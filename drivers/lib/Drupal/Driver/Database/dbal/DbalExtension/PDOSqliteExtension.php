<?php

namespace Drupal\Driver\Database\dbal\DbalExtension;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Database\Database;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Database\DatabaseNotFoundException;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Database\Driver\sqlite\Connection as SqliteConnectionBase;
use Drupal\Driver\Database\dbal\Connection as DruDbalConnection;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Exception\DriverException as DbalDriverException;
use Doctrine\DBAL\Schema\Schema as DbalSchema;
use Doctrine\DBAL\Statement as DbalStatement;

/**
 * Driver specific methods for pdo_sqlite.
 */
class PDOSqliteExtension extends AbstractExtension {

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
   * Replacement for single quote identifiers.
   *
   * @todo DBAL uses single quotes instead of backticks to produce DDL
   * statements. This causes problems if fields defaults or comments have
   * single quotes inside.
   */
  const SINGLE_QUOTE_IDENTIFIER_REPLACEMENT = ']]]]SINGLEQUOTEIDENTIFIERDRUDBAL[[[[';


  // @todo DBAL schema manager does not manage namespaces, so instead of
  // having a separate attached database for each prefix like in core Sqlite
  // driver, we have all the tables in the same main db.

  // @todo still check how :memory: database works.

  /**
   * {@inheritdoc}
   */
  public function delegateClientVersion() {
    return $this->dbalConnection->getWrappedConnection()->getAttribute(\PDO::ATTR_CLIENT_VERSION);
  }

  /**
   * {@inheritdoc}
   */
  public function delegateQueryExceptionProcess($query, array $args, array $options, $message, \Exception $e) {
    if ($e instanceof DatabaseExceptionWrapper) {
      $e = $e->getPrevious();
    }
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
  public function delegateFullQualifiedTableName($drupal_table_name) {
    // @todo needs cleanup!!! vs other similar methods and finding index name
    $table_prefix_info = $this->connection->schema()->getPrefixInfoPublic($drupal_table_name);
    return $table_prefix_info['schema'] . '.' . $table_prefix_info['table'];
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
  public function delegateTransactionalDdlSupport(array &$connection_options = []) {
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
  public function delegateMapConditionOperator($operator) {
    return isset(static::$sqliteConditionOperatorMap[$operator]) ? static::$sqliteConditionOperatorMap[$operator] : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateNextId($existing_id = 0) {
    // @codingStandardsIgnoreLine
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
    // @todo
  }

  /**
   * Statement delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public function alterStatement(&$query, array &$args) {
    // The PDO SQLite layer doesn't replace numeric placeholders in queries
    // correctly, and this makes numeric expressions (such as
    // COUNT(*) >= :count) fail.
    // We replace numeric placeholders in the query ourselves to work around
    // this bug.
    //
    // See http://bugs.php.net/bug.php?id=45259 for more details.
    if (count($args)) {
      // Check if $args is a simple numeric array.
      if (range(0, count($args) - 1) === array_keys($args)) {
        // In that case, we have unnamed placeholders.
        $count = 0;
        $new_args = [];
        foreach ($args as $value) {
          if (is_float($value) || is_int($value)) {
            if (is_float($value)) {
              // Force the conversion to float so as not to loose precision
              // in the automatic cast.
              $value = sprintf('%F', $value);
            }
            $query = substr_replace($query, $value, strpos($query, '?'), 1);
          }
          else {
            $placeholder = ':db_statement_placeholder_' . $count++;
            $query = substr_replace($query, $placeholder, strpos($query, '?'), 1);
            $new_args[$placeholder] = $value;
          }
        }
        $args = $new_args;
      }
      else {
        // Else, this is using named placeholders.
        foreach ($args as $placeholder => $value) {
          if (is_float($value) || is_int($value)) {
            if (is_float($value)) {
              // Force the conversion to float so as not to loose precision
              // in the automatic cast.
              $value = sprintf('%F', $value);
            }

            // We will remove this placeholder from the query as PDO throws an
            // exception if the number of placeholders in the query and the
            // arguments does not match.
            unset($args[$placeholder]);
            // PDO allows placeholders to not be prefixed by a colon. See
            // http://marc.info/?l=php-internals&m=111234321827149&w=2 for
            // more.
            if ($placeholder[0] != ':') {
              $placeholder = ":$placeholder";
            }
            // When replacing the placeholders, make sure we search for the
            // exact placeholder. For example, if searching for
            // ':db_placeholder_1', do not replace ':db_placeholder_11'.
            $query = preg_replace('/' . preg_quote($placeholder) . '\b/', $value, $query);
          }
        }
      }
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateFetch(DbalStatement $dbal_statement, $mode, $fetch_class) {
    if ($mode === \PDO::FETCH_CLASS) {
      $dbal_statement->setFetchMode($mode, $fetch_class);
    }
    return $dbal_statement->fetch($mode);
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

    // @todo is DBAL creating the db on connect? YES
    // if file path is wrong, what happens? EXCEPTION CODE 14

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
  public function alterDefaultSchema(&$default_schema) {
    $default_schema = 'main';
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateListTableNames() {
    try {
      return $this->getDbalConnection()->getSchemaManager()->listTableNames();
    }
    catch (DbalDriverException $e) {
      if ($e->getErrorCode() === 17) {
        return $this->getDbalConnection()->getSchemaManager()->listTableNames();
      }
      else {
        throw $e;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delegateGetDbalColumnType(&$dbal_type, array $drupal_field_specs) {
    if (isset($drupal_field_specs['sqlite_type'])) {
      $dbal_type = $this->dbalConnection->getDatabasePlatform()->getDoctrineTypeMapping($drupal_field_specs['sqlite_type']);
      return TRUE;
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getStringForDefault($string) {
    // Encode single quotes.
    return str_replace('\'', self::SINGLE_QUOTE_IDENTIFIER_REPLACEMENT, $string);
  }

  /**
   * {@inheritdoc}
   */
  public function alterDbalColumnDefinition($context, &$dbal_column_definition, array $dbal_column_options, $dbal_type, array $drupal_field_specs, $field_name) {
    // DBAL does not support BINARY option for char/varchar columns.
    if (isset($drupal_field_specs['binary']) && $drupal_field_specs['binary'] === FALSE) {
      $dbal_column_definition = preg_replace('/CHAR\(([0-9]+)\)/', '$0 COLLATE NOCASE_UTF8', $dbal_column_definition);
      $dbal_column_definition = preg_replace('/TEXT\(([0-9]+)\)/', '$0 COLLATE NOCASE_UTF8', $dbal_column_definition);
    }

    // @todo just setting 'unsigned' to true does not enforce values >=0 in the
    // field in Sqlite, so add a CHECK >= 0 constraint.
    if (isset($drupal_field_specs['type']) && in_array($drupal_field_specs['type'], ['float', 'numeric', 'serial', 'int']) && !empty($drupal_field_specs['unsigned']) && (bool) $drupal_field_specs['unsigned'] === TRUE) {
      $dbal_column_definition .= ' CHECK (' . $field_name . '>= 0)';
    }

    // @todo added to avoid edge cases; maybe this can be overridden in alterDbalColumnOptions
    if (array_key_exists('default', $drupal_field_specs) && $drupal_field_specs['default'] === '') {
      $dbal_column_definition = preg_replace('/DEFAULT (?!:\'\')/', "$0 ''", $dbal_column_definition);
    }
    $dbal_column_definition = preg_replace('/DEFAULT\s+\'\'\'\'/', "DEFAULT ''", $dbal_column_definition);

    // Decode single quotes.
    $dbal_column_definition = str_replace(self::SINGLE_QUOTE_IDENTIFIER_REPLACEMENT, '\'\'', $dbal_column_definition);

    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateAddField(&$primary_key_processed_by_extension, $drupal_table_name, $field_name, array $drupal_field_specs, array $keys_new_specs, array $dbal_column_options) {
    // SQLite doesn't have a full-featured ALTER TABLE statement. It only
    // supports adding new fields to a table, in some simple cases. In most
    // cases, we have to create a new table and copy the data over.
    if (empty($keys_new_specs) && (empty($drupal_field_specs['not null']) || isset($drupal_field_specs['default']))) {
      // When we don't have to create new keys and we are not creating a
      // NOT NULL column without a default value, we can use the quicker
      // version.
      $dbal_type = $this->connection->schema()->getDbalColumnType($drupal_field_specs);
      $dbal_column_options = $this->connection->schema()->getDbalColumnOptions('addField', $field_name, $dbal_type, $drupal_field_specs);
      $query = 'ALTER TABLE {' . $drupal_table_name . '} ADD ' . $field_name . ' ' . $dbal_column_options['columnDefinition'];
      $this->connection->query($query);

      // Apply the initial value if set.
      if (isset($drupal_field_specs['initial'])) {
        $this->connection->update($drupal_table_name)
          ->fields([$field_name => $drupal_field_specs['initial']])
          ->execute();
      }
      if (isset($drupal_field_specs['initial_from_field'])) {
        $this->connection->update($drupal_table_name)
          ->expression($field_name, $drupal_field_specs['initial_from_field'])
          ->execute();
      }
    }
    else {
      // We cannot add the field directly. Use the slower table alteration
      // method, starting from the old schema.
      $old_schema = $this->introspectSchema($drupal_table_name);
      $new_schema = $old_schema;

      // Add the new field.
      $new_schema['fields'][$field_name] = $drupal_field_specs;

      // Build the mapping between the old fields and the new fields.
      $mapping = [];
      if (isset($drupal_field_specs['initial'])) {
        // If we have a initial value, copy it over.
        $mapping[$field_name] = [
          'expression' => ':newfieldinitial',
          'arguments' => [':newfieldinitial' => $drupal_field_specs['initial']],
        ];
      }
      elseif (isset($drupal_field_specs['initial_from_field'])) {
        // If we have a initial value, copy it over.
        $mapping[$field_name] = [
          'expression' => $drupal_field_specs['initial_from_field'],
          'arguments' => [],
        ];
      }
      else {
        // Else use the default of the field.
        $mapping[$field_name] = NULL;
      }

      // Add the new indexes.
      $new_schema = array_merge($new_schema, $keys_new_specs);

      $this->alterTable($drupal_table_name, $old_schema, $new_schema, $mapping);
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateDropField($drupal_table_name, $field_name) {
    $old_schema = $this->introspectSchema($drupal_table_name);
    $new_schema = $old_schema;

    unset($new_schema['fields'][$field_name]);

    // Handle possible primary key changes.
    if (isset($new_schema['primary key']) && ($key = array_search($field_name, $new_schema['primary key'])) !== FALSE) {
      unset($new_schema['primary key'][$key]);
    }

    // Handle possible index changes.
    foreach ($new_schema['indexes'] as $index => $fields) {
      foreach ($fields as $key => $field) {
        if ($field == $field_name) {
          unset($new_schema['indexes'][$index][$key]);
        }
      }
      // If this index has no more fields then remove it.
      if (empty($new_schema['indexes'][$index])) {
        unset($new_schema['indexes'][$index]);
      }
    }
    $this->alterTable($drupal_table_name, $old_schema, $new_schema);
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateChangeField(&$primary_key_processed_by_extension, $drupal_table_name, $field_name, $field_new_name, array $drupal_field_new_specs, array $keys_new_specs, array $dbal_column_options) {
    $old_schema = $this->introspectSchema($drupal_table_name);
    $new_schema = $old_schema;

    // Map the old field to the new field.
    if ($field_name != $field_new_name) {
      $mapping[$field_new_name] = $field_name;
    }
    else {
      $mapping = [];
    }

    // Remove the previous definition and swap in the new one.
    unset($new_schema['fields'][$field_name]);
    $new_schema['fields'][$field_new_name] = $drupal_field_new_specs;

    // Map the former indexes to the new column name.
    $new_schema['primary key'] = $this->mapKeyDefinition($new_schema['primary key'], $mapping);
    foreach (['unique keys', 'indexes'] as $k) {
      foreach ($new_schema[$k] as &$key_definition) {
        $key_definition = $this->mapKeyDefinition($key_definition, $mapping);
      }
    }

    // Add in the keys from $keys_new_specs.
    if (isset($keys_new_specs['primary key'])) {
      $new_schema['primary key'] = $keys_new_specs['primary key'];
    }
    foreach (['unique keys', 'indexes'] as $k) {
      if (!empty($keys_new_specs[$k])) {
        $new_schema[$k] = $keys_new_specs[$k] + $new_schema[$k];
      }
    }

    $this->alterTable($drupal_table_name, $old_schema, $new_schema, $mapping);
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateFieldSetDefault($drupal_table_name, $field_name, $default) {
    $old_schema = $this->introspectSchema($drupal_table_name);
    $new_schema = $old_schema;

    $new_schema['fields'][$field_name]['default'] = $default;
    $this->alterTable($drupal_table_name, $old_schema, $new_schema);
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateFieldSetNoDefault($drupal_table_name, $field_name) {
    $old_schema = $this->introspectSchema($drupal_table_name);
    $new_schema = $old_schema;

    unset($new_schema['fields'][$field_name]['default']);
    $this->alterTable($drupal_table_name, $old_schema, $new_schema);
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateGetIndexName($drupal_table_name, $index_name, array $table_prefix_info) {
    return $table_prefix_info['table'] . '____' . $index_name;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateIndexExists(&$result, DbalSchema $dbal_schema, $drupal_table_name, $index_name) {
    $schema = $this->introspectSchema($drupal_table_name);
    if (in_array($index_name, array_keys($schema['unique keys']))) {
      $result = TRUE;
    }
    elseif (in_array($index_name, array_keys($schema['indexes']))) {
      $result = TRUE;
    }
    else {
      $result = FALSE;
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateAddUniqueKey($drupal_table_name, $index_name, array $drupal_field_specs) {
    $schema['unique keys'][$index_name] = $drupal_field_specs;
    $statements = $this->createIndexSql($drupal_table_name, $schema);
    foreach ($statements as $statement) {
      $this->connection->query($statement);
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateAddIndex($drupal_table_name, $index_name, array $drupal_field_specs, array $indexes_spec) {
    $schema['indexes'][$index_name] = $drupal_field_specs;
    $statements = $this->createIndexSql($drupal_table_name, $schema);
    foreach ($statements as $statement) {
      $this->connection->query($statement);
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateDropIndex($drupal_table_name, $index_name) {
    $info = $this->connection->schema()->getPrefixInfoPublic($drupal_table_name);
    $this->connection->query('DROP INDEX ' . $info['table'] . '____' . $index_name);
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateGetTableComment(DbalSchema $dbal_schema, $drupal_table_name) {
    throw new \RuntimeException('Table comments are not supported in SQlite.');
  }

  /**
   * Find out the schema of a table.
   *
   * This function uses introspection methods provided by the database to
   * create a schema array. This is useful, for example, during update when
   * the old schema is not available.
   *
   * @param string $table
   *   Name of the table.
   *
   * @return array
   *   An array representing the schema, from drupal_get_schema().
   *
   * @throws \Exception
   *   If a column of the table could not be parsed.
   */
  protected function introspectSchema($table) {
    $mapped_fields = array_flip($this->getFieldTypeMap());
    $schema = [
      'fields' => [],
      'primary key' => [],
      'unique keys' => [],
      'indexes' => [],
      'full_index_names' => [],
    ];

    $info = $this->connection->schema()->getPrefixInfoPublic($table);
    $result = $this->connection->query('PRAGMA ' . $info['schema'] . '.table_info(' . $info['table'] . ')');
    foreach ($result as $row) {
      if (preg_match('/^([^(]+)\((.*)\)$/', $row->type, $matches)) {
        $type = $matches[1];
        $length = $matches[2];
      }
      else {
        $type = $row->type;
        $length = NULL;
      }
      // @todo this was added to avoid test failure, not sure this is correct
      $type = preg_replace('/ UNSIGNED/', '', $type);
      if (isset($mapped_fields[$type])) {
        list($type, $size) = explode(':', $mapped_fields[$type]);
        $schema['fields'][$row->name] = [
          'type' => $type,
          'size' => $size,
          'not null' => !empty($row->notnull),
          'default' => trim($row->dflt_value, "'"),
        ];
        // @todo this was added to avoid test failure, not sure it is correct.
        if ($row->pk) {
          $schema['fields'][$row->name]['not null'] = FALSE;
        }
        if ($length) {
          $schema['fields'][$row->name]['length'] = $length;
        }
        if ($row->pk) {
          $schema['primary key'][] = $row->name;
        }
        // @todo this was added to avoid test failure, not sure this is correct
        if (preg_match('/ UNSIGNED/', $row->type, $matches)) {
          $schema['fields'][$row->name]['unsigned'] = TRUE;
        }
      }
      else {
        throw new \Exception("Unable to parse the column type " . $row->type);
      }
    }
    $indexes = [];
    $result = $this->connection->query('PRAGMA ' . $info['schema'] . '.index_list(' . $info['table'] . ')');
    foreach ($result as $row) {
      if (strpos($row->name, 'sqlite_autoindex_') !== 0) {
        $schema['full_index_names'][] = $row->name;
        $indexes[] = [
          'schema_key' => $row->unique ? 'unique keys' : 'indexes',
          'name' => $row->name,
        ];
      }
    }
    foreach ($indexes as $index) {
      $name = $index['name'];
      // Get index name without prefix.
      $matches = NULL;
      if (preg_match('/.*____(.+)/', $name, $matches)) {
        $index_name = $matches[1];
        $result = $this->connection->query('PRAGMA ' . $info['schema'] . '.index_info(' . $name . ')');
        foreach ($result as $row) {
          $schema[$index['schema_key']][$index_name][] = $row->name;
        }
      }
    }
    return $schema;
  }

  /**
   * Utility method: rename columns in an index definition according to a new mapping.
   *
   * @param array $key_definition
   *   The key definition.
   * @param array $mapping
   *   The new mapping.
   */
  protected function mapKeyDefinition(array $key_definition, array $mapping) {
    foreach ($key_definition as &$field) {
      // The key definition can be an array($field, $length).
      if (is_array($field)) {
        $field = &$field[0];
      }
      if (isset($mapping[$field])) {
        $field = $mapping[$field];
      }
    }
    return $key_definition;
  }

  /**
   * Create a table with a new schema containing the old content.
   *
   * As SQLite does not support ALTER TABLE (with a few exceptions) it is
   * necessary to create a new table and copy over the old content.
   *
   * @param string $table
   *   Name of the table to be altered.
   * @param array $old_schema
   *   The old schema array for the table.
   * @param array $new_schema
   *   The new schema array for the table.
   * @param array $mapping
   *   An optional mapping between the fields of the old specification and the
   *   fields of the new specification. An associative array, whose keys are
   *   the fields of the new table, and values can take two possible forms:
   *     - a simple string, which is interpreted as the name of a field of the
   *       old table,
   *     - an associative array with two keys 'expression' and 'arguments',
   *       that will be used as an expression field.
   */
  protected function alterTable($table, array $old_schema, array $new_schema, array $mapping = []) {
    $i = 0;
    do {
      $new_table = $table . '_' . $i++;
    } while ($this->connection->schema()->tableExists($new_table));

    // Drop any existing index from the old table.
    foreach ($old_schema['full_index_names'] as $full_index_name) {
      $this->connection->query('DROP INDEX ' . $full_index_name);
    }

    $this->connection->schema()->createTable($new_table, $new_schema);

    // Build a SQL query to migrate the data from the old table to the new.
    $select = $this->connection->select($table);

    // Complete the mapping.
    $possible_keys = array_keys($new_schema['fields']);
    $mapping += array_combine($possible_keys, $possible_keys);

    // Now add the fields.
    foreach ($mapping as $field_alias => $field_source) {
      // Just ignore this field (ie. use it's default value).
      if (!isset($field_source)) {
        continue;
      }

      if (is_array($field_source)) {
        $select->addExpression($field_source['expression'], $field_alias, $field_source['arguments']);
      }
      else {
        $select->addField($table, $field_source, $field_alias);
      }
    }

    // Execute the data migration query.
    $this->connection->insert($new_table)
      ->from($select)
      ->execute();

    $old_count = $this->connection->query('SELECT COUNT(*) FROM {' . $table . '}')->fetchField();
    $new_count = $this->connection->query('SELECT COUNT(*) FROM {' . $new_table . '}')->fetchField();
    if ($old_count == $new_count) {
      $this->connection->schema()->dropTable($table);
      $this->connection->schema()->renameTable($new_table, $table);
    }
  }

  /**
   * This maps a generic data type in combination with its data size
   * to the engine-specific data type.
   */
  protected function getFieldTypeMap() {
    // Put :normal last so it gets preserved by array_flip. This makes
    // it much easier for modules (such as schema.module) to map
    // database types back into schema types.
    // $map does not use drupal_static as its value never changes.
    static $map = [
      'varchar_ascii:normal' => 'CLOB',

      'varchar:normal'  => 'VARCHAR',
      'char:normal'     => 'CHAR',

      'text:tiny'       => 'TEXT',
      'text:small'      => 'TEXT',
      'text:medium'     => 'TEXT',
      'text:big'        => 'TEXT',
      'text:normal'     => 'TEXT',

      'serial:tiny'     => 'SMALLINT',
      'serial:small'    => 'SMALLINT',
      'serial:medium'   => 'INTEGER',
      'serial:big'      => 'BIGINT',
      'serial:normal'   => 'INTEGER',

      'int:tiny'        => 'SMALLINT',
      'int:small'       => 'SMALLINT',
      'int:medium'      => 'INTEGER',
      'int:big'         => 'BIGINT',
      'int:normal'      => 'INTEGER',

      'float:tiny'      => 'DOUBLE PRECISION',
      'float:small'     => 'DOUBLE PRECISION',
      'float:medium'    => 'DOUBLE PRECISION',
      'float:big'       => 'DOUBLE PRECISION',
      'float:normal'    => 'FLOAT',

      'numeric:normal'  => 'NUMERIC',

      'blob:big'        => 'BLOB',
      'blob:normal'     => 'BLOB',
    ];
    return $map;
  }

  /**
   * Build the SQL expression for indexes.
   */
  protected function createIndexSql($tablename, $schema) {
    $sql = [];
    $info = $this->connection->schema()->getPrefixInfoPublic($tablename);
    if (!empty($schema['unique keys'])) {
      foreach ($schema['unique keys'] as $key => $fields) {
        $sql[] = 'CREATE UNIQUE INDEX ' . $info['table'] . '____' . $key . ' ON ' . $info['table'] . ' (' . $this->createKeySql($fields) . ")\n";
      }
    }
    if (!empty($schema['indexes'])) {
      foreach ($schema['indexes'] as $key => $fields) {
        $sql[] = 'CREATE INDEX ' . $info['table'] . '____' . $key . ' ON ' . $info['table'] . ' (' . $this->createKeySql($fields) . ")\n";
      }
    }
    return $sql;
  }

  /**
   * Build the SQL expression for keys.
   */
  protected function createKeySql($fields) {
    $return = [];
    foreach ($fields as $field) {
      if (is_array($field)) {
        $return[] = $field[0];
      }
      else {
        $return[] = $field;
      }
    }
    return implode(', ', $return);
  }

}
