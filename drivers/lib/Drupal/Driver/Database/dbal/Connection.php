<?php

namespace Drupal\Driver\Database\dbal;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Database\Connection as DatabaseConnection;
use Drupal\Core\Database\ConnectionNotDefinedException;
use Drupal\Core\Database\Database;
use Drupal\Core\Database\DatabaseException;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Database\DatabaseNotFoundException;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Database\TransactionCommitFailedException;

use Drupal\Driver\Database\dbal\DbalExtension\PDOMySql;
use Drupal\Driver\Database\dbal\Statement\PDODbalStatement;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\ConnectionException as DbalConnectionException;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\DriverManager as DbalDriverManager;
use Doctrine\DBAL\Version as DbalVersion;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\UriInterface;

/**
 * DruDbal implementation of \Drupal\Core\Database\Connection.
 *
 * Note: there should not be db platform specific code here. Any tasks that
 * cannot be managed by Doctrine DBAL should be added to extension specific
 * code in Drupal\Driver\Database\dbal\DbalExtension\[dbal_driver_name]
 * classes and execution handed over to there.
 */
class Connection extends DatabaseConnection {

  /**
   * List of supported drivers and their mapping to the DBAL extension
   * and the statement classes to use.
   *
   * @var array[]
   */
  protected static $dbalClassMap = array(
    'pdo_mysql' => [PDOMySql::class, PDODbalStatement::class],
  );

  /**
   * List of URL schemes from a database URL and their mappings to driver.
   *
   * @var string[]
   */
  protected static $driverSchemeAliases = array(
    'mysql' => 'pdo_mysql',
    'mysql2' => 'pdo_mysql',
  );

  /**
   * The DruDbal extension for the DBAL driver.
   *
   * @var \Drupal\Driver\Database\dbal\DbalExtension\DbalExtensionInterface
   */
  protected $dbalExtension;

  /**
   * An array of options to be passed to the Statement object.
   *
   * DBAL is quite strict in the sense that it does not pass options to the
   * prepare/execute methods. Overcome that by storing here options required,
   * so that the custom Statement classes defined by the driver can manage that
   * on construction.
   *
   * @var array[]
   */
  protected $statementOptions;

  /**
   * Constructs a Connection object.
   */
  public function __construct(DbalConnection $dbal_connection, array $connection_options = []) {
    $dbal_extension_class = static::getDbalExtensionClass($connection_options);
    $this->statementClass = static::getStatementClass($connection_options);
    $this->dbalExtension = new $dbal_extension_class($this, $dbal_connection, $this->statementClass);
    $this->transactionSupport = $this->dbalExtension->transactionSupport($connection_options);
    $this->transactionalDDLSupport = $this->dbalExtension->transactionalDDLSupport($connection_options);
    $this->setPrefix(isset($connection_options['prefix']) ? $connection_options['prefix'] : '');
    $this->connectionOptions = $connection_options;
    // Unset $this->connection so that __get() can return the wrapped
    // DbalConnection on the extension instead.
    unset($this->connection);
  }

  /**
   * Implements the magic __get() method.
   */
  public function __get($name) {
    // Calls to $this->connection return the wrapped DbalConnection on the
    // extension instead.
    if ($name === 'connection') {
      return $this->getDbalConnection();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function destroy() {
    $this->dbalExtension->destroy();
    $this->schema = NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function clientVersion() {
    return $this->dbalExtension->clientVersion();
  }

  /**
   * Returns a prefixed table name.
   *
   * @param string $table_name
   *   A Drupal table name
   *
   * @return string
   *   A fully prefixed table name, suitable for direct usage in db queries.
   */
  public function getPrefixedTableName($table_name) {
    return $this->prefixTables('{' . $table_name . '}');
  }

  /**
   * {@inheritdoc}
   */
  public function query($query, array $args = [], $options = []) {
    // Use default values if not already set.
    $options += $this->defaultOptions();

    try {
      // We allow either a pre-bound statement object or a literal string.
      // In either case, we want to end up with an executed statement object,
      // which we pass to Statement::execute.
      if ($query instanceof StatementInterface) {
        $stmt = $query;
        $stmt->execute(NULL, $options);
      }
      else {
        $this->expandArguments($query, $args);
        // To protect against SQL injection, Drupal only supports executing one
        // statement at a time.  Thus, the presence of a SQL delimiter (the
        // semicolon) is not allowed unless the option is set.  Allowing
        // semicolons should only be needed for special cases like defining a
        // function or stored procedure in SQL. Trim any trailing delimiter to
        // minimize false positives.
        $query = rtrim($query, ";  \t\n\r\0\x0B");
        if (strpos($query, ';') !== FALSE && empty($options['allow_delimiter_in_query'])) {
          throw new \InvalidArgumentException('; is not supported in SQL strings. Use only one statement at a time.');
        }
        $stmt = $this->prepareQueryWithParams($query, $args);
        $stmt->execute($args, $options);
      }

      // Depending on the type of query we may need to return a different value.
      // See DatabaseConnection::defaultOptions() for a description of each
      // value.
      switch ($options['return']) {
        case Database::RETURN_STATEMENT:
          return $stmt;
        case Database::RETURN_AFFECTED:
          $stmt->allowRowCount = TRUE;
          return $stmt->rowCount();
        case Database::RETURN_INSERT_ID:
          $sequence_name = isset($options['sequence_name']) ? $options['sequence_name'] : NULL;
          return $this->connection->lastInsertId($sequence_name);
        case Database::RETURN_NULL:
          return NULL;
        default:
          throw new DBALException('Invalid return directive: ' . $options['return']);
      }
    }
    catch (\InvalidArgumentException $e) {
      throw $e;
    }
    catch (\Exception $e) {
      return $this->dbalExtension->handleQueryException($e, $query, $args, $options);
    }
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
        throw new ConnectionNotDefinedException(t('Database connection is not defined properly for the \'dbal\' driver. The \'dbal_url\' key is missing. Check the database connection definition in settings.php.'));
      }
      $dbal_connection = DbalDriverManager::getConnection([
        'url' => $connection_options['dbal_url'],
      ]);
      // Below shouldn't happen, but if it does, then use the driver name
      // from the just established DBAL connection.
      $connection_options['dbal_driver'] = $dbal_connection->getDriver()->getName();
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
   * @todo
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
      'dbal_statement_class' => NULL,
// @todo advanced_options are written to settings.php - still??
      'advanced_options' => NULL,
    ]);
    // Map to DBAL connection array the main keys from the Drupal connection.
    if (isset($connection_options['database'])) {
      $options['dbname'] = $connection_options['database'];
    }
    if (isset($connection_options['username'])) {
      $options['user'] = $connection_options['username'];
    }
    if (isset($connection_options['password'])) {
      $options['password'] = $connection_options['password'];
    }
    if (isset($connection_options['host'])) {
      $options['host'] = $connection_options['host'];
    }
    if (isset($connection_options['port'])) {
      $options['port'] =  $connection_options['port'];
    }
    if (isset($connection_options['dbal_url'])) {
      $options['url'] =  $connection_options['dbal_url'];
    }
    if (isset($connection_options['dbal_driver'])) {
      $options['driver'] = $connection_options['dbal_driver'];
    }
    // If there is a 'pdo' key in Drupal, that needs to be mapped to the
    // 'driverOptions' key in DBAL.
    $options['driverOptions'] = isset($connection_options['pdo']) ? $connection_options['pdo'] : [];
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
    try {
      return $this->dbalExtension->queryRange($query, $from, $count, $args, $options);
    }
    catch (DBALException $e) {
      throw new \Exception($e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function queryTemporary($query, array $args = [], array $options = []) {
    try {
      $tablename = $this->generateTemporaryTableName();
      $this->dbalExtension->queryTemporary($tablename, $query, $args, $options);
      return $tablename;
    }
    catch (DBALException $e) {
      throw new \Exception($e->getMessage());
    }
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
    return DbalVersion::VERSION;
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
    catch (DBALException $e) {
      throw new DatabaseNotFoundException($e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function mapConditionOperator($operator) {
    // We don't want to override any of the defaults.
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function nextId($existing_id = 0) {
    return $this->dbalExtension->nextId($existing_id);
  }

  /**
   * Prepares a query string and returns the prepared statement.
   *
   * This method caches prepared statements, reusing them when possible. It also
   * prefixes tables names enclosed in curly-braces.
   * Emulated prepared statements does not communicate with the database server
   * so this method does not check the statement.
   *
   * @param string $query
   *   The query string as SQL, with curly-braces surrounding the
   *   table names.
   * @param array $args
   *   An array of arguments for the prepared statement. If the prepared
   *   statement uses ? placeholders, this array must be an indexed array.
   *   If it contains named placeholders, it must be an associative array.
   * @param array $driver_options
   *   (optional) This array holds one or more key=>value pairs to set
   *   attribute values for the Statement object that this method returns.
   *
   * @return \Drupal\Core\Database\StatementInterface|false
   *   If the database server successfully prepares the statement, returns a
   *   StatementInterface object.
   *   If the database server cannot successfully prepare the statement  returns
   *   FALSE or emits an Exception (depending on error handling).
   */
  public function prepareQueryWithParams($query, array $args = [], array $driver_options = []) {
    $query = $this->prefixTables($query);
    return $this->dbalExtension->prepare($query, $args, $driver_options);
  }

  /**
   * {@inheritdoc}
   */
  public function prepareQuery($query) {
    // Should not be used, because it fails to execute properly in case the
    // driver is not able to process named placeholders. Use
    // ::prepareQueryWithParams instead.
    // @todo raise an exception and fail hard??
    return $this->prepareQueryWithParams($query);
  }

  /**
   * {@inheritdoc}
   */
  public function prepare($statement, array $driver_options = []) {
    // Should not be used, because it fails to execute properly in case the
    // driver is not able to process named placeholders. Use
    // ::prepareQueryWithParams instead.
    // @todo raise an exception and fail hard??
    return $this->dbalExtension->prepare($statement, [], $driver_options);
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
        try {
          $this->getDbalConnection()->commit();
        }
        catch (DbalConnectionException $e) {
          throw new TransactionCommitFailedException();
        }
      }
      else {
        // Attempt to release this savepoint in the standard way.
        if ($this->dbalExtension->releaseSavepoint($name) === 'all') {
          $this->transactionLayers = [];
        }
      }
    }
  }

  /**
   * Gets the wrapped DBAL connection.
   *
   * @return string
   *   The DBAL connection wrapped by the extension object.
   */
  public function getDbalConnection() {
    return $this->dbalExtension->getDbalConnection();
  }

  /**
   * Gets the DBAL extension.
   *
   * @return \Drupal\Driver\Database\dbal\DbalExtension\DbalExtensionInterface
   *   The DBAL extension for this connection.
   */
  public function getDbalExtension() {
    return $this->dbalExtension;
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
    return static::$dbalClassMap[$driver_name][0];
  }

  /**
   * Gets the Statement class to use for this connection.
   *
   * @param array $connection_options
   *   An array of options for the connection.
   *
   * @return string
   *   The Statement class.
   */
  public static function getStatementClass(array $connection_options) {
    if (isset($connection_options['dbal_statement_class'])) {
      return $connection_options['dbal_statement_class'];
    }
    $driver_name = $connection_options['dbal_driver'];
    if (isset(static::$driverSchemeAliases[$driver_name])) {
      $driver_name = static::$driverSchemeAliases[$driver_name];
    }
    return static::$dbalClassMap[$driver_name][1];
  }

  /**
   * Gets the database server version.
   *
   * @return string
   *   The database server version string.
   */
  public function getDbServerVersion() {
    return $this->getDbalConnection()->getWrappedConnection()->getServerVersion();
  }

  /**
   * {@inheritdoc}
   */
  public static function getConnectionInfoAsUrlHelper(array $connection_options, UriInterface $uri) {
    $uri = parent::getConnectionInfoAsUrlHelper($connection_options, $uri);
    // Add the 'dbal_driver' key to the URI.
    if (!empty($connection_options['dbal_driver'])) {
      $uri = Uri::withQueryValue($uri, 'dbal_driver', $connection_options['dbal_driver']);
    }
    return $uri;
  }

  /**
   * {@inheritdoc}
   */
  public static function convertDbUrlToConnectionInfoHelper(UriInterface $uri, $root, array $connection_options) {
    $connection_options = parent::convertDbUrlToConnectionInfoHelper($uri, $root, $connection_options);
    // Add the 'dbal_driver' key to the connection options.
    $parts = [];
    parse_str($uri->getQuery(), $parts);
    $dbal_driver = isset($parts['dbal_driver']) ? $parts['dbal_driver'] : '';
    $connection_options['dbal_driver'] = $dbal_driver;
    return $connection_options;
  }

  /**
   * Pushes an option to be retrieved by the Statement object.
   *
   * @param string $option
   *   The option identifier.
   * @param string $value
   *   The option value.
   *
   * @return $this
   */
  public function pushStatementOption($option, $value) {
    if (!isset($this->statementOptions[$option])) {
      $this->statementOptions[$option] = [];
    }
    $this->statementOptions[$option][] = $value;
    return $this;
  }

  /**
   * Pops an option retrieved by the Statement object.
   *
   * @param string $option
   *   The option identifier.
   *
   * @return mixed|null
   *   The option value, or NULL if missing.
   */
  public function popStatementOption($option) {
    if (!isset($this->statementOptions[$option]) || empty($this->statementOptions[$option])) {
      return NULL;
    }
    return array_pop($this->statementOptions[$option]);
  }

}
