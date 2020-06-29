<?php

namespace Drupal\drudbal\Driver\Database\dbal;

use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Database\Query\Insert as QueryInsert;
use Doctrine\DBAL\Exception\LockWaitTimeoutException as DBALLockWaitTimeoutException;

/**
 * DruDbal implementation of \Drupal\Core\Database\Query\Insert.
 *
 * Note: there should not be db platform specific code here. Any tasks that
 * cannot be managed by Doctrine DBAL should be added to extension specific
 * code in Drupal\drudbal\Driver\Database\dbal\DbalExtension\[dbal_driver_name]
 * classes and execution handed over to there.
 */
class Insert extends QueryInsert {

  /**
   * {@inheritdoc}
   */
  public function execute() {
    if (!$this->preExecute()) {
      return NULL;
    }

    $sql = (string) $this;

    // DBAL does not support multiple insert statements. In such case, open a
    // transaction, and process separately each values set.
    if ((count($this->insertValues) > 1 || !empty($this->fromQuery))) {
      // @codingStandardsIgnoreLine
      $trn = $this->connection->startTransaction();
    }

    // Get from extension if a sequence name should be attached to the insert
    // query.
    $this->queryOptions['sequence_name'] = $this->connection->getDbalExtension()->getSequenceNameForInsert($this->table);

    $last_insert_id = NULL;
    if (empty($this->fromQuery)) {
      // Deal with a single INSERT or a bulk INSERT.
      if ($this->insertValues) {
        foreach ($this->insertValues as $insert_values) {
          $max_placeholder = 0;
          $values = [];
          foreach ($insert_values as $value) {
            $values[':db_insert_placeholder_' . $max_placeholder++] = $value;
          }
          try {
            $last_insert_id = $this->doQuery($sql, $values, $this->queryOptions);
          }
          catch (IntegrityConstraintViolationException $e) {
            // Abort the entire insert in case of integrity constraint violation
            // and a transaction is open.
            if ($this->connection->inTransaction()) {
              $this->connection->rollBack();
            }
            throw $e;
          }
        }
      }
      else {
        // If there are no values, then this is a default-only query. We still
        // need to handle that.
        $last_insert_id = $this->doQuery($sql, [], $this->queryOptions);
      }
    }
    else {
      // Deal with a INSERT INTO ... SELECT construct, that DBAL does not
      // support natively. Execute the SELECT subquery and INSERT its rows'
      // values to the target table.
      $rows = $this->fromQuery->execute();
      foreach ($rows as $row) {
        $max_placeholder = 0;
        $values = [];
        foreach ($row as $value) {
          $values[':db_insert_placeholder_' . $max_placeholder++] = $value;
        }
        try {
          $last_insert_id = $this->doQuery($sql, $values, $this->queryOptions);
        }
        catch (IntegrityConstraintViolationException $e) {
          // Abort the entire insert in case of integrity constraint violation
          // and a transaction is open.
          if ($this->connection->inTransaction()) {
            $this->connection->rollBack();
          }
          throw $e;
        }
      }
    }

    // Re-initialize the values array so that we can re-use this query.
    $this->insertValues = [];

    return $last_insert_id;
  }

  /**
   * Wraps a query on the connection object to enable retrying.
   */
  protected function doQuery(string $query, array $args = [], array $options = []) {
    // SQLite can raise "General error: 5 database is locked" errors when too
    // many concurrent operations are attempted on the db. We wait and retry
    // in such circumstance.
    for ($i = 0; $i < 50; $i++) {
      try {
        return $this->connection->query($query, $args, $options);
      }
      catch (DatabaseExceptionWrapper $e) {
        if (!$e->getPrevious() instanceof DBALLockWaitTimeoutException || $i === 99) {
          throw $e;
        }
        usleep(100000);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function __toString() {
    $comments = $this->connection->makeComment($this->comments);
    $dbal_connection = $this->connection->getDbalConnection();
    $dbal_extension = $this->connection->getDbalExtension();

    $sql = '';

    // Use special syntax, if available, for an insert of only default values.
    if (count($this->insertFields) === 0 && empty($this->fromQuery) && $dbal_extension->delegateDefaultsOnlyInsertSql($sql, $this->table)) {
      return $comments . $sql;
    }

    // Use DBAL query builder to prepare the INSERT query. The table name has to
    // be quoted in DBAL.
    $dbal_query = $dbal_connection->createQueryBuilder()->insert($this->connection->getPrefixedTableName($this->table, TRUE));

    // If we're selecting from a SelectQuery, and no fields are specified in
    // select (i.e. we have a SELECT * FROM ...), then we have to fetch the
    // target column names from the table to be INSERTed to, since DBAL does
    // not support 'INSERT INTO ... SELECT * FROM' constructs.
    if (!empty($this->fromQuery) && empty($this->fromQuery->getFields())) {
      $insert_fields = array_keys($dbal_connection->getSchemaManager()->listTableColumns($this->connection->getPrefixedTableName($this->table)));
    }
    else {
      if ($this->connection->getDbalExtension()->getAddDefaultsExplicitlyOnInsert()) {
        foreach ($this->defaultFields as $field) {
          $dbal_query->setValue($dbal_extension->getDbFieldName($field), 'default');
        }
      }
      $insert_fields = $this->insertFields;
    }
    $max_placeholder = 0;

    foreach ($insert_fields as $field) {
      $dbal_query->setValue($dbal_extension->getDbFieldName($field), ':db_insert_placeholder_' . $max_placeholder++);
    }

    return $comments . $dbal_query->getSQL();
  }

}
