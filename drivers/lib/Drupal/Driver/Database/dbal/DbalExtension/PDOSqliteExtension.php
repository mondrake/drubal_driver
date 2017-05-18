<?php

namespace Drupal\Driver\Database\dbal\DbalExtension;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Database\Database;
use Drupal\Core\Database\DatabaseException;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Database\DatabaseNotFoundException;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Database\SchemaException;
use Drupal\Core\Database\TransactionCommitFailedException;
use Drupal\Core\Database\Driver\sqlite\Connection as SqliteConnectionBase;
use Drupal\Driver\Database\dbal\Connection as DruDbalConnection;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\ConnectionException as DbalConnectionException;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Exception\DriverException as DbalDriverException;
use Doctrine\DBAL\Exception\ConnectionException as DbalExceptionConnectionException;
use Doctrine\DBAL\Schema\Schema as DbalSchema;
use Doctrine\DBAL\Schema\Table as DbalTable;
use Doctrine\DBAL\Version as DbalVersion;

/**
 * Driver specific methods for pdo_sqlite.
 */
class PDOSqliteExtension implements DbalExtensionInterface {

  /**
   * Minimum required Sqlite version.
   */
  const SQLITE_MINIMUM_VERSION = '3.7.11';

  /**
   * Error code for "Unable to open database file" error.
   */
  const DATABASE_NOT_FOUND = 14;

  /**
   * Whether or not the active transaction (if any) will be rolled back.
   *
   * @var bool
   */
  protected $willRollback;

  /**
   * A map of condition operators to SQLite operators.
   *
   * We don't want to override any of the defaults.
   */
  protected static $sqliteConditionOperatorMap = [
    'LIKE' => ['postfix' => " ESCAPE '\\'"],
    'NOT LIKE' => ['postfix' => " ESCAPE '\\'"],
    'LIKE BINARY' => ['postfix' => " ESCAPE '\\'", 'operator' => 'GLOB'],
    'NOT LIKE BINARY' => ['postfix' => " ESCAPE '\\'", 'operator' => 'NOT GLOB'],
  ];

  /**
   * All databases attached to the current database. This is used to allow
   * prefixes to be safely handled without locking the table.
   *
   * @var array
   */
  protected $attachedDatabases = [];

  /**
   * Whether or not a table has been dropped this request: the destructor will
   * only try to get rid of unnecessary databases if there is potential of them
   * being empty.
   *
   * This variable is set to public because Schema needs to
   * access it. However, it should not be manually set.
   *
   * @var bool
   */
  public $tableDropped = FALSE;

  /**
   * Replacement for single quote identifiers.
   *
   * @todo DBAL uses single quotes instead of backticks to produce DDL
   * statements. This causes problems if fields defaults or comments have
   * single quotes inside.
   */
  const SINGLE_QUOTE_IDENTIFIER_REPLACEMENT = ']]]]SINGLEQUOTEIDENTIFIERDRUDBAL[[[[';

  /**
   * The DruDbal connection.
   *
   * @var \Drupal\Driver\Database\dbal\Connection
   */
  protected $connection;

  /**
   * The actual DBAL connection.
   *
   * @var \Doctrine\DBAL\Connection
   */
  protected $dbalConnection;

  /**
   * The Statement class to use for this extension.
   *
   * @var \Drupal\Core\Database\StatementInterface
   */
  protected $statementClass;

  /**
   * Flag to indicate if the cleanup function in __destruct() should run.
   *
   * @var bool
   */
  protected $needsCleanup = FALSE;

  /**
   * @todo shouldn't serialization being avoided?? this is from mysql core
   */
  public function serialize() {
    // Cleanup the connection, much like __destruct() does it as well.
    if ($this->needsCleanup) {
      $this->nextIdDelete();
    }
    $this->needsCleanup = FALSE;

    return parent::serialize();
  }

  /**
   * {@inheritdoc}
   */
  public function __destruct() {
    if ($this->needsCleanup) {
      $this->nextIdDelete();
    }
  }

  /**
   * Constructs a PDOMySqlExtension object.
   *
   * @param \Drupal\Driver\Database\dbal\Connection $drudbal_connection
   *   The Drupal database connection object for this extension.
   * @param \Doctrine\DBAL\Connection $dbal_connection
   *   The DBAL connection.
   * @param string $statement_class
   *   The StatementInterface class to be used.
   */
  public function __construct(DruDbalConnection $drudbal_connection, DbalConnection $dbal_connection, $statement_class) {
    $this->connection = $drudbal_connection;
    $this->dbalConnection = $dbal_connection;
    $this->statementClass = $statement_class;

    // Attach one database for each registered prefix.
    $prefixes = $this->connection->getPrefixes();
    foreach ($prefixes as &$prefix) {
      // Empty prefix means query the main database -- no need to attach anything.
      if (!empty($prefix)) {
        // Only attach the database once.
        if (!isset($this->attachedDatabases[$prefix])) {
          $this->attachedDatabases[$prefix] = $prefix;
          if ($this->connection->getConnectionOptions()['database'] === ':memory:') {
            // In memory database use ':memory:' as database name. According to
            // http://www.sqlite.org/inmemorydb.html it will open a unique
            // database so attaching it twice is not a problem.
            $this->connection->query('ATTACH DATABASE :database AS :prefix', [':database' => $this->connection->getConnectionOptions()['database'], ':prefix' => $prefix]);
          }
          else {
            $this->connection->query('ATTACH DATABASE :database AS :prefix', [':database' => $this->connection->getConnectionOptions()['database'] . '-' . $prefix, ':prefix' => $prefix]);
          }
        }

        // Add a ., so queries become prefix.table, which is proper syntax for
        // querying an attached database.
        $prefix .= '.';
      }
    }

    // Regenerate the prefixes replacement table.
    $this->connection->setPrefixPublic($prefixes);
  }

  /**
   * {@inheritdoc}
   */
  public function destroy() {
  }

  /**
   * {@inheritdoc}
   */
  public function delegateClientVersion() {
    return $this->dbalConnection->getWrappedConnection()->getAttribute(\PDO::ATTR_CLIENT_VERSION);
  }

  /**
   * {@inheritdoc}
   */
  public function delegatePrepare($statement, array $params, array $driver_options = []) {
    return new $this->statementClass($this->connection->getDbalConnection(), $this->connection, $statement, $driver_options);
  }

  /**
   * {@inheritdoc}
   */
  public function delegateQueryExceptionProcess($query, array $args, array $options, $message, \Exception $e) {
    // The database schema might be changed by another process in between the
    // time that the statement was prepared and the time the statement was run
    // (e.g. usually happens when running tests). In this case, we need to
    // re-run the query.
    // @see http://www.sqlite.org/faq.html#q15
    // @see http://www.sqlite.org/rescode.html#schema
    if ($e->getErrorCode() === 17) {
      return $this->connection->query($query, $args, $options);
    }

    // Match all SQLSTATE 23xxx errors.
    if (substr($e->getSqlState(), -6, -3) == '23') {
      throw new IntegrityConstraintViolationException($message, $e->getCode(), $e);
    }
    else {
      throw new DatabaseExceptionWrapper($message, 0, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getDbalConnection() {
    return $this->dbalConnection;
  }

  /**
   * Returns a fully prefixed table name from Drupal's {table} syntax.
   *
   * @param string $drupal table
   *   The table name in Drupal's syntax.
   *
   * @return string
   *   The fully prefixed table name to be used in the DBMS.
   */
  protected function tableName($drupal_table) {
    return $this->connection->getPrefixedTableName($drupal_table);
  }

  /**
   * Connection delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public static function preConnectionOpen(array &$connection_options, array &$dbal_connection_options) {
    $dbal_connection_options['path'] = $connection_options['database'];
    unset($dbal_connection_options['dbname']);
    $dbal_connection_options['driverOptions'] += [
      \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
      // Convert numeric values to strings when fetching.
      \PDO::ATTR_STRINGIFY_FETCHES => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function postConnectionOpen(DbalConnection $dbal_connection, array &$connection_options, array &$dbal_connection_options) {
    $pdo = $dbal_connection->getWrappedConnection();

    // Create functions needed by SQLite.
    $pdo->sqliteCreateFunction('if', [SqliteConnectionBase::class, 'sqlFunctionIf']);
    $pdo->sqliteCreateFunction('greatest', [SqliteConnectionBase::class, 'sqlFunctionGreatest']);
    $pdo->sqliteCreateFunction('pow', 'pow', 2);
    $pdo->sqliteCreateFunction('exp', 'exp', 1);
    $pdo->sqliteCreateFunction('length', 'strlen', 1);
    $pdo->sqliteCreateFunction('md5', 'md5', 1);
    $pdo->sqliteCreateFunction('concat', [SqliteConnectionBase::class, 'sqlFunctionConcat']);
    $pdo->sqliteCreateFunction('concat_ws', [SqliteConnectionBase::class, 'sqlFunctionConcatWs']);
    $pdo->sqliteCreateFunction('substring', [SqliteConnectionBase::class, 'sqlFunctionSubstring'], 3);
    $pdo->sqliteCreateFunction('substring_index', [SqliteConnectionBase::class, 'sqlFunctionSubstringIndex'], 3);
    $pdo->sqliteCreateFunction('rand', [SqliteConnectionBase::class, 'sqlFunctionRand']);
    $pdo->sqliteCreateFunction('regexp', [SqliteConnectionBase::class, 'sqlFunctionRegexp']);

    // SQLite does not support the LIKE BINARY operator, so we overload the
    // non-standard GLOB operator for case-sensitive matching. Another option
    // would have been to override another non-standard operator, MATCH, but
    // that does not support the NOT keyword prefix.
    $pdo->sqliteCreateFunction('glob', [SqliteConnectionBase::class, 'sqlFunctionLikeBinary']);

    // Create a user-space case-insensitive collation with UTF-8 support.
    $pdo->sqliteCreateCollation('NOCASE_UTF8', ['Drupal\Component\Utility\Unicode', 'strcasecmp']);

    // Set SQLite init_commands if not already defined. Enable the Write-Ahead
    // Logging (WAL) for SQLite. See https://www.drupal.org/node/2348137 and
    // https://www.sqlite.org/wal.html.
    $connection_options += [
      'init_commands' => [],
    ];
    $connection_options['init_commands'] += [
      'wal' => "PRAGMA journal_mode=WAL",
    ];

    // Execute sqlite init_commands.
    if (isset($connection_options['init_commands'])) {
      $dbal_connection->exec(implode('; ', $connection_options['init_commands']));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delegateTransactionSupport(array &$connection_options = []) {
    return !isset($connection_options['transactions']) || ($connection_options['transactions'] !== FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function delegateTransactionalDDLSupport(array &$connection_options = []) {
    return !isset($connection_options['transactions']) || ($connection_options['transactions'] !== FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function preCreateDatabase($database_name) {
    // Verify the database is writable.
    $db_directory = new \SplFileInfo(dirname($database_name));
    if (!$db_directory->isDir() && !drupal_mkdir($db_directory->getPathName(), 0755, TRUE)) {
      throw new DatabaseNotFoundException('Unable to create database directory ' . $db_directory->getPathName());
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function postCreateDatabase($database_name) {
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateMapConditionOperator($operator) {
    return isset(static::$sqliteConditionOperatorMap[$operator]) ? static::$sqliteConditionOperatorMap[$operator] : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateNextId($existing_id = 0) {
    $trn = $this->connection->startTransaction();
    // We can safely use literal queries here instead of the slower query
    // builder because if a given database breaks here then it can simply
    // override nextId. However, this is unlikely as we deal with short strings
    // and integers and no known databases require special handling for those
    // simple cases. If another transaction wants to write the same row, it will
    // wait until this transaction commits. Also, the return value needs to be
    // set to RETURN_AFFECTED as if it were a real update() query otherwise it
    // is not possible to get the row count properly.
    $affected = $this->connection->query('UPDATE {sequences} SET value = GREATEST(value, :existing_id) + 1', [
      ':existing_id' => $existing_id,
    ], ['return' => Database::RETURN_AFFECTED]);
    if (!$affected) {
      $this->connection->query('INSERT INTO {sequences} (value) VALUES (:existing_id + 1)', [
        ':existing_id' => $existing_id,
      ]);
    }
    // The transaction gets committed when the transaction object gets destroyed
    // because it gets out of scope.
    return $this->connection->query('SELECT value FROM {sequences}')->fetchField();
  }

  /**
   * {@inheritdoc}
   */
  public function delegateQueryRange($query, $from, $count, array $args = [], array $options = []) {
    return $this->connection->query($query . ' LIMIT ' . (int) $from . ', ' . (int) $count, $args, $options);
  }

  /**
   * {@inheritdoc}
   */
  public function delegateQueryTemporary($drupal_table_name, $query, array $args = [], array $options = []) {
    $prefixes = $this->connection->getPrefixes();
    $prefixes[$drupal_table_name] = '';
    $this->connection->setPrefixPublic($prefixes);
    return $this->connection->query('CREATE TEMPORARY TABLE ' . $drupal_table_name . ' AS ' . $query, $args, $options);
  }

  /**
   * {@inheritdoc}
   */
  public function delegateReleaseSavepointExceptionProcess(DbalDriverException $e) {
  }

  /**
   * Insert delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public function getAddDefaultsExplicitlyOnInsert() {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateDefaultsOnlyInsertSql(&$sql, $drupal_table_name) {
    $sql = 'INSERT INTO ' . $this->tableName($drupal_table_name) . ' DEFAULT VALUES';
    return TRUE;
  }

  /**
   * Truncate delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public function preTruncate($drupal_table_name) {
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function postTruncate($drupal_table_name) {
    return $this;
  }

  /**
   * Install\Tasks delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public static function delegateInstallConnectExceptionProcess(\Exception $e) {
    $results = [
      'fail' => [],
      'pass' => [],
    ];

    return $results;
  }

  /**
   * {@inheritdoc}
   */
  public function runInstallTasks() {
    $results = [
      'fail' => [],
      'pass' => [],
    ];

    // Ensure that Sqlite has the right minimum version.
    $db_server_version = $this->dbalConnection->getWrappedConnection()->getServerVersion();
    if (version_compare($db_server_version, self::SQLITE_MINIMUM_VERSION, '<')) {
      $results['fail'][] = t("The Sqlite version %version is less than the minimum required version %minimum_version.", [
        '%version' => $db_server_version,
        '%minimum_version' => self::SQLITE_MINIMUM_VERSION,
      ]);
    }

    return $results;
  }

  /**
   * Schema delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public function delegateTableExists(&$result, $drupal_table_name) {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateFieldExists(&$result, $drupal_table_name, $field_name) {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function alterCreateTableOptions(DbalTable $dbal_table, DbalSchema $dbal_schema, array &$drupal_table_specs, $drupal_table_name) {
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateGetDbalColumnType(&$dbal_type, array $drupal_field_specs) {
  }

  /**
   * {@inheritdoc}
   */
  public function alterDbalColumnOptions($context, array &$dbal_column_options, $dbal_type, array $drupal_field_specs, $field_name) {
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getDbalEncodedStringForDDLSql($string) {
    // Encode single quotes.
    return str_replace('\'', self::SINGLE_QUOTE_IDENTIFIER_REPLACEMENT, $string);
  }

  /**
   * {@inheritdoc}
   */
  public function alterDbalColumnDefinition($context, &$dbal_column_definition, array $dbal_column_options, $dbal_type, array $drupal_field_specs, $field_name) {
    // DBAL does not support unsigned float/numeric columns.
    // @see https://github.com/doctrine/dbal/issues/2380
    // @todo remove the version check once DBAL 2.6.0 is out.
    if (isset($drupal_field_specs['type']) && in_array($drupal_field_specs['type'], ['float', 'numeric', 'serial', 'int']) && !empty($drupal_field_specs['unsigned']) && (bool) $drupal_field_specs['unsigned'] === TRUE) {
      $dbal_column_definition .= ' CHECK (' . $field_name . '>= 0)';
    }
    // Decode single quotes.
    $dbal_column_definition = str_replace(self::SINGLE_QUOTE_IDENTIFIER_REPLACEMENT, '>mxmx<', $dbal_column_definition);
    // DBAL duplicates the COMMENT part when creating a table, or adding a
    // field, if comment is already in the 'customDefinition' option. Here,
    // just drop comment from the column definition string.
    // @see https://github.com/doctrine/dbal/pull/2725
//    if (in_array($context, ['createTable', 'addField'])) {
//      $dbal_column_definition = preg_replace("/ COMMENT (?:(?:'(?:\\\\\\\\)+'|'(?:[^'\\\\]|\\\\'?|'')*'))?/s", '', $dbal_column_definition);
//    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateAddField(&$primary_key_processed_by_extension, $drupal_table_name, $field_name, array $drupal_field_specs, array $keys_new_specs, array $dbal_column_options) {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateChangeField(&$primary_key_processed_by_extension, $drupal_table_name, $field_name, $field_new_name, array $drupal_field_new_specs, array $keys_new_specs, array $dbal_column_options) {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateFieldSetDefault($drupal_table_name, $field_name, $default) {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateFieldSetNoDefault($drupal_table_name, $field_name) {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateGetIndexName($drupal_table_name, $index_name, array $table_prefix_info) {
    return $table_prefix_info['schema'] . '_' . $table_prefix_info['table'] . '_' . $index_name;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateIndexExists(&$result, DbalSchema $dbal_schema, $drupal_table_name, $index_name) {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateAddPrimaryKey(DbalSchema $dbal_schema, $drupal_table_name, $drupal_field_specs) {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateAddUniqueKey($drupal_table_name, $index_name, $drupal_field_specs) {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateAddIndex($drupal_table_name, $index_name, array $drupal_field_specs, array $indexes_spec) {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateGetComment(&$comment, DbalSchema $dbal_schema, $drupal_table_name, $column = NULL) {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function alterGetComment(&$comment, DbalSchema $dbal_schema, $drupal_table_name, $column = NULL) {
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function alterSetTableComment(&$comment, $drupal_table_name, DbalSchema $dbal_schema, array $drupal_table_spec) {
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function alterSetColumnComment(&$comment, $dbal_type, array $drupal_field_specs, $field_name) {
    return $this;
  }

}