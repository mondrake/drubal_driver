<?php

namespace Drupal\drudbal\Driver\Database\dbal\DbalExtension;

use Drupal\Component\Utility\Timer;
use Drupal\Component\Uuid\Php as Uuid;
use Drupal\Core\Database\Database;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Database\DatabaseNotFoundException;
use Drupal\Core\Database\IntegrityConstraintViolationException;

use Drupal\drudbal\Driver\Database\dbal\Connection as DruDbalConnection;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\Exception\DriverException as DbalDriverException;
use Doctrine\DBAL\Schema\Schema as DbalSchema;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;

/**
 * Abstract DBAL Extension for Oracle drivers.
 */
class AbstractOracleExtension extends AbstractExtension {

  // protected static $isDebugging = TRUE;

  const ORACLE_EMPTY_STRING_REPLACEMENT = "\010"; // it's the Backspace, dec=8, hex=8, oct=10.

  /**
   * A map of condition operators to SQLite operators.
   *
   * @var array
   */
  protected static $oracleConditionOperatorMap = [
    'LIKE' => ['postfix' => " ESCAPE '\\'"],
    'NOT LIKE' => ['postfix' => " ESCAPE '\\'"],
  ];

  private $tempTables = [];

  /**
   * Map of database identifiers.
   *
   * This array maps actual database identifiers to identifiers longer than 30
   * characters, to allow dealing with Oracle constraint.
   *
   * @var string[]
   */
  private $dbIdentifiersMap = [];

  /**
   * Destructs an Oci8 extension object.
   */
  public function __destruct() {
    foreach ($this->tempTables as $db_table) {
      try {
        $this->getDbalConnection()->exec("TRUNCATE TABLE $db_table");
        $this->getDbalConnection()->exec("DROP TABLE $db_table");
      }
      catch (\Exception $e) {
        throw new \RuntimeException("Missing temp table $db_table", $e->getCode(), $e);
      }
    }
    parent::__destruct();
  }

  /**
   * Database asset name resolution methods.
   */

  private function getLimitedIdentifier(string $identifier, int $max_length = 30): string {
    if (strlen($identifier) > $max_length) {
      $identifier_crc = hash('crc32b', $identifier);
      $limited_identifier = substr($identifier, 0, $max_length - 8) . $identifier_crc;
      $this->dbIdentifiersMap[$limited_identifier] = $identifier;
      return $limited_identifier;
    }
    return $identifier;
  }

  /**
   * {@inheritdoc}
   */
  public function getDbTableName(string $drupal_prefix, string $drupal_table_name): string {
    $prefixed_table_name = $drupal_prefix . $drupal_table_name;
    // Max length for Oracle is 30 chars, but should be even lower to allow
    // DBAL creating triggers/sequences with table name + suffix.
    if (strlen($prefixed_table_name) > 24) {
      $identifier_crc = hash('crc32b', $prefixed_table_name);
      $prefixed_table_name = substr($prefixed_table_name, 0, 16) . $identifier_crc;
    }
    return $prefixed_table_name;
  }

  /**
   * {@inheritdoc}
   */
  public function getDbFullQualifiedTableName($drupal_table_name) {
    $options = $this->connection->getConnectionOptions();
    $prefix = $this->connection->tablePrefix($drupal_table_name);
    return $options['username'] . '."' . $this->getDbTableName($prefix, $drupal_table_name) . '"';
  }

  /**
   * {@inheritdoc}
   */
  public function getDbFieldName($field_name, bool $quoted = TRUE) {
    if ($field_name === NULL || $field_name === '') {
      return '';
    }

    if (strpos($field_name, '.') !== FALSE) {
      [$table_tmp, $field_tmp] = explode('.', $field_name);
      $table = $this->getLimitedIdentifier($table_tmp);
      $field = $this->getLimitedIdentifier($field_tmp);
    }
    else {
      $field = $this->getLimitedIdentifier($field_name);
    }

    $identifier = '';
    if (isset($table)) {
      $identifier .= $quoted ? '"' . $table . '".' : $table . '.';
    }

    $identifier .= $quoted ? '"' . $field . '"' : $field;

    return $identifier;
  }

  /**
   * {@inheritdoc}
   */
  public function getDbAlias($alias, bool $quoted = TRUE) {
    if ($alias === NULL || $alias === '') {
      return '';
    }

    if (substr($alias, 0, 1) === '"') {
      return $alias;
    }

    if (strpos($alias, '.') !== FALSE) {
      [$table_tmp, $alias_tmp] = explode('.', $alias);
      $table = $this->getLimitedIdentifier($table_tmp);
      $alias = $this->getLimitedIdentifier($alias_tmp);
    }
    else {
      $alias = $this->getLimitedIdentifier($alias);
    }

    $identifier = '';
    if (isset($table)) {
      $identifier .= $quoted ? '"' . $table . '".' : $table . '.';
    }

    $identifier .= $quoted ? '"' . $alias . '"' : $alias;

    return $identifier;
  }

  /**
   * {@inheritdoc}
   */
  public function resolveAliases(?string $unaliased): string {
    return $unaliased ? strtr($unaliased, array_flip($this->dbIdentifiersMap)) : '';
  }

  /**
   * {@inheritdoc}
   */
  public function getDbIndexName(string $context, DbalSchema $dbal_schema, string $drupal_table_name, string $drupal_index_name): string {
    // If checking for index existence or dropping, see if an index exists
    // with the Drupal name, regardless of prefix. It may be a table was
    // renamed so the prefix is no longer relevant.
    if (in_array($context, ['indexExists', 'dropIndex'])) {
      $dbal_table = $dbal_schema->getTable($this->connection->getPrefixedTableName($drupal_table_name, TRUE));
      foreach ($dbal_table->getIndexes() as $index) {
        $index_full_name = $index->getName();
        $matches = [];
        if (preg_match('/.*____(.+)/', $index_full_name, $matches)) {
          if ($matches[1] === hash('crc32b', $drupal_index_name)) {
            return strtolower($index_full_name);
          }
        }
      }
      return FALSE;
    }
    else {
      // To keep things... short, use a CRC32 hash of a UUID and one of the
      // Drupal index name as the db name of the index.
      $uuid = new Uuid();
      return 'IDX_' . hash('crc32b', $uuid->generate()) . '____' . hash('crc32b', $drupal_index_name);
    }
  }

  /**
   * Connection delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public static function preConnectionOpen(array &$connection_options, array &$dbal_connection_options) {
    // @todo check if not passed yet
    $dbal_connection_options['charset'] = 'AL32UTF8';
  }

  /**
   * {@inheritdoc}
   */
  public static function postConnectionOpen(DbalConnection $dbal_connection, array &$connection_options, array &$dbal_connection_options) {
    $dbal_connection->exec('ALTER SESSION SET NLS_LENGTH_SEMANTICS=CHAR');
  }

  /**
   * {@inheritdoc}
   */
  public function delegateTransactionalDdlSupport(array &$connection_options = []): bool {
    // Transactional DDL is not available in Oracle.
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateMapConditionOperator($operator) {
    return isset(static::$oracleConditionOperatorMap[$operator]) ? static::$oracleConditionOperatorMap[$operator] : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateNextId(int $existing_id = 0): int {
    // @codingStandardsIgnoreLine
    $trn = $this->connection->startTransaction();
    $affected = $this->connection->query('UPDATE {sequences} SET [value] = GREATEST([value], :existing_id) + 1', [
      ':existing_id' => $existing_id,
    ], ['return' => Database::RETURN_AFFECTED]);
    if (!$affected) {
      $this->connection->query('INSERT INTO {sequences} ([value]) VALUES (:new_id)', [
        ':new_id' => $existing_id + 1,
      ]);
    }
    // The transaction gets committed when the transaction object gets destroyed
    // because it gets out of scope.
    return (int) $this->connection->query('SELECT [value] FROM {sequences}')->fetchField();
  }

  /**
   * {@inheritdoc}
   */
  public function delegateQueryRange($query, $from, $count, array $args = [], array $options = []) {
    $limit_query = $this->getDbalConnection()->getDatabasePlatform()->modifyLimitQuery($query, $count, $from);
    return $this->connection->query($limit_query, $args, $options);
  }

  /**
   * Generates a temporary table name.
   *
   * @return string
   *   A table name.
   */
  protected function generateTemporaryTableName() {
    return $this->getLimitedIdentifier(parent::generateTemporaryTableName(), 24);
  }

  /**
   * {@inheritdoc}
   */
  public function delegateQueryTemporary(string $query, array $args = [], array $options = []): string {
    $table_name = $this->generateTemporaryTableName();
    $this->connection->query("CREATE GLOBAL TEMPORARY TABLE \"$table_name\" ON COMMIT PRESERVE ROWS AS $query", $args, $options);

    // @todo Oracle 18 allows session scoped temporary tables, but until then
    //   we need to store away the table being created and drop it during
    //   destruction.
    $this->tempTables[$table_name] = '"' . $table_name . '"';

    // Temp tables should not be prefixed.
    $prefixes = $this->connection->getPrefixes();
    $prefixes[$table_name] = '';
    $this->connection->setPrefixPublic($prefixes);

    return $table_name;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateQueryExceptionProcess($query, array $args, array $options, $message, \Exception $e) {
    if ($e instanceof DatabaseExceptionWrapper) {
      $e = $e->getPrevious();
    }
    if ($e instanceof UniqueConstraintViolationException) {
      throw new IntegrityConstraintViolationException($message, $e->getCode(), $e);
    }
    elseif ($e instanceof NotNullConstraintViolationException) {
      throw new IntegrityConstraintViolationException($message, $e->getCode(), $e);
    }
    elseif ($e instanceof DbalDriverException) {
      switch ($e->getCode()) {
        // ORA-01407 cannot update (string) to NULL.
        case 1407:
          throw new IntegrityConstraintViolationException($message, $e->getCode(), $e);

        // ORA-00900: invalid SQL statement.
        case 900:
          throw new DatabaseExceptionWrapper($message, 0, $e);

        default:
          /*if ($this->getDebugging()) {
            $backtrace = debug_backtrace();
            error_log("\n***** Exception    : " . get_class($e));
            error_log('***** Message      : ' . $message);
            error_log('***** getCode      : ' . $e->getCode());
            error_log('***** getSQLState  : ' . $e->getSQLState());
            error_log('***** Query        : ' . $query);
            error_log('***** Query args   : ' . var_export($args, TRUE));
            error_log("***** Backtrace    : \n" . $this->formatBacktrace($backtrace));
          }*/
          throw new DatabaseExceptionWrapper($message, 0, $e);

      }
    }
    else {
      throw new DatabaseExceptionWrapper($message, 0, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function handleDropTableException(\Exception $e, string $drupal_table_name, string $db_table_name): void {
    // ORA-14452: attempt to create, alter or drop an index on temporary table
    // already in use.
    if ($e->getCode() == 14452) {
      // In this case the table is temporary, and will be removed by the
      // destructor.
      return;
    }

    throw new DatabaseExceptionWrapper("Failed dropping table '$drupal_table_name', (on DBMS: '$db_table_name')", $e->getCode(), $e);
  }

  /**
   * Formats a backtrace into a plain-text string.
   *
   * The calls show values for scalar arguments and type names for complex ones.
   *
   * @param array $backtrace
   *   A standard PHP backtrace.
   *
   * @return string
   *   A plain-text line-wrapped string ready to be put inside <pre>.
   */
  public static function formatBacktrace(array $backtrace) {
    $return = '';

    foreach ($backtrace as $trace) {
      $call = ['function' => '', 'args' => []];

      if (isset($trace['class'])) {
        $call['function'] = $trace['class'] . $trace['type'] . $trace['function'];
      }
      elseif (isset($trace['function'])) {
        $call['function'] = $trace['function'];
      }
      else {
        $call['function'] = 'main';
      }

      /*      if (isset($trace['args'])) {
              foreach ($trace['args'] as $arg) {
                if (is_scalar($arg)) {
                  $call['args'][] = is_string($arg) ? '\'' . $arg . '\'' : $arg;
                }
                else {
                  $call['args'][] = ucfirst(gettype($arg));
                }
              }
            }*/

      $line = '';
      if (isset($trace['line'])) {
        $line = " (Line: {$trace['line']})";
      }

      $return .= $call['function'] . '(' . /*implode(', ', $call['args']) .*/ ")$line\n";
    }

    return $return;
  }

  /**
   * DrudbalDateSql delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public function delegateGetDateFieldSql(string $field, bool $string_date) : string {
    if ($string_date) {
      return $field;
    }

    return "TO_DATE('19700101', 'YYYYMMDD') + (1 / 24 / 60 / 60) * $field";
  }

  /**
   * {@inheritdoc}
   */
  public function delegateGetDateFormatSql(string $field, string $format) : string {
    // An array of PHP-to-Oracle date replacement patterns.
    static $replace = [
      'Y' => 'YYYY',
      'y' => 'YY',
      'M' => 'MON',
      'm' => 'MM',
      // No format for Numeric representation of a month, without leading
      // zeros.
      'n' => 'MM',
      'F' => 'MONTH',
      'D' => 'DY',
      'd' => 'DD',
      'l' => 'DAY',
      // No format for Day of the month without leading zeros.
      'j' => 'DD',
      'W' => 'IW',
      'H' => 'HH24',
      'h' => 'HH12',
      'i' => 'MI',
      's' => 'SS',
      'A' => 'AM',
    ];

    $format = strtr($format, $replace);
    return "TO_CHAR($field, '$format')";
  }

  /**
   * {@inheritdoc}
   */
  public function delegateSetTimezoneOffset(string $offset) : void {
    // Nothing to do here.
  }

  /**
   * {@inheritdoc}
   */
  public function delegateSetFieldTimezoneOffsetSql(string &$field, int $offset) : void {
    // Nothing to do here.
  }

  /**
   * Statement delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public function alterStatement(&$query, array &$args) {
//    if ($this->getDebugging()) dump(['pre-alter', $query, $args]);

    // Reprocess args.
    $new_args = [];
    foreach ($args as $placeholder => $value) {
      // Rename placeholders that are reserved keywords.
      if ($this->connection->getDbalPlatform()->getReservedKeywordsList()->isKeyword(substr($placeholder, 1))) {
        $new_placeholder = $placeholder . 'x';
        $query = str_replace($placeholder, $new_placeholder, $query);
        $placeholder = $new_placeholder;
      }
      // Modify arguments for empty strings.
      $new_args[$placeholder] = $value === '' ? self::ORACLE_EMPTY_STRING_REPLACEMENT : $value;  // @todo here check
    }
    $args = $new_args;

    // Replace empty strings.
    $query = str_replace("''", "'" . self::ORACLE_EMPTY_STRING_REPLACEMENT . "'", $query);

    // INSERT INTO table () VALUES () requires the placeholders to be listed,
    // and no columns list.
    if (strpos($query, ' () VALUES()') !== FALSE) {
      $query = str_replace(' () VALUES()', ' VALUES(' . implode(', ', array_keys($args)) . ')', $query);
    }

    // REGEXP is not available in Oracle; convert to using REGEXP_LIKE
    // function.
    $query = preg_replace('/([^\s]+)\s+NOT REGEXP\s+([^\s]+)/', 'NOT REGEXP_LIKE($1, $2)', $query);
    $query = preg_replace('/([^\s]+)\s+REGEXP\s+([^\s]+)/', 'REGEXP_LIKE($1, $2)', $query);

    // In case of missing from, Oracle requires FROM DUAL.
    if (strpos($query, 'SELECT ') === 0 && strpos($query, ' FROM ') === FALSE) {
      $query .= ' FROM DUAL';
    }

    if ($this->getDebugging()) dump(['post-alter', $query, $args]);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function processFetchedRecord(array $record) : array {
    // Enforce all values are of type 'string'.
    $result = [];
    foreach ($record as $column => $value) {
      $column = strtolower($column);
      if ($column === 'doctrine_rownum') {
        continue;
      }
      if (isset($this->dbIdentifiersMap[$column])) {
        $column = $this->dbIdentifiersMap[$column];
      }
      switch ($value) {
        case self::ORACLE_EMPTY_STRING_REPLACEMENT:
          $result[$column] = '';
          break;

        case NULL:
          $result[$column] = NULL;
          break;

        default:
          $result[$column] = (string) $value;

      }
    }
    return $result;
  }

  /**
   * Insert delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public function getSequenceNameForInsert($drupal_table_name) {
    $table_name = $this->connection->getPrefixedTableName($drupal_table_name);
    return "\"{$table_name}_SEQ\"";
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
  public function runInstallTasks(): array {
    $results = [
      'fail' => [],
      'pass' => [],
    ];

    // Install a CONCAT_WS function.
    try {
      $this->getDbalConnection()->exec(<<<PLSQL
CREATE OR REPLACE FUNCTION CONCAT_WS(p_delim IN VARCHAR2
                                    , p_str1 IN VARCHAR2 DEFAULT NULL
                                    , p_str2 IN VARCHAR2 DEFAULT NULL
                                    , p_str3 IN VARCHAR2 DEFAULT NULL
                                    , p_str4 IN VARCHAR2 DEFAULT NULL
                                    , p_str5 IN VARCHAR2 DEFAULT NULL
                                    , p_str6 IN VARCHAR2 DEFAULT NULL
                                    , p_str7 IN VARCHAR2 DEFAULT NULL
                                    , p_str8 IN VARCHAR2 DEFAULT NULL
                                    , p_str9 IN VARCHAR2 DEFAULT NULL
                                    , p_str10 IN VARCHAR2 DEFAULT NULL
                                    , p_str11 IN VARCHAR2 DEFAULT NULL
                                    , p_str12 IN VARCHAR2 DEFAULT NULL
                                    , p_str13 IN VARCHAR2 DEFAULT NULL
                                    , p_str14 IN VARCHAR2 DEFAULT NULL
                                    , p_str15 IN VARCHAR2 DEFAULT NULL
                                    , p_str16 IN VARCHAR2 DEFAULT NULL
                                    , p_str17 IN VARCHAR2 DEFAULT NULL
                                    , p_str18 IN VARCHAR2 DEFAULT NULL
                                    , p_str19 IN VARCHAR2 DEFAULT NULL
                                    , p_str20 IN VARCHAR2 DEFAULT NULL) RETURN VARCHAR2 IS
    TYPE t_str IS VARRAY (20) OF VARCHAR2(4000);
    l_str_list t_str := t_str(p_str1
        , p_str2
        , p_str3
        , p_str4
        , p_str5
        , p_str6
        , p_str7
        , p_str8
        , p_str9
        , p_str10
        , p_str11
        , p_str12
        , p_str13
        , p_str14
        , p_str15
        , p_str16
        , p_str17
        , p_str18
        , p_str19
        , p_str20);
    i          INTEGER;
    l_result   VARCHAR2(4000);
BEGIN
    FOR i IN l_str_list.FIRST .. l_str_list.LAST
        LOOP
            l_result := l_result
                || CASE
                       WHEN l_str_list(i) IS NOT NULL
                           THEN p_delim
                            END
                || CASE
                       WHEN l_str_list(i) = CHR(8)
                           THEN NULL
                       ELSE l_str_list(i)
                            END;
        END LOOP;
    RETURN LTRIM(l_result, p_delim);
END;
PLSQL
      );
    }
    catch (\Exception $e) {
      $results['fail'][] = t("Failed installation of the CONCAT_WS function: " . $e->getMessage());
    }

    // Install a GREATEST function.
    try {
      $this->getDbalConnection()->exec(<<<PLSQL
create or replace function greatest(p1 number, p2 number, p3 number default null)
return number
as
begin
  if p3 is null then
    if p1 > p2 or p2 is null then
     return p1;
    else
     return p2;
    end if;
  else
   return greatest(p1,greatest(p2,p3));
  end if;
end;
PLSQL
      );
    }
    catch (\Exception $e) {
      $results['fail'][] = t("Failed installation of the GREATEST function: " . $e->getMessage());
    }

    // Install a RAND function.
    try {
      $this->getDbalConnection()->exec(<<<PLSQL
create or replace function rand
return number
as
begin
  return dbms_random.random;
end;
PLSQL
      );
    }
    catch (\Exception $e) {
      $results['fail'][] = t("Failed installation of the RAND function: " . $e->getMessage());
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
    // The DBAL Schema manager is quite slow here.
    // Instead, we try to select from the table in question.  If it fails,
    // the most likely reason is that it does not exist.
    try {
      $this->getDbalConnection()->query("SELECT 1 FROM " . $this->connection->getPrefixedTableName($drupal_table_name, TRUE) . " WHERE ROWNUM <= 1");
      $result = TRUE;
    }
    catch (\Exception $e) {
      $result = FALSE;
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateFieldExists(&$result, $drupal_table_name, $field_name) {
    // The DBAL Schema manager is quite slow here.
    // Instead, we try to select from the table and field in question. If it
    // fails, the most likely reason is that it does not exist.
    $db_field = $this->getDbFieldName($field_name, TRUE);
    try {
      $this->getDbalConnection()->query("SELECT $db_field FROM " . $this->connection->getPrefixedTableName($drupal_table_name, TRUE) . " WHERE ROWNUM <= 1");
      $result = TRUE;
    }
    catch (\Exception $e) {
      $result = FALSE;
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateListTableNames() {
    $db_table_names = $this->getDbalConnection()->getSchemaManager()->listTableNames();
    $table_names = [];
    foreach ($db_table_names as $db_table_name) {
      $table_names[] = rtrim(ltrim($db_table_name, '"'), '"');
    }
    return $table_names;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateColumnNameList(array $columns) {
    $column_names = [];
    foreach ($columns as $dbal_column_name) {
      $column_names[] = trim($dbal_column_name, '"');
    }
    return $column_names;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateGetDbalColumnType(&$dbal_type, array $drupal_field_specs) {
    if (isset($drupal_field_specs['oracle_type'])) {
      $dbal_type = $this->getDbalConnection()->getDatabasePlatform()->getDoctrineTypeMapping($drupal_field_specs['oracle_type']);
      return TRUE;
    }
    if (isset($drupal_field_specs['type']) && $drupal_field_specs['type'] === 'blob') {
      $dbal_type = 'text';
      return TRUE;
    }
    // Special case when text field need to be indexed, BLOB field will not
    // be indexeable.
    if ($drupal_field_specs['type'] === 'text') {
      $dbal_type = 'string';
      return TRUE;
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function alterDbalColumnOptions($context, array &$dbal_column_options, $dbal_type, array $drupal_field_specs, $field_name) {
    if (isset($drupal_field_specs['type']) && in_array($drupal_field_specs['type'], ['char', 'varchar', 'varchar_ascii', 'text', 'blob'])) {
      if (array_key_exists('default', $drupal_field_specs)) {
        // Empty string must be replaced as in Oracle that equals to NULL.
        $default = $drupal_field_specs['default'] === '' ? self::ORACLE_EMPTY_STRING_REPLACEMENT : $drupal_field_specs['default'];
        $dbal_column_options['default'] = $default;
      }
    }
    // String field definition may miss the length if it has been altered.
    if ($dbal_type === 'string' && !isset($drupal_field_specs['length'])) {
      $dbal_column_options['length'] = 4000;
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getStringForDefault($string) {
    return $string;
  }

  /**
   * {@inheritdoc}
   */
  public function alterDbalColumnDefinition($context, &$dbal_column_definition, array &$dbal_column_options, $dbal_type, array $drupal_field_specs, $field_name) {
    // @todo just setting 'unsigned' to true does not enforce values >=0 in the
    // field in Oracle, so add a CHECK >= 0 constraint.
    // @todo open a DBAL issue, this is also in SQLite
    if (isset($drupal_field_specs['type']) && in_array($drupal_field_specs['type'], [
      'float', 'numeric', 'serial', 'int',
    ]) && !empty($drupal_field_specs['unsigned']) && (bool) $drupal_field_specs['unsigned'] === TRUE) {
      $dbal_column_definition .= ' CHECK (' . $this->getDbFieldName($field_name) . '>= 0)';
    }

    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateAddField(&$primary_key_processed_by_extension, DbalSchema $dbal_schema, $drupal_table_name, $field_name, array $drupal_field_specs, array $keys_new_specs, array $dbal_column_options) {
    $primary_key_processed_by_extension = TRUE;

    $unquoted_db_table = $this->connection->getPrefixedTableName($drupal_table_name, FALSE);
    $db_table = '"' . $unquoted_db_table . '"';

    $unquoted_db_field = $this->getDbFieldName($field_name, FALSE);
    $db_field = '"' . $unquoted_db_field . '"';

    $dbal_table = $dbal_schema->getTable($unquoted_db_table);
    $has_primary_key = $dbal_table->hasPrimaryKey();
    $dbal_primary_key = $has_primary_key ? $dbal_table->getPrimaryKey() : NULL;

    $drop_primary_key = $has_primary_key && !empty($keys_new_specs['primary key']);
    $db_primary_key_columns = !empty($keys_new_specs['primary key']) ? $this->connection->schema()->dbalGetFieldList($keys_new_specs['primary key']) : [];

    if ($drop_primary_key) {
      $db_pk_constraint = '';
      $this->delegateDropPrimaryKey($primary_key_processed_by_extension, $db_pk_constraint, $dbal_schema, $drupal_table_name);
      $has_primary_key = FALSE;
    }

    $column_definition = $dbal_column_options['columnDefinition'];

    $sql = [];
    $sql[] = "ALTER TABLE $db_table ADD $db_field $column_definition";

    if ($drupal_field_specs['type'] === 'serial') {
      $autoincrement_sql = $this->connection->getDbalPlatform()->getCreateAutoincrementSql($db_field, $db_table);
      // Remove the auto primary key generation, which is the first element in
      // the array.
      array_shift($autoincrement_sql);
      $sql = array_merge($sql, $autoincrement_sql);
    }

    if (!$has_primary_key && $db_primary_key_columns) {
      $db_pk_constraint = $db_pk_constraint ?? $unquoted_db_table . '_PK';
      $sql[] = "ALTER TABLE $db_table ADD CONSTRAINT $db_pk_constraint PRIMARY KEY (" . implode(', ', $db_primary_key_columns) . ")";
    }

    if (isset($drupal_field_specs['description'])) {
      $column_description = $this->connection->getDbalPlatform()->quoteStringLiteral($drupal_field_specs['description']);
      $sql[] = "COMMENT ON COLUMN $db_table.$db_field IS " . $column_description;
    }

    foreach ($sql as $exec) {
      if ($this->getDebugging()) {
        dump($exec);
      }
      $this->getDbalConnection()->exec($exec);
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function initAddedField(string $drupal_table_name, string $drupal_field_name, array $drupal_field_specs): void {
    if (isset($drupal_field_specs['initial_from_field'])) {
      if (isset($drupal_field_specs['initial'])) {
        if (in_array($drupal_field_specs['type'], ['float', 'numeric', 'serial', 'int'])) {
          $expression = "COALESCE([{$drupal_field_specs['initial_from_field']}], {$drupal_field_specs['initial']})";
          $arguments = [];
        }
        else {
          $expression = "COALESCE([{$drupal_field_specs['initial_from_field']}], :default_initial_value)";
          $arguments = [':default_initial_value' => $drupal_field_specs['initial']];
        }
      }
      else {
        $expression = "[{$drupal_field_specs['initial_from_field']}]";
        $arguments = [];
      }
      $this->connection->update($drupal_table_name)
        ->expression($drupal_field_name, $expression, $arguments)
        ->execute();
    }
    elseif (isset($drupal_field_specs['initial'])) {
      $this->connection->update($drupal_table_name)
        ->fields([$drupal_field_name => $drupal_field_specs['initial']])
        ->execute();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delegateChangeField(&$primary_key_processed_by_extension, DbalSchema $dbal_schema, $drupal_table_name, $field_name, $field_new_name, array $drupal_field_new_specs, array $keys_new_specs, array $dbal_column_options) {
    $primary_key_processed_by_extension = TRUE;

    $unquoted_db_table = $this->connection->getPrefixedTableName($drupal_table_name, FALSE);
    $db_table = '"' . $unquoted_db_table . '"';

    $unquoted_db_field = $this->getDbFieldName($field_name, FALSE);
    $db_field = '"' . $unquoted_db_field . '"';

    $unquoted_new_db_field = $this->getDbFieldName($field_new_name, FALSE);
    $new_db_field = '"' . $unquoted_new_db_field . '"';

    $dbal_table = $dbal_schema->getTable($unquoted_db_table);
    $has_primary_key = $dbal_table->hasPrimaryKey();
    $dbal_primary_key = $has_primary_key ? $dbal_table->getPrimaryKey() : NULL;

    $db_primary_key_columns = $dbal_primary_key ? $dbal_primary_key->getColumns() : [];
    $drop_primary_key = $has_primary_key && (!empty($keys_new_specs['primary key']) || in_array($db_field, $db_primary_key_columns));
    if (!empty($keys_new_specs['primary key'])) {
      $db_primary_key_columns = $this->connection->schema()->dbalGetFieldList($keys_new_specs['primary key']);
    }
    elseif ($db_primary_key_columns && $unquoted_new_db_field !== $unquoted_db_field) {
      $key = array_search($db_field, $db_primary_key_columns);
      $db_primary_key_columns[$key] = $new_db_field;
    }

    if ($drop_primary_key) {
      $db_pk_constraint = '';
      $this->delegateDropPrimaryKey($primary_key_processed_by_extension, $db_pk_constraint, $dbal_schema, $drupal_table_name);
      $has_primary_key = FALSE;
    }

    $temp_column = $this->getLimitedIdentifier(str_replace('-', '', 'tmp' . (new Uuid())->generate()));
    $not_null = $drupal_field_new_specs['not null'] ?? FALSE;
    $column_definition = str_replace($db_field, "\"$temp_column\"", $dbal_column_options['columnDefinition']);
    if ($not_null) {
      $column_definition = str_replace("NOT NULL", "NULL", $column_definition);
    }

    $sql = [];
    $sql[] = "ALTER TABLE $db_table ADD \"$temp_column\" $column_definition";
    $sql[] = "UPDATE $db_table SET \"$temp_column\" = $db_field";
    $sql[] = "ALTER TABLE $db_table DROP COLUMN $db_field";
    $sql[] = "ALTER TABLE $db_table RENAME COLUMN \"$temp_column\" TO $new_db_field";
    if ($not_null) {
      $sql[] = "ALTER TABLE $db_table MODIFY $new_db_field NOT NULL";
    }

    if ($drupal_field_new_specs['type'] === 'serial') {
      $autoincrement_sql = $this->connection->getDbalPlatform()->getCreateAutoincrementSql($new_db_field, $db_table);
      // Remove the auto primary key generation, which is the first element in
      // the array.
      array_shift($autoincrement_sql);
      $sql = array_merge($sql, $autoincrement_sql);
    }

    if (!$has_primary_key && $db_primary_key_columns) {
      $db_pk_constraint = $db_pk_constraint ?? $unquoted_db_table . '_PK';
      $sql[] = "ALTER TABLE $db_table ADD CONSTRAINT $db_pk_constraint PRIMARY KEY (" . implode(', ', $db_primary_key_columns) . ")";
    }

    if (isset($drupal_field_new_specs['description'])) {
      $column_description = $this->connection->getDbalPlatform()->quoteStringLiteral($drupal_field_new_specs['description']);
      $sql[] = "COMMENT ON COLUMN $db_table.$new_db_field IS " . $column_description;
    }

    foreach ($sql as $exec) {
      if ($this->getDebugging()) {
        dump($exec);
      }
      $this->getDbalConnection()->exec($exec);
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateIndexExists(&$result, DbalSchema $dbal_schema, $table_full_name, $drupal_table_name, $drupal_index_name) {
    $index_full_name = $this->getDbIndexName('indexExists', $dbal_schema, $drupal_table_name, $drupal_index_name);
    $result = in_array($index_full_name, array_keys($this->getDbalConnection()->getSchemaManager()->listTableIndexes("\"$table_full_name\"")));
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateDropPrimaryKey(bool &$primary_key_dropped_by_extension, string &$primary_key_asset_name, DbalSchema $dbal_schema, string $drupal_table_name): bool {
    $dbal_table = $dbal_schema->getTable($this->connection->getPrefixedTableName($drupal_table_name));
    $db_table = $this->connection->getPrefixedTableName($drupal_table_name, TRUE);
    $unquoted_db_table = $this->connection->getPrefixedTableName($drupal_table_name, FALSE);
    $db_constraint = $this->connection->query(<<<SQL
        SELECT ind.index_name AS name
          FROM all_indexes ind
     LEFT JOIN all_constraints con ON ind.owner = con.owner AND ind.index_name = con.index_name
         WHERE ind.table_name = '$unquoted_db_table' AND con.constraint_type = 'P'
SQL
    )->fetch();
    $primary_key_asset_name = $db_constraint->name;
    $exec = "ALTER TABLE $db_table DROP CONSTRAINT \"$primary_key_asset_name\"";
    if ($this->getDebugging()) {
      dump($exec);
    }
    $this->getDbalConnection()->exec($exec);
    $primary_key_dropped_by_extension = TRUE;
    return TRUE;
  }

}
