<?php

namespace Drupal\drudbal\Driver\Database\dbal;

use Composer\InstalledVersions;
use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\ConnectionException as DbalConnectionException;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\DriverManager as DbalDriverManager;
use Doctrine\DBAL\Exception\DriverException as DbalDriverException;
use Doctrine\DBAL\ExpandArrayParameters;
use Doctrine\DBAL\Platforms\AbstractPlatform as DbalAbstractPlatform;
use Doctrine\DBAL\SQL\Parser;
use Drupal\Component\Uuid\Php as Uuid;
use Drupal\Core\Database\Connection as DatabaseConnection;
use Drupal\Core\Database\ConnectionNotDefinedException;
use Drupal\Core\Database\Database;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Database\DatabaseNotFoundException;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Database\TransactionCommitFailedException;
use Drupal\Core\Database\TransactionNameNonUniqueException;
use Drupal\Core\Database\TransactionNoActiveException;
use Drupal\Core\Database\TransactionOutOfOrderException;
use Drupal\drudbal\Driver\Database\dbal\DbalExtension\MysqliExtension;
use Drupal\drudbal\Driver\Database\dbal\DbalExtension\Oci8Extension;
use Drupal\drudbal\Driver\Database\dbal\DbalExtension\PDOMySqlExtension;
use Drupal\drudbal\Driver\Database\dbal\DbalExtension\PDOSqliteExtension;
use GuzzleHttp\Psr7\Uri;

/**
 * DruDbal implementation of \Drupal\Core\Database\Connection.
 *
 * Note: there should not be db platform specific code here. Any tasks that
 * cannot be managed by Doctrine DBAL should be added to extension specific
 * code in Drupal\drudbal\Driver\Database\dbal\DbalExtension\[dbal_driver_name]
 * classes and execution handed over to there.
 */
class Connection extends DatabaseConnection {

  /**
   * Supported DBAL drivers and DBAL extension classes to use.
   *
   * @var string[]
   */
  protected static $dbalClassMap = [
    'mysqli' => MysqliExtension::class,
    'oci8' => Oci8Extension::class,
    'pdo_mysql' => PDOMySqlExtension::class,
    'pdo_sqlite' => PDOSqliteExtension::class,
  ];

  /**
   * Map of database tables.
   *
   * Drupal SQL statements wrap table names in curly brackets. This array
   * maps this syntax to actual database tables, adding prefix and/or
   * resolving platform specific constraints.
   *
   * @var string[]
   */
  protected $dbTables = [];

  /**
   * List of URL schemes from a database URL and their mappings to driver.
   *
   * @var string[]
   */
  protected static $driverSchemeAliases = [
    'mysql' => 'pdo_mysql',
    'mysql2' => 'pdo_mysql',
    'sqlite' => 'pdo_sqlite',
    'sqlite3' => 'pdo_sqlite',
  ];

  /**
   * The DruDbal extension for the DBAL driver.
   *
   * @var \Drupal\drudbal\Driver\Database\dbal\DbalExtension\DbalExtensionInterface
   */
  protected $dbalExtension;

  /**
   * Current connection DBAL platform.
   *
   * @var \Doctrine\DBAL\Platforms\AbstractPlatform
   */
  protected $dbalPlatform;

  /**
   * The platform SQL parser.
   *
   * @var \Doctrine\DBAL\SQL\Parser|null
   */
  protected $parser;

  /**
   * Constructs a Connection object.
   */
  public function __construct(DbalConnection $dbal_connection, array $connection_options = []) {
    // The 'transactions' option is deprecated.
    if (isset($connection_options['transactions'])) {
      @trigger_error('Passing a \'transactions\' connection option to Drupal\\Core\\Database\\Connection::__construct is deprecated in drupal:9.1.0 and is removed in drupal:10.0.0. All database drivers must support transactions. See https://www.drupal.org/node/2278745', E_USER_DEPRECATED);
      unset($connection_options['transactions']);
    }

    $this->connection = $dbal_connection;
    $this->connectionOptions = $connection_options;
    $this->setPrefix($connection_options['prefix'] ?? '');
    $this->dbalPlatform = $dbal_connection->getDatabasePlatform();
    $dbal_extension_class = static::getDbalExtensionClass($connection_options);
    $this->dbalExtension = new $dbal_extension_class($this);
    $this->statementWrapperClass = $this->dbalExtension->getStatementClass();
    $this->transactionalDDLSupport = $this->dbalExtension->delegateTransactionalDdlSupport($connection_options);

    $quote_identifier = $this->dbalPlatform->getIdentifierQuoteCharacter();
    $this->identifierQuotes = [$quote_identifier, $quote_identifier];
  }

  /**
   * Destructs a Connection object.
   */
  public function __destruct() {
    $this->schema = NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function destroy() {
    @trigger_error(__METHOD__ . '() is deprecated in drupal:9.1.0 and is removed from drupal:10.0.0. Move custom database destruction logic to __destruct(). See https://www.drupal.org/node/3142866', E_USER_DEPRECATED);
    return;
  }

  /**
   * {@inheritdoc}
   */
  public function clientVersion() {
    return $this->dbalExtension->delegateClientVersion();
  }

  /**
   * {@inheritdoc}
   */
  public function quoteIdentifiers($sql) {
    preg_match_all('/(\[(.+?)\])/', $sql, $matches);
    $ids = [];
    $i = 0;
    foreach($matches[1] as $m) {
      $ids[$m] = $this->getDbalExtension()->getDbFieldName($matches[2][$i], TRUE);
      $i++;
    }
    return strtr($sql, $ids);
  }

  /**
   * {@inheritdoc}
   */
  public function prefixTables($sql) {
    $matches = [];
    preg_match_all('/{(\S*)}/', $sql, $matches, PREG_SET_ORDER, 0);
    foreach ($matches as $match) {
      $table = $match[1];
      if (isset($this->dbTables['{' . $table . '}'])) {
        continue;
      }
      $prefix = $this->prefixes[$table] ?? $this->prefixes['default'];
      $this->dbTables['{' . $table . '}'] = $this->identifierQuotes[0] . $this->dbalExtension->getDbTableName($prefix, $table) . $this->identifierQuotes[1];
    }
    return str_replace(array_keys($this->dbTables), array_values($this->dbTables), $sql);
  }

  /**
   * Returns a prefixed table name.
   *
   * @param string $table_name
   *   A Drupal table name.
   * @param bool $quoted
   *   (Optional) If TRUE, the returned table name is wrapped into identifier
   *   quotes.
   *
   * @return string
   *   A fully prefixed table name, suitable for direct usage in db queries.
   */
  public function getPrefixedTableName(string $table_name, bool $quoted = FALSE): string {
    // If the table name is enclosed in curly braces, remove them first.
    $matches = [];
    if (preg_match('/^{(\S*)}/', $table_name, $matches) === 1) {
      $table_name = $matches[1];
    }

    $prefixed_table_name = $this->prefixTables('{' . $table_name . '}');
    // @todo use substr  instead
    return $quoted ? $prefixed_table_name : str_replace($this->identifierQuotes, ['', ''], $prefixed_table_name);
  }

  /**
   * {@inheritdoc}
   */
  public function query($query, array $args = [], $options = []) {
    // Use default values if not already set.
    $options += $this->defaultOptions();

    // We allow either a pre-bound statement object (deprecated) or a literal
    // string. In either case, we want to end up with an executed statement
    // object, which we pass to StatementInterface::execute.
    if ($query instanceof StatementInterface) {
      @trigger_error('Passing a StatementInterface object as a $query argument to Drupal\Core\Database\Connection::query is deprecated in drupal:9.2.0 and is removed in drupal:10.0.0. Call the execute method from the StatementInterface object directly instead. See https://www.drupal.org/node/3154439', E_USER_DEPRECATED);
      $stmt = $query;
    }
    else {
      $this->expandArguments($query, $args);
      $stmt = $this->prepareStatement($query, $options);
    }

    try {
      if ($query instanceof StatementInterface) {
        $stmt->execute(NULL, $options);
      }
      else {
        $stmt->execute($args, $options);
      }

      // Depending on the type of query we may need to return a different value.
      // See DatabaseConnection::defaultOptions() for a description of each
      // value.
      switch ($options['return'] ?? Database::RETURN_STATEMENT) {
        case Database::RETURN_STATEMENT:
          return $stmt;

        case Database::RETURN_AFFECTED:
          $stmt->allowRowCount = TRUE;
          return $stmt->rowCount();

        case Database::RETURN_INSERT_ID:
          try {
            $sequence_name = $options['sequence_name'] ?? NULL;
            return (string) $this->getDbalConnection()->lastInsertId($sequence_name);
          }
          catch (\Exception $e) {
            return '0';
          }

        case Database::RETURN_NULL:
          return NULL;

        default:
          throw new DbalException('Invalid return directive: ' . $options['return']);

      }
    }
    catch (\Exception $e) {
      return $this->exceptionHandler()->handleExecutionException($e, $stmt, $args, $options);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function exceptionHandler() {
    return new ExceptionHandler($this);
  }

  /**
   * {@inheritdoc}
   */
  public static function open(array &$connection_options = []) {
    if (empty($connection_options['dbal_driver'])) {
      // If 'dbal_driver' is missing from the connection options, then we are
      // likely in an installation scenario where the database URL is invalid.
      // Try establishing a DBAL connection to clarify details.
      if (empty($connection_options['dbal_url'])) {
        // If 'dbal_url' is also missing, then we are in a very very wrong
        // situation, as DBAL would not be able to determine the driver it
        // needs to use.
        throw new ConnectionNotDefinedException("Database connection is not defined properly for the 'dbal' driver. The 'dbal_url' key is missing. Check the database connection definition in settings.php.");
      }
      $dbal_connection = DbalDriverManager::getConnection([
        'url' => $connection_options['dbal_url'],
      ]);
      // Below shouldn't happen, but if it does, then use the driver name
      // from the just established DBAL connection.
      $uri = new Uri($connection_options['dbal_url']);
      $connection_options['dbal_driver'] = $uri->getScheme();
    }

    $dbal_extension_class = static::getDbalExtensionClass($connection_options);
    try {
      $dbal_connection_options = static::mapConnectionOptionsToDbal($connection_options);
      $dbal_extension_class::preConnectionOpen($connection_options, $dbal_connection_options);
      $dbal_connection = DBALDriverManager::getConnection($dbal_connection_options);
      $dbal_extension_class::postConnectionOpen($dbal_connection, $connection_options, $dbal_connection_options);
    }
    catch (DbalConnectionException $e) {
      throw new DatabaseExceptionWrapper($e->getMessage(), $e->getCode(), $e);
    }
    return $dbal_connection;
  }

  /**
   * Create an array of DBAL connection options from the Drupal options.
   *
   * @param array $connection_options
   *   An array of DRUPAL options for the connection. May include the
   *   following:
   *   - prefix
   *   - namespace
   *   - Other driver-specific options.
   *
   * @return array
   *   An array of options suitable to establish a DBAL connection.
   */
  public static function mapConnectionOptionsToDbal(array $connection_options) {
    // Take away from the Drupal connection array the keys that will be
    // managed separately.
    $options = array_diff_key($connection_options, [
      'namespace' => NULL,
      'driver' => NULL,
      'prefix' => NULL,

      'database' => NULL,
      'username' => NULL,
      'password' => NULL,
      'host' => NULL,
      'port' => NULL,

      'pdo' => NULL,

      'dbal_url' => NULL,
      'dbal_driver' => NULL,
      'dbal_options' => NULL,
      'dbal_extension_class' => NULL,
    ]);
    // Map to DBAL connection array the main keys from the Drupal connection.
    if (!empty($connection_options['database'])) {
      $options['dbname'] = $connection_options['database'];
    }
    if (!empty($connection_options['username'])) {
      $options['user'] = $connection_options['username'];
    }
    if (!empty($connection_options['password'])) {
      $options['password'] = $connection_options['password'];
    }
    if (!empty($connection_options['host'])) {
      $options['host'] = $connection_options['host'];
    }
    if (!empty($connection_options['port'])) {
      $options['port'] = $connection_options['port'];
    }
    if (!empty($connection_options['dbal_url'])) {
      $options['url'] = $connection_options['dbal_url'];
    }
    if (!empty($connection_options['dbal_driver'])) {
      $options['driver'] = $connection_options['dbal_driver'];
    }
    // If there is a 'pdo' key in Drupal, that needs to be mapped to the
    // 'driverOptions' key in DBAL.
    $options['driverOptions'] = $connection_options['pdo'] ?? [];
    // If there is a 'dbal_options' key in Drupal, merge it with the array
    // built so far. The content of the 'dbal_options' key will override
    // overlapping keys built so far.
    if (isset($connection_options['dbal_options'])) {
      $options = array_merge($options, $connection_options['dbal_options']);
    }

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function queryRange($query, $from, $count, array $args = [], array $options = []) {
    return $this->dbalExtension->delegateQueryRange($query, $from, $count, $args, $options);
  }

  /**
   * {@inheritdoc}
   */
  public function queryTemporary($query, array $args = [], array $options = []) {
    @trigger_error('Connection::queryTemporary() is deprecated in drupal:9.3.0 and is removed from drupal:10.0.0. There is no replacement. See https://www.drupal.org/node/3211781', E_USER_DEPRECATED);
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function driver() {
    return 'dbal';
  }

  /**
   * {@inheritdoc}
   */
  public function databaseType() {
    return $this->getDbalConnection()->getDriver()->getDatabasePlatform()->getName();
  }

  /**
   * {@inheritdoc}
   */
  public function version() {
    // Return the DBAL version.
    return InstalledVersions::getPrettyVersion('doctrine/dbal');
  }

  /**
   * {@inheritdoc}
   */
  public function createDatabase($database) {
    try {
      $this->dbalExtension->preCreateDatabase($database);
      $this->getDbalConnection()->getSchemaManager()->createDatabase($database);
      $this->dbalExtension->postCreateDatabase($database);
    }
    catch (DbalException $e) {
      throw new DatabaseNotFoundException($e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function mapConditionOperator($operator) {
    return $this->dbalExtension->delegateMapConditionOperator($operator);
  }

  /**
   * {@inheritdoc}
   */
  public function nextId($existing_id = 0) {
    $id = is_numeric($existing_id ?? 0) ? ($existing_id ?? 0) : 0;
    return $this->dbalExtension->delegateNextId($id);
  }

  /**
   * {@inheritdoc}
   */
  public function escapeField($field) {
    return $this->getDbalExtension()->getDbFieldName($field, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function escapeAlias($field) {
    return $this->getDbalExtension()->getDbAlias($field, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function rollBack($savepoint_name = 'drupal_transaction') {
    if (!$this->inTransaction()) {
      throw new TransactionNoActiveException();
    }
    // A previous rollback to an earlier savepoint may mean that the savepoint
    // in question has already been accidentally committed.
    if (!isset($this->transactionLayers[$savepoint_name])) {
      throw new TransactionNoActiveException();
    }

    // We need to find the point we're rolling back to, all other savepoints
    // before are no longer needed. If we rolled back other active savepoints,
    // we need to throw an exception.
    $rolled_back_other_active_savepoints = FALSE;
    while ($savepoint = array_pop($this->transactionLayers)) {
      if ($savepoint == $savepoint_name) {
        // If it is the last the transaction in the stack, then it is not a
        // savepoint, it is the transaction itself so we will need to roll back
        // the transaction rather than a savepoint.
        if (empty($this->transactionLayers)) {
          break;
        }
        $this->getDbalConnection()->exec($this->dbalPlatform->rollbackSavePoint($savepoint));
        $this->popCommittableTransactions();
        if ($rolled_back_other_active_savepoints) {
          throw new TransactionOutOfOrderException();
        }
        return;
      }
      else {
        $rolled_back_other_active_savepoints = TRUE;
      }
    }

    // Notify the callbacks about the rollback.
    $callbacks = $this->rootTransactionEndCallbacks;
    $this->rootTransactionEndCallbacks = [];
    foreach ($callbacks as $callback) {
      call_user_func($callback, FALSE);
    }

    $this->getDbalConnection()->rollBack();
    if ($rolled_back_other_active_savepoints) {
      throw new TransactionOutOfOrderException();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function pushTransaction($name) {
    if (isset($this->transactionLayers[$name])) {
      throw new TransactionNameNonUniqueException($name . " is already in use.");
    }
    // If we're already in a transaction then we want to create a savepoint
    // rather than try to create another transaction.
    if ($this->inTransaction()) {
      $this->getDbalConnection()->exec($this->dbalPlatform->createSavePoint($name));
    }
    else {
      $this->getDbalConnection()->beginTransaction();
    }
    $this->transactionLayers[$name] = $name;
  }

  /**
   * {@inheritdoc}
   */
  protected function popCommittableTransactions() {
    // Commit all the committable layers.
    foreach (array_reverse($this->transactionLayers) as $name => $active) {
      // Stop once we found an active transaction.
      if ($active) {
        break;
      }

      // If there are no more layers left then we should commit.
      unset($this->transactionLayers[$name]);
      if (empty($this->transactionLayers)) {
        $this->doCommit();
      }
      else {
        // Attempt to release this savepoint in the standard way.
        try {
          $this->getDbalConnection()->exec($this->dbalPlatform->releaseSavePoint($name));
        }
        catch (DbalDriverException $e) {
          // If all SAVEPOINTs were released automatically, clean the
          // transaction stack.
          if ($this->dbalExtension->delegateReleaseSavepointExceptionProcess($e) === 'all') {
            $this->transactionLayers = [];
          };
        }
      }
    }
  }

  /**
   * Do the actual commit, invoke post-commit callbacks.
   *
   * @internal
   */
  protected function doCommit() {
    try {
      $this->getDbalConnection()->commit();
      $success = TRUE;
    }
    catch (DbalConnectionException $e) {
      $success = FALSE;
    }

    if (!empty($this->rootTransactionEndCallbacks)) {
      $callbacks = $this->rootTransactionEndCallbacks;
      $this->rootTransactionEndCallbacks = [];
      foreach ($callbacks as $callback) {
        call_user_func($callback, $success);
      }
    }

    if (!$success) {
      throw new TransactionCommitFailedException();
    }
  }

  /**
   * Gets the wrapped DBAL connection.
   *
   * @return \Doctrine\DBAL\Connection
   *   The DBAL connection wrapped by the extension object.
   */
  public function getDbalConnection(): DbalConnection {
    return $this->connection;
  }

  /**
   * Gets the DBAL extension.
   *
   * @return \Drupal\drudbal\Driver\Database\dbal\DbalExtension\DbalExtensionInterface
   *   The DBAL extension for this connection.
   */
  public function getDbalExtension() {
    return $this->dbalExtension;
  }

  /**
   * Gets the DBAL platform.
   *
   * @return \Doctrine\DBAL\Platforms\AbstractPlatform
   *   The DBAL platform for this connection.
   */
  public function getDbalPlatform(): DbalAbstractPlatform {
    return $this->dbalPlatform;
  }

  /**
   * Gets the DBAL extension class to use for the DBAL driver.
   *
   * @param array $connection_options
   *   An array of options for the connection.
   *
   * @return string
   *   The DBAL extension class.
   */
  public static function getDbalExtensionClass(array $connection_options) {
    if (isset($connection_options['dbal_extension_class'])) {
      return $connection_options['dbal_extension_class'];
    }
    $driver_name = $connection_options['dbal_driver'];
    if (isset(static::$driverSchemeAliases[$driver_name])) {
      $driver_name = static::$driverSchemeAliases[$driver_name];
    }
    return static::$dbalClassMap[$driver_name];
  }

  /**
   * {@inheritdoc}
   */
  public static function createUrlFromConnectionOptions(array $connection_options) {
    $uri = new Uri();

    // Driver name as the URI scheme.
    $uri = $uri->withScheme($connection_options['driver']);

    // User credentials if existing.
    if (isset($connection_options['username'])) {
      $uri = $uri->withUserInfo($connection_options['username'], $connection_options['password'] ?? NULL);
    }

    $uri = $uri->withHost($connection_options['host'] ?? 'localhost');

    if (!empty($connection_options['port'])) {
      $uri = $uri->withPort($connection_options['port']);
    }

    $uri = $uri->withPath('/' . $connection_options['database']);

    // Add the 'module' key to the URI.
    $uri = Uri::withQueryValue($uri, 'module', 'drudbal');

    // Add the 'dbal_driver' key to the URI.
    if (!empty($connection_options['dbal_driver'])) {
      $uri = Uri::withQueryValue($uri, 'dbal_driver', $connection_options['dbal_driver']);
    }

    // Table prefix as the URI fragment.
    if (!empty($connection_options['prefix']['default'])) {
      $uri = $uri->withFragment($connection_options['prefix']['default']);
    }

    return (string) $uri;
  }

  /**
   * {@inheritdoc}
   */
  public static function createConnectionOptionsFromUrl($url, $root) {
    $uri = new Uri($url);
    if (empty($uri->getHost()) || empty($uri->getScheme()) || empty($uri->getPath())) {
      throw new \InvalidArgumentException('Minimum requirement: driver://host/database');
    }

    // Use reflection to get the namespace of the class being called.
    $reflector = new \ReflectionClass(get_called_class());

    // Build the connection information array.
    $connection_options = [
      'driver' => $uri->getScheme(),
      'host' => $uri->getHost(),
      // Strip the first leading slash of the path to get the database name.
      // Note that additional leading slashes have meaning for some database
      // drivers.
      'database' => substr($uri->getPath(), 1),
      'prefix' => $uri->getFragment() ?: NULL,
      'namespace' => $reflector->getNamespaceName(),
    ];

    $port = $uri->getPort();
    if (!empty($port)) {
      $connection_options['port'] = $port;
    }

    $user_info = $uri->getUserInfo();
    if (!empty($user_info)) {
      $user_info_elements = explode(':', $user_info, 2);
      $connection_options['username'] = $user_info_elements[0];
      $connection_options['password'] = $user_info_elements[1] ?? '';
    }

    // Add the 'dbal_driver' key to the connection options.
    $parts = [];
    parse_str($uri->getQuery(), $parts);
    $dbal_driver = $parts['dbal_driver'] ?? '';
    $connection_options['dbal_driver'] = $dbal_driver;

    return $connection_options;
  }

  /**
   * {@inheritdoc}
   */
  public function getFullQualifiedTableName($table) {
    return $this->getDbalExtension()->getDbFullQualifiedTableName($table);
  }

  /**
   * Returns the table prefixes array.
   *
   * @return array
   *   The connection options array.
   */
  public function getPrefixes() {
    return $this->prefixes;
  }

  /**
   * Set the list of prefixes used by this database connection.
   *
   * @param array|string $prefix
   *   Either a single prefix, or an array of prefixes, in any of the multiple
   *   forms documented in default.settings.php.
   */
  public function setPrefixPublic($prefix) {
    return $this->setPrefix($prefix);
  }

  /**
   * @param array<int, mixed>|array<string, mixed>                               $params
   * @param array<int, int|string|Type|null>|array<string, int|string|Type|null> $types
   *
   * @return array{string, list<mixed>, array<int,Type|int|string|null>}
   */
  public function expandArrayParameters(string $sql, array $params, array $types): array {
    if ($this->parser === null) {
      $this->parser = $this->getDbalConnection()->getDatabasePlatform()->createSQLParser();
    }

    $pms = [];
    foreach($params as $k => $v) {
      $pms[substr($k, 1)] = $v;
    }

    $visitor = new ExpandArrayParameters($pms, $types);

    $this->parser->parse($sql, $visitor);

    return [
      $visitor->getSQL(),
      $visitor->getParameters(),
      $visitor->getTypes(),
    ];
  }
}
