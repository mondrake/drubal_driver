<?php

namespace Drupal\Driver\Database\dbal;

use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Database\RowCountException;
use Drupal\Driver\Database\dbal\Connection as DruDbalConnection;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\SQLParserUtils;

// @todo DBAL 2.6.0:
// provides PDO::FETCH_OBJ emulation for mysqli and oci8 statements, check;
// Normalize method signatures for `fetch()` and `fetchAll()`, ensuring compatibility with the `PDOStatement` signature
// `ResultStatement#fetchAll()` must define 3 arguments in order to be compatible with `PDOStatement#fetchAll()`

/**
 * DruDbal implementation of \Drupal\Core\Database\Statement.
 *
 * Note: there should not be db platform specific code here. Any tasks that
 * cannot be managed by Doctrine DBAL should be added to extension specific
 * code in Drupal\Driver\Database\dbal\DbalExtension\[dbal_driver_name]
 * classes and execution handed over to there.
 */
class Statement implements \IteratorAggregate, StatementInterface {

  /**
   * Reference to the database connection object for this statement.
   *
   * The name $dbh is inherited from \PDOStatement.
   *
   * @var \Drupal\Driver\Database\dbal\Connection
   */
  public $dbh;

  /**
   * Is rowCount() execution allowed.
   *
   * @var bool
   */
  public $allowRowCount = FALSE;

  /**
   * The DBAL statement.
   *
   * @var \Doctrine\DBAL\Statement
   */
  protected $dbalStatement;

  /**
   * The default fetch mode.
   *
   * See http://php.net/manual/pdo.constants.php for the definition of the
   * constants used.
   *
   * @var int
   */
  protected $defaultFetchMode;

  /**
   * The query string, in its form with placeholders.
   *
   * @var string
   */
  protected $queryString;

  /**
   * The class to be used for returning row results.
   *
   * Used when fetch mode is \PDO::FETCH_CLASS.
   *
   * @var string
   */
  protected $fetchClass;

  /**
   * Constructs a Statement object.
   *
   * @param \Drupal\Driver\Database\dbal\Connection $dbh
   *   The database connection object for this statement.
   * @param string $statement
   *   A string containing an SQL query. Passed by reference.
   * @param array $params
   *   (optional) An array of values to substitute into the query at placeholder
   *   markers. Passed by reference.
   * @param array $driver_options
   *   (optional) An array of driver options for this query.
   */
  public function __construct(DruDbalConnection $dbh, &$statement, array &$params, array $driver_options = []) {
    $this->queryString = $statement;
    $this->dbh = $dbh;
    $this->setFetchMode(\PDO::FETCH_OBJ);

    // Replace named placeholders with positional ones if needed.
    if (!$this->dbh->getDbalExtension()->delegateNamedPlaceholdersSupport()) {
      list($statement, $params) = SQLParserUtils::expandListParameters($statement, $params, []);
    }

    try {
      $this->dbh->getDbalExtension()->alterStatement($statement, $params);
if ($this->dbh->getDbalExtension()->getDebugging() && strpos($statement, 'cache_config') !== FALSE) {
//  drupal_set_message(var_export([$statement, $params, $this->formatBacktrace(debug_backtrace())], TRUE));
  drupal_set_message(var_export([$statement, $params], TRUE));
}
      $this->dbalStatement = $dbh->getDbalConnection()->prepare($statement);
    }
    catch (DBALException $e) {
      throw new DatabaseExceptionWrapper($e->getMessage(), $e->getCode(), $e);
    }
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
   * {@inheritdoc}
   */
  public function execute($args = [], $options = []) {
    // Replace named placeholders with positional ones if needed.
    if (!$this->dbh->getDbalExtension()->delegateNamedPlaceholdersSupport()) {
      list(, $args) = SQLParserUtils::expandListParameters($this->queryString, $args, []);
    }

    if (isset($options['fetch'])) {
      if (is_string($options['fetch'])) {
        $this->setFetchMode(\PDO::FETCH_CLASS, $options['fetch']);
      }
      else {
        $this->setFetchMode($options['fetch']);
      }
    }

    $logger = $this->dbh->getLogger();
    if (!empty($logger)) {
      $query_start = microtime(TRUE);
    }

    $this->dbalStatement->execute($args);

    if (!empty($logger)) {
      $query_end = microtime(TRUE);
      $logger->log($this, $args, $query_end - $query_start);
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function fetch($mode = NULL, $cursor_orientation = NULL, $cursor_offset = NULL) {
    if (is_string($mode)) {
      $this->setFetchMode(\PDO::FETCH_CLASS, $mode);
      $mode = \PDO::FETCH_CLASS;
    }
    else {
      $mode = $mode ?: $this->defaultFetchMode;
    }

    return $this->dbh->getDbalExtension()->delegateFetch($this->dbalStatement, $mode, $this->fetchClass);
  }

  /**
   * {@inheritdoc}
   */
  public function fetchAll($mode = NULL, $column_index = NULL, $constructor_arguments = NULL) {
    if (is_string($mode)) {
      $this->setFetchMode(\PDO::FETCH_CLASS, $mode);
      $mode = \PDO::FETCH_CLASS;
    }
    else {
      $mode = $mode ?: $this->defaultFetchMode;
    }

    $rows = [];
    if (\PDO::FETCH_COLUMN == $mode) {
      if ($column_index === NULL) {
        $column_index = 0;
      }
      while (($record = $this->fetch(\PDO::FETCH_ASSOC)) !== FALSE) {
        $cols = array_keys($record);
        $rows[] = $record[$cols[$column_index]];
      }
    }
    else {
      while (($row = $this->fetch($mode)) !== FALSE) {
        $rows[] = $row;
      }
    }

    return $rows;
  }

  /**
   * {@inheritdoc}
   */
  public function getIterator() {
    return new \ArrayIterator($this->fetchAll());
  }

  /**
   * {@inheritdoc}
   */
  public function getQueryString() {
    return $this->queryString;
  }

  /**
   * {@inheritdoc}
   */
  public function fetchCol($index = 0) {
    $ret = $this->fetchAll(\PDO::FETCH_COLUMN, $index);
    return $ret;
  }

  /**
   * {@inheritdoc}
   */
  public function fetchAllAssoc($key, $fetch = NULL) {
    $return = [];
    if (isset($fetch)) {
      if (is_string($fetch)) {
        $this->setFetchMode(\PDO::FETCH_CLASS, $fetch);
      }
      else {
        $this->setFetchMode($fetch ?: $this->defaultFetchMode);
      }
    }

    while ($record = $this->fetch()) {
      $record_key = is_object($record) ? $record->$key : $record[$key];
      $return[$record_key] = $record;
    }

    return $return;
  }

  /**
   * {@inheritdoc}
   */
  public function fetchAllKeyed($key_index = 0, $value_index = 1) {
    $return = [];
    $this->setFetchMode(\PDO::FETCH_ASSOC);
    while ($record = $this->fetch(\PDO::FETCH_ASSOC)) {
      $cols = array_keys($record);
      $return[$record[$cols[$key_index]]] = $record[$cols[$value_index]];
    }
    return $return;
  }

  /**
   * {@inheritdoc}
   */
  public function fetchField($index = 0) {
    $record = $this->fetch(\PDO::FETCH_ASSOC);
    if (!$record) {
      return FALSE;
    }
    $cols = array_keys($record);
    $ret = $record[$cols[$index]];
    return empty($ret) ? NULL : (string) $ret;
  }

  /**
   * {@inheritdoc}
   */
  public function fetchAssoc() {
    return $this->fetch(\PDO::FETCH_ASSOC);
  }

  /**
   * {@inheritdoc}
   */
  public function fetchObject() {
    return $this->fetch(\PDO::FETCH_OBJ);
  }

  /**
   * {@inheritdoc}
   */
  public function rowCount() {
    // SELECT query should not use the method.
    if ($this->allowRowCount) {
      return $this->dbh->getDbalExtension()->delegateRowCount($this->dbalStatement);
    }
    else {
      throw new RowCountException();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function setFetchMode($mode, $a1 = NULL, $a2 = []) {
    $this->defaultFetchMode = $mode;
    if ($mode === \PDO::FETCH_CLASS) {
      $this->fetchClass = $a1;
    }
    return TRUE;
  }

}
