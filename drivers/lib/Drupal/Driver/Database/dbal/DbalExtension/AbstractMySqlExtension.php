<?php

namespace Drupal\Driver\Database\dbal\DbalExtension;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Database\Database;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Database\DatabaseNotFoundException;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Database\SchemaException;
use Drupal\Core\Database\TransactionCommitFailedException;
use Drupal\Driver\Database\dbal\Connection as DruDbalConnection;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\ConnectionException as DbalConnectionException;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Exception\ConnectionException as DbalExceptionConnectionException;

/**
 * Abstract DBAL Extension for MySql drivers.
 */
abstract class AbstractMySqlExtension implements DbalExtensionInterface {

  /**
   * Minimum required mysql version.
   */
  const MYSQLSERVER_MINIMUM_VERSION = '5.5.3';

  /**
   * Minimum required MySQLnd version.
   */
  const MYSQLND_MINIMUM_VERSION = '5.0.9';

  /**
   * Minimum required libmysqlclient version.
   */
  const LIBMYSQLCLIENT_MINIMUM_VERSION = '5.5.3';

  /**
   * Error code for "Unknown database" error.
   */
  const DATABASE_NOT_FOUND = 1049;

  /**
   * Error code for "Access denied" error.
   */
  const ACCESS_DENIED = 1045;

  /**
   * Error code for "Can't initialize character set" error.
   */
  const UNSUPPORTED_CHARSET = 2019;

  /**
   * Driver-specific error code for "Unknown character set" error.
   */
  const UNKNOWN_CHARSET = 1115;

  /**
   * SQLSTATE error code for "Syntax error or access rule violation".
   */
  const SQLSTATE_SYNTAX_ERROR = 42000;

  /**
   * Maximum length of a table comment in MySQL.
   */
  const COMMENT_MAX_TABLE = 60;

  /**
   * Maximum length of a column comment in MySQL.
   */
  const COMMENT_MAX_COLUMN = 255;

  /**
   * The minimal possible value for the max_allowed_packet setting of MySQL.
   *
   * @link https://mariadb.com/kb/en/mariadb/server-system-variables/#max_allowed_packet
   * @link https://dev.mysql.com/doc/refman/5.7/en/server-system-variables.html#sysvar_max_allowed_packet
   *
   * @var int
   */
  const MIN_MAX_ALLOWED_PACKET = 1024;

  /**
   * The DruDbal connection.
   *
   * @var @todo
   */
  protected $connection;

  /**
   * The actual DBAL connection.
   *
   * @var \Doctrine\DBAL\Connection
   */
  protected $dbalConnection;

  /**
   * Flag to indicate if the cleanup function in __destruct() should run.
   *
   * @var bool
   */
  protected $needsCleanup = FALSE;

  /**
   * Constructs a Connection object.
   */
  public function __construct(DruDbalConnection $drudbal_connection, DbalConnection $dbal_connection, $statement_class) {
    $this->connection = $drudbal_connection;
    $this->dbalConnection = $dbal_connection;
    $this->dbalConnection->getWrappedConnection()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [$statement_class, [$this->connection]]);
  }

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
   * Gets the DBAL connection.
   *
   * @return string DBAL driver name
   */
  public function getDbalConnection() {
    return $this->dbalConnection;
  }

  /**
   * {@inheritdoc}
   */
  public static function postConnectionOpen(DbalConnection $dbal_connection, array &$connection_options, array &$dbal_connection_options) {
    // Force MySQL to use the UTF-8 character set. Also set the collation, if a
    // certain one has been set; otherwise, MySQL defaults to
    // 'utf8mb4_general_ci' for utf8mb4.
    $sql = 'SET NAMES ' . $connection_options['charset'];
    if (!empty($connection_options['collation'])) {
      $sql .= ' COLLATE ' . $connection_options['collation'];
    }
    $dbal_connection->exec($sql);

    // Set MySQL init_commands if not already defined.  Default Drupal's MySQL
    // behavior to conform more closely to SQL standards.  This allows Drupal
    // to run almost seamlessly on many different kinds of database systems.
    // These settings force MySQL to behave the same as postgresql, or sqlite
    // in regards to syntax interpretation and invalid data handling.  See
    // https://www.drupal.org/node/344575 for further discussion. Also, as MySQL
    // 5.5 changed the meaning of TRADITIONAL we need to spell out the modes one
    // by one.
    $connection_options += [
      'init_commands' => [],
    ];
    $connection_options['init_commands'] += [
      'sql_mode' => "SET sql_mode = 'ANSI,STRICT_TRANS_TABLES,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,ONLY_FULL_GROUP_BY'",
    ];
    // Execute initial commands.
    foreach ($connection_options['init_commands'] as $sql) {
      $dbal_connection->exec($sql);
    }
  }

  /**
   * @todo
   */
  public function destroy() {
    // Destroy all references to this connection by setting them to NULL.
    // The Statement class attribute only accepts a new value that presents a
    // proper callable, so we reset it to PDOStatement.
    if (!empty($this->statementClass)) {
      $this->getDbalConnection()->getWrappedConnection()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, ['PDOStatement', []]);
    }
  }

  /**
   * @todo
   */
  public function transactionSupport(array &$connection_options = []) {
    // This driver defaults to transaction support, except if explicitly passed FALSE.
    return !isset($connection_options['transactions']) || ($connection_options['transactions'] !== FALSE);
  }

  /**
   * @todo
   */
  public function transactionalDDLSupport(array &$connection_options = []) {
    // MySQL never supports transactional DDL.
    return FALSE;
  }

  /**
   * @todo
   */
  public function prepare($statement, array $params, array $driver_options = []) {
    return $this->getDbalConnection()->getWrappedConnection()->prepare($statement, $driver_options);
  }

  /**
   * Wraps and re-throws any DBALException thrown by static::query().
   *
   * @param \Exception $e
   *   The exception thrown by query().
   * @param $query
   *   The query executed by query().
   * @param array $args
   *   An array of arguments for the prepared statement.
   * @param array $options
   *   An associative array of options to control how the query is run.
   *
   * @return @todo
   *
   * @throws \Drupal\Core\Database\DatabaseExceptionWrapper
   */
  public function handleQueryException(\Exception $e, $query, array $args = [], $options = []) {
    if ($options['throw_exception']) {
      // Wrap the exception in another exception, because PHP does not allow
      // overriding Exception::getMessage(). Its message is the extra database
      // debug information.
      if ($query instanceof StatementInterface) {
        $query_string = $query->getQueryString();
      }
      elseif (is_string($query)) {
        $query_string = $query;
      }
      else {
        $query_string = NULL;
      }
      $message = $e->getMessage() . ": " . $query_string . "; " . print_r($args, TRUE);
      // Match all SQLSTATE 23xxx errors.
      if (substr($e->getCode(), -6, -3) == '23') {
        throw new IntegrityConstraintViolationException($message, $e->getCode(), $e);
      }
      elseif ($e->errorInfo[1] == 1153) {
        // If a max_allowed_packet error occurs the message length is truncated.
        // This should prevent the error from recurring if the exception is
        // logged to the database using dblog or the like.
        $message = Unicode::truncateBytes($e->getMessage(), self::MIN_MAX_ALLOWED_PACKET);
        throw new DatabaseExceptionWrapper($message, $e->getCode(), $e);
      }
      else {
        throw new DatabaseExceptionWrapper($message, 0, $e);
      }
    }

    return NULL;
  }

  /**
   * @todo
   */
  public function preCreateDatabase($database) {
  }

  /**
   * @todo
   */
  public function postCreateDatabase($database) {
    // Set the database as active.
    $this->dbalConnection->exec("USE $database");
  }

  /**
   * @todo
   */
  public function nextId($existing_id = 0) {
    $new_id = $this->connection->query('INSERT INTO {sequences} () VALUES ()', [], ['return' => Database::RETURN_INSERT_ID]);
    // This should only happen after an import or similar event.
    if ($existing_id >= $new_id) {
      // If we INSERT a value manually into the sequences table, on the next
      // INSERT, MySQL will generate a larger value. However, there is no way
      // of knowing whether this value already exists in the table. MySQL
      // provides an INSERT IGNORE which would work, but that can mask problems
      // other than duplicate keys. Instead, we use INSERT ... ON DUPLICATE KEY
      // UPDATE in such a way that the UPDATE does not do anything. This way,
      // duplicate keys do not generate errors but everything else does.
      $this->connection->query('INSERT INTO {sequences} (value) VALUES (:value) ON DUPLICATE KEY UPDATE value = value', [':value' => $existing_id]);
      $new_id = $this->connection->query('INSERT INTO {sequences} () VALUES ()', [], ['return' => Database::RETURN_INSERT_ID]);
    }
    $this->needsCleanup = TRUE;
    return $new_id;
  }

  /**
   * @todo
   */
  public function nextIdDelete() {
    // While we want to clean up the table to keep it up from occupying too
    // much storage and memory, we must keep the highest value in the table
    // because InnoDB uses an in-memory auto-increment counter as long as the
    // server runs. When the server is stopped and restarted, InnoDB
    // reinitializes the counter for each table for the first INSERT to the
    // table based solely on values from the table so deleting all values would
    // be a problem in this case. Also, TRUNCATE resets the auto increment
    // counter.
    try {
      $max_id = $this->connection->query('SELECT MAX(value) FROM {sequences}')->fetchField();
      // We know we are using MySQL here, no need for the slower db_delete().
      $this->connection->query('DELETE FROM {sequences} WHERE value < :value', [':value' => $max_id]);
    }
    // During testing, this function is called from shutdown with the
    // simpletest prefix stored in $this->connection, and those tables are gone
    // by the time shutdown is called so we need to ignore the database
    // errors. There is no problem with completely ignoring errors here: if
    // these queries fail, the sequence will work just fine, just use a bit
    // more database storage and memory.
//    catch (DatabaseException $e) {
    catch (\Exception $e) {
      return;  // @todo
    }
  }

  public function queryRange($query, $from, $count, array $args = [], array $options = []) {
    return $this->connection->query($query . ' LIMIT ' . (int) $from . ', ' . (int) $count, $args, $options);
  }

  public function queryTemporary($tablename, $query, array $args = [], array $options = []) {
    return $this->connection->query('CREATE TEMPORARY TABLE {' . $tablename . '} Engine=MEMORY ' . $query, $args, $options);
  }

  /**
   * Returns the version of the database client.
   */
  abstract public function clientVersion();

  /**
   * Truncate delegated methods.
   */

  /**
   * @todo
   */
  public function preTruncate($table) {
    $this->dbalConnection->exec('SET FOREIGN_KEY_CHECKS=0');
  }

  /**
   * @todo
   */
  public function postTruncate($table) {
    $this->dbalConnection->exec('SET FOREIGN_KEY_CHECKS=1');
  }

  /**
   * Install\Tasks delegated methods.
   */

  /**
   * @todo
   */
  public static function handleInstallConnectException(DbalExceptionConnectionException $e) {
    $results = [
      'fail' => [],
      'pass' => [],
    ];

    $info = Database::getConnectionInfo();
    $pdo_exception = $e->getPrevious();

    // Detect utf8mb4 incompability.

    // @todo still clarify why this is needed

    if ($pdo_exception->getCode() == self::UNSUPPORTED_CHARSET || ($pdo_exception->getCode() == self::SQLSTATE_SYNTAX_ERROR && $pdo_exception->errorInfo[1] == self::UNKNOWN_CHARSET)) {
      $results['fail'][] = t('Your MySQL server and PHP MySQL driver must support utf8mb4 character encoding. Make sure to use a database system that supports this (such as MySQL/MariaDB/Percona 5.5.3 and up), and that the utf8mb4 character set is compiled in. See the <a href=":documentation" target="_blank">MySQL documentation</a> for more information.', [':documentation' => 'https://dev.mysql.com/doc/refman/5.0/en/cannot-initialize-character-set.html']);
      $info_copy = $info;
      // Set a flag to fall back to utf8. Note: this flag should only be
      // used here and is for internal use only.
      $info_copy['default']['_dsn_utf8_fallback'] = TRUE;
      // In order to change the Database::$databaseInfo array, we need to
      // remove the active connection, then re-add it with the new info.
      Database::removeConnection('default');
      Database::addConnectionInfo('default', 'default', $info_copy['default']);
      // Connect with the new database info, using the utf8 character set so
      // that we can run the checkEngineVersion test.
      Database::getConnection();
      // Revert to the old settings.
      Database::removeConnection('default');
      Database::addConnectionInfo('default', 'default', $info['default']);
      return $results;
    }

    // Attempt to create the database if it is not found. Try to establish a
    // connection without database specified, try to create database, and if
    // successful reopen the connection to the new database.
    if ($pdo_exception->getCode() == self::DATABASE_NOT_FOUND) {
      try {
        // Remove the database string from connection info.
        $database = $info['default']['database'];
        $dbal_url = $info['default']['dbal_url'];
        unset($info['default']['database']);
        if (($pos = strrpos($info['default']['dbal_url'], '/' . $database)) !== FALSE) {
          $info['default']['dbal_url'] = substr_replace($info['default']['dbal_url'], '', $pos, strlen($database) + 1);
        }

        // Change the Database::$databaseInfo array, need to remove the active
        // connection, then re-add it with the new info.
        Database::removeConnection('default');
        Database::addConnectionInfo('default', 'default', $info['default']);

        // Now, attempt the connection again; if it's successful, attempt to
        // create the database, then reset the connection info to original.
        Database::getConnection()->createDatabase($database);
        Database::closeConnection();
        $info['default']['database'] = $database;
        $info['default']['dbal_url'] = $dbal_url;
        Database::removeConnection('default');
        Database::addConnectionInfo('default', 'default', $info['default']);

        // Re-connect with the new database info.
        Database::getConnection();
        $results['pass'][] = t('Database %database was created successfully.', ['%database' => $database]);
      }
      catch (DatabaseNotFoundException $e) {
        // Still no dice; probably a permission issue. Raise the error to the
        // installer.
        $results['fail'][] = t('Creation of database %database failed. The server reports the following message: %error.', ['%database' => $database, '%error' => $pdo_exception->getMessage()]);
      }
      return $results;
    }

    // Database connection failed for some other reasons. Report.
    $results['fail'][] = t('Failed to connect to your database server. The server reports the following message: %error.<ul><li>Is the database server running?</li><li>Does the database exist or does the database user have sufficient privileges to create the database?</li><li>Have you entered the correct database name?</li><li>Have you entered the correct username and password?</li><li>Have you entered the correct database hostname?</li></ul>', ['%error' => $pdo_exception->getMessage()]);
    return $results;
  }

  /**
   * @todo
   */
  public function runInstallTasks() {
    $results = [
      'fail' => [],
      'pass' => [],
    ];

    // Ensure that MySql has the right minimum version.
    $db_server_version = $this->dbalConnection->getWrappedConnection()->getServerVersion();
    if (version_compare($db_server_version, self::MYSQLSERVER_MINIMUM_VERSION, '<')) {
      $results['fail'][] = t("The MySQL server version %version is less than the minimum required version %minimum_version.", [
        '%version' => $db_server_version,
        '%minimum_version' => self::MYSQLSERVER_MINIMUM_VERSION,
      ]);
    }

    // Ensure that InnoDB is available.
    $engines = $this->connection->query('SHOW ENGINES')->fetchAllKeyed();
    if (isset($engines['MyISAM']) && $engines['MyISAM'] == 'DEFAULT' && !isset($engines['InnoDB'])) {
      $results['fail'][] = t('The MyISAM storage engine is not supported.');
    }

    // Ensure that the MySQL driver supports utf8mb4 encoding.
    $version = $this->clientVersion();
    if (FALSE !== strpos($version, 'mysqlnd')) {
      // The mysqlnd driver supports utf8mb4 starting at version 5.0.9.
      $version = preg_replace('/^\D+([\d.]+).*/', '$1', $version);
      if (version_compare($version, self::MYSQLND_MINIMUM_VERSION, '<')) {
        $results['fail'][] = t("The MySQLnd driver version %version is less than the minimum required version. Upgrade to MySQLnd version %mysqlnd_minimum_version or up, or alternatively switch mysql drivers to libmysqlclient version %libmysqlclient_minimum_version or up.", ['%version' => $version, '%mysqlnd_minimum_version' => self::MYSQLND_MINIMUM_VERSION, '%libmysqlclient_minimum_version' => self::LIBMYSQLCLIENT_MINIMUM_VERSION]);
      }
    }
    else {
      // The libmysqlclient driver supports utf8mb4 starting at version 5.5.3.
      if (version_compare($version, self::LIBMYSQLCLIENT_MINIMUM_VERSION, '<')) {
        $results['fail'][] = t("The libmysqlclient driver version %version is less than the minimum required version. Upgrade to libmysqlclient version %libmysqlclient_minimum_version or up, or alternatively switch mysql drivers to MySQLnd version %mysqlnd_minimum_version or up.", ['%version' => $version, '%libmysqlclient_minimum_version' => self::LIBMYSQLCLIENT_MINIMUM_VERSION, '%mysqlnd_minimum_version' => self::MYSQLND_MINIMUM_VERSION]);
      }
    }

    return $results;
  }

  /**
   * Schema delegated methods.
   */

  public function delegateCreateTableSetOptions($dbal_table, $dbal_schema, &$table, $name) {
    // Provide defaults if needed.
    $table += [
      'mysql_engine' => 'InnoDB',
      'mysql_character_set' => 'utf8mb4',
    ];
    $dbal_table->addOption('charset', $table['mysql_character_set']);
    $dbal_table->addOption('engine', $table['mysql_engine']);
    $info = $this->connection->getConnectionOptions();
    $dbal_table->addOption('collate', empty($info['collation']) ? 'utf8mb4_general_ci' : $info['collation']);
  }

  public function delegateGetDbalColumnType(&$dbal_type, $field) {
    if (isset($field['mysql_type'])) {
      $dbal_type = $this->dbalConnection->getDatabasePlatform()->getDoctrineTypeMapping($field['mysql_type']);
      return TRUE;
    }
    return FALSE;
  }

  public function alterDbalColumnOptions(&$options, $dbal_type, $field, $field_name) {
    if (isset($field['type']) && $field['type'] == 'varchar_ascii') {
      $options['charset'] = 'ascii';
      $options['collation'] = 'ascii_general_ci';
    }
  }

  public function encodeDefaultValue($string) {
    return strtr($string, [
      '\'' => "]]]]QUOTEDELIMITERDRUDBAL[[[[",
    ]);
  }

  public function alterDbalColumnDefinition(&$dbal_column_definition, $options, $dbal_type, $field, $field_name) {
    // DBAL does not support unsigned float/numeric columns.
    // @see https://github.com/doctrine/dbal/issues/2380
    if (isset($field['type']) && $field['type'] == 'float' && !empty($field['unsigned']) && (bool) $field['unsigned'] === TRUE) {
      $dbal_column_definition = str_replace('DOUBLE PRECISION', 'DOUBLE PRECISION UNSIGNED', $dbal_column_definition);
    }
    if (isset($field['type']) && $field['type'] == 'numeric' && !empty($field['unsigned']) && (bool) $field['unsigned'] === TRUE) {
      $dbal_column_definition = preg_replace('/NUMERIC\((.+)\)/', '$0 UNSIGNED', $dbal_column_definition);
    }
    // DBAL does not support per-column charset.
    // @see https://github.com/doctrine/dbal/pull/881
    if (isset($field['type']) && $field['type'] == 'varchar_ascii') {
      $dbal_column_definition = preg_replace('/CHAR\(([0-9]+)\)/', '$0 CHARACTER SET ascii', $dbal_column_definition);
    }
    // DBAL does not support BINARY option for char/varchar columns.
    if (isset($field['binary']) && $field['binary']) {
      $dbal_column_definition = preg_replace('/CHAR\(([0-9]+)\)/', '$0 BINARY', $dbal_column_definition);
    }
    // Decode quotes.
    $dbal_column_definition = strtr($dbal_column_definition, [
      "]]]]QUOTEDELIMITERDRUDBAL[[[[" => '\\\'',
    ]);
  }

  public function delegateAddField(&$primary_key_processed_by_driver, $table, $field, $spec, $keys_new, $dbal_column_definition) {
    if (!empty($keys_new['primary key']) && isset($field['type']) && $field['type'] == 'serial') {
      $sql = 'ALTER TABLE {' . $table . '} ADD `' . $field . '` ' . $dbal_column_definition;
      $keys_sql = $this->createKeysSql(['primary key' => $keys_new['primary key']]);
      $sql .= ', ADD ' . $keys_sql[0];
      $this->connection->query($sql);
      $primary_key_processed_by_driver = TRUE;
      return TRUE;
    }
    return FALSE;
  }

  public function delegateChangeField(&$primary_key_processed_by_driver, $table, $field, $field_new, $spec, $keys_new, $dbal_column_definition) {
    $sql = 'ALTER TABLE {' . $table . '} CHANGE `' . $field . '` `' . $field_new . '` ' . $dbal_column_definition;
    if (!empty($keys_new['primary key'])) {
      $keys_sql = $this->createKeysSql(['primary key' => $keys_new['primary key']]);
      $sql .= ', ADD ' . $keys_sql[0];
      $primary_key_processed_by_driver = TRUE;
    }
    $this->connection->query($sql);
    return TRUE;
  }

  public function delegateFieldSetDefault($table, $field, $default) {
    // DBAL would use an ALTER TABLE ... CHANGE statement that would not
    // preserve non-DBAL managed column attributes. Use MySql syntax here
    // instead.
    $this->connection->query('ALTER TABLE {' . $table . '} ALTER COLUMN `' . $field . '` SET DEFAULT ' . $default);
    return TRUE;
  }

  public function delegateFieldSetNoDefault($table, $field) {
    // DBAL would use an ALTER TABLE ... CHANGE statement that would not
    // preserve non-DBAL managed column attributes. Use MySql syntax here
    // instead.
    $this->connection->query('ALTER TABLE {' . $table . '} ALTER COLUMN `' . $field . '` DROP DEFAULT');
    return TRUE;
  }

  public function delegateIndexExists(&$result, $table, $name) {
    if ($name == 'PRIMARY') {
      $schema = $this->dbalConnection->getSchemaManager()->createSchema();
      $result = $schema->getTable($this->pfxTable($table))->hasPrimaryKey();
      return TRUE;
    }
    return FALSE;
  }

  public function delegateAddPrimaryKey($schema, $table, $fields) {
    // DBAL does not support creating indexes with column lenghts.
    // @see https://github.com/doctrine/dbal/pull/2412
    if (($idx_cols = $this->dbalResolveIndexColumnNames($fields)) === FALSE) {
      $this->connection->query('ALTER TABLE {' . $table . '} ADD PRIMARY KEY (' . $this->createKeySql($fields) . ')');
      return TRUE;
    }
    return FALSE;
  }

  public function delegateAddUniqueKey($table, $name, $fields) {
    // DBAL does not support creating indexes with column lenghts.
    // @see https://github.com/doctrine/dbal/pull/2412
    if (($idx_cols = $this->dbalResolveIndexColumnNames($fields)) === FALSE) {
      $this->connection->query('ALTER TABLE {' . $table . '} ADD UNIQUE KEY `' . $name . '` (' . $this->createKeySql($fields) . ')');
      return TRUE;
    }
    return FALSE;
  }

  public function delegateAddIndex($table, $name, $fields, $spec) {
    // DBAL does not support creating indexes with column lenghts.
    // @see https://github.com/doctrine/dbal/pull/2412
    $spec['indexes'][$name] = $fields;
    $indexes = $this->getNormalizedIndexes($spec);
    if (($idx_cols = $this->dbalResolveIndexColumnNames($indexes[$name])) === FALSE) {
      $this->connection->query('ALTER TABLE {' . $table . '} ADD INDEX `' . $name . '` (' . $this->createKeySql($indexes[$name]) . ')');
      return TRUE;
    }
    return FALSE;
  }

  /**
   * @todo
   */
  public function pfxTable($table) {
    return $this->connection->prefixTables('{' . $table . '}');
  }

  /**
   * Retrieve a table or column comment.
   */
  public function delegateGetComment(&$comment, $dbal_schema, $table, $column = NULL) {
    if ($column !== NULL) {
      return FALSE;
    }

    // DBAL cannot retrieve table comments from introspected schema.
    // @see https://github.com/doctrine/dbal/issues/1335
    $dbal_query = $this->dbalConnection->createQueryBuilder();
    $dbal_query
      ->select('table_comment')
      ->from('information_schema.tables')
      ->where(
          $dbal_query->expr()->andX(
            $dbal_query->expr()->eq('table_schema', '?'),
            $dbal_query->expr()->eq('table_name', '?')
          )
        )
      ->setParameter(0, $this->dbalConnection->getDatabase())
      ->setParameter(1, $this->pfxTable($table));
    $comment = $dbal_query->execute()->fetchField();
    $this->alterGetComment($comment, $dbal_schema, $table, $column);
    return TRUE;
  }

  /**
   * Alter a table or column comment retrieved from schema.
   */
  public function alterGetComment(&$comment, $dbal_schema, $table, $column = NULL) {
    return;
  }

  /**
   * Alter a table comment being set.
   */
  public function alterSetTableComment($comment, $name, $dbal_schema, $table) {
    return Unicode::truncate($comment, self::COMMENT_MAX_TABLE, TRUE, TRUE);
  }

  /**
   * Alter a column comment being set.
   */
  public function alterSetColumnComment($comment, $dbal_type, $field, $field_name) {
    return Unicode::truncate($comment, self::COMMENT_MAX_COLUMN, TRUE, TRUE);
  }

  /**
   * @var array
   *   List of MySQL string types.
   */
  protected $mysqlStringTypes = [
    'VARCHAR',
    'CHAR',
    'TINYTEXT',
    'MEDIUMTEXT',
    'LONGTEXT',
    'TEXT',
  ];

  /**
   * Set database-engine specific properties for a field.
   *
   * @param $field
   *   A field description array, as specified in the schema documentation.
   */
  protected function processField($field) {

    if (!isset($field['size'])) {
      $field['size'] = 'normal';
    }

    // Set the correct database-engine specific datatype.
    // In case one is already provided, force it to uppercase.
    if (isset($field['mysql_type'])) {
      $field['mysql_type'] = Unicode::strtoupper($field['mysql_type']);
    }
    else {
      $map = $this->getFieldTypeMap();
      $field['mysql_type'] = $map[$field['type'] . ':' . $field['size']];
    }

    if (isset($field['type']) && $field['type'] == 'serial') {
      $field['auto_increment'] = TRUE;
    }

    return $field;
  }

  /**
   * Gets normalized indexes from a table specification.
   *
   * Shortens indexes to 191 characters if they apply to utf8mb4-encoded
   * fields, in order to comply with the InnoDB index limitation of 756 bytes.
   *
   * @param array $spec
   *   The table specification.
   *
   * @return array
   *   List of shortened indexes.
   *
   * @throws \Drupal\Core\Database\SchemaException
   *   Thrown if field specification is missing.
   */
  protected function getNormalizedIndexes(array $spec) {
    $indexes = isset($spec['indexes']) ? $spec['indexes'] : [];
    foreach ($indexes as $index_name => $index_fields) {
      foreach ($index_fields as $index_key => $index_field) {
        // Get the name of the field from the index specification.
        $field_name = is_array($index_field) ? $index_field[0] : $index_field;
        // Check whether the field is defined in the table specification.
        if (isset($spec['fields'][$field_name])) {
          // Get the MySQL type from the processed field.
          $mysql_field = $this->processField($spec['fields'][$field_name]);
          if (in_array($mysql_field['mysql_type'], $this->mysqlStringTypes)) {
            // Check whether we need to shorten the index.
            if ((!isset($mysql_field['type']) || $mysql_field['type'] != 'varchar_ascii') && (!isset($mysql_field['length']) || $mysql_field['length'] > 191)) {
              // Limit the index length to 191 characters.
              $this->shortenIndex($indexes[$index_name][$index_key]);
            }
          }
        }
        else {
          throw new SchemaException("MySQL needs the '$field_name' field specification in order to normalize the '$index_name' index");
        }
      }
    }
    return $indexes;
  }

  protected function getFieldTypeMap() {
    // Put :normal last so it gets preserved by array_flip. This makes
    // it much easier for modules (such as schema.module) to map
    // database types back into schema types.
    // $map does not use drupal_static as its value never changes.
    static $map = [
      'varchar_ascii:normal' => 'VARCHAR',

      'varchar:normal'  => 'VARCHAR',
      'char:normal'     => 'CHAR',

      'text:tiny'       => 'TINYTEXT',
      'text:small'      => 'TINYTEXT',
      'text:medium'     => 'MEDIUMTEXT',
      'text:big'        => 'LONGTEXT',
      'text:normal'     => 'TEXT',

      'serial:tiny'     => 'TINYINT',
      'serial:small'    => 'SMALLINT',
      'serial:medium'   => 'MEDIUMINT',
      'serial:big'      => 'BIGINT',
      'serial:normal'   => 'INT',

      'int:tiny'        => 'TINYINT',
      'int:small'       => 'SMALLINT',
      'int:medium'      => 'MEDIUMINT',
      'int:big'         => 'BIGINT',
      'int:normal'      => 'INT',

      'float:tiny'      => 'FLOAT',
      'float:small'     => 'FLOAT',
      'float:medium'    => 'FLOAT',
      'float:big'       => 'DOUBLE',
      'float:normal'    => 'FLOAT',

      'numeric:normal'  => 'DECIMAL',

      'blob:big'        => 'LONGBLOB',
      'blob:normal'     => 'BLOB',
    ];
    return $map;
  }

  /**
   * Helper function for normalizeIndexes().
   *
   * Shortens an index to 191 characters.
   *
   * @param array $index
   *   The index array to be used in createKeySql.
   *
   * @see Drupal\Core\Database\Driver\mysql\Schema::createKeySql()
   * @see Drupal\Core\Database\Driver\mysql\Schema::normalizeIndexes()
   */
  protected function shortenIndex(&$index) {
    if (is_array($index)) {
      if ($index[1] > 191) {
        $index[1] = 191;
      }
    }
    else {
      $index = [$index, 191];
    }
  }

  protected function createKeySql($fields) {
    $return = [];
    foreach ($fields as $field) {
      if (is_array($field)) {
        $return[] = '`' . $field[0] . '`(' . $field[1] . ')';
      }
      else {
        $return[] = '`' . $field . '`';
      }
    }
    return implode(', ', $return);
  }

  protected function dbalResolveIndexColumnNames($fields) {
    $return = [];
    foreach ($fields as $field) {
      if (is_array($field)) {
        return FALSE;
      }
      else {
        $return[] = $field;
      }
    }
    return $return;
  }

  protected function createKeysSql($spec) {
    $keys = [];

    if (!empty($spec['primary key'])) {
      $keys[] = 'PRIMARY KEY (' . $this->createKeySql($spec['primary key']) . ')';
    }
    if (!empty($spec['unique keys'])) {
      foreach ($spec['unique keys'] as $key => $fields) {
        $keys[] = 'UNIQUE KEY `' . $key . '` (' . $this->createKeySql($fields) . ')';
      }
    }
    if (!empty($spec['indexes'])) {
      $indexes = $this->druDbalDriver->getNormalizedIndexes($spec);
      foreach ($indexes as $index => $fields) {
        $keys[] = 'INDEX `' . $index . '` (' . $this->createKeySql($fields) . ')';
      }
    }

    return $keys;
  }

}
