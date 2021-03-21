<?php

namespace Drupal\drudbal\Driver\Database\dbal;

use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Database\Query\Upsert as QueryUpsert;
use Doctrine\DBAL\Exception\DeadlockException as DBALDeadlockException;

/**
 * DruDbal implementation of \Drupal\Core\Database\Query\Upsert.
 *
 * Note: there should not be db platform specific code here. Any tasks that
 * cannot be managed by Doctrine DBAL should be added to extension specific
 * code in Drupal\drudbal\Driver\Database\dbal\DbalExtension\[dbal_driver_name]
 * classes and execution handed over to there.
 */
class Upsert extends QueryUpsert {

  /**
   * {@inheritdoc}
   */
  public function execute() {
    // DBAL does not support UPSERT. Open a transaction (if supported), and
    // process separate inserts. In case of integrity constraint violation,
    // fall back to an update.
    // @see https://github.com/doctrine/dbal/issues/1320
    // @todo what to do if no transaction support.
    if (!$this->preExecute()) {
      return NULL;
    }

    $sql = (string) $this;

    // Loop through the values to be UPSERTed.
    $last_insert_id = NULL;
    if ($this->insertValues) {
      if ($this->connection->getDbalExtension()->hasNativeUpsert()) {
        // Use native UPSERT.
        $max_placeholder = 0;
        $values = [];
        foreach ($this->insertValues as $insert_values) {
          foreach ($insert_values as $value) {
            $values[':db_insert_placeholder_' . $max_placeholder++] = $value;
          }
        }
        $last_insert_id = $this->connection->query($sql, $values, $this->queryOptions);
      }
      else {
        // Emulated UPSERT.
        // @codingStandardsIgnoreLine
        $trn = $this->connection->startTransaction();

        foreach ($this->insertValues as $insert_values) {
          $max_placeholder = 0;
          $values = [];
          foreach ($insert_values as $value) {
            $values[':db_insert_placeholder_' . $max_placeholder++] = $value;
          }
          try {
            $last_insert_id = $this->connection->query($sql, $values, $this->queryOptions);
          }
          catch (IntegrityConstraintViolationException $e) {
            // Update the record at key in case of integrity constraint
            // violation.
            $this->fallbackUpdate($insert_values);
          }
        }
      }
    }
    else {
      // If there are no values, then this is a default-only query. We still
      // need to handle that.
      try {
        $last_insert_id = $this->connection->query($sql, [], $this->queryOptions);
      }
      catch (IntegrityConstraintViolationException $e) {
        // Update the record at key in case of integrity constraint
        // violation.
        if (!$this->connection->getDbalExtension()->hasNativeUpsert()) {
          $this->fallbackUpdate([]);
        }
      }
    }

    // Re-initialize the values array so that we can re-use this query.
    $this->insertValues = [];

    return $last_insert_id;
  }

  /**
   * {@inheritdoc}
   */
  public function __toString() {
    $dbal_extension = $this->connection->getDbalExtension();

    $comments = $this->connection->makeComment($this->comments);

    // Delegate to DBAL extension.
    if ($dbal_extension->hasNativeUpsert()) {
      $insert_fields = array_merge($this->defaultFields, $this->insertFields);
      $insert_values = $this->getInsertPlaceholderFragment($this->insertValues, $this->defaultFields);
      return $dbal_extension->delegateUpsertSql($this->table, $this->key, $insert_fields, $insert_values, $comments);
    }

    $dbal_connection = $this->connection->getDbalConnection();

    // Use DBAL query builder to prepare an INSERT query. Need to pass the
    // quoted table name here.
    $dbal_query = $dbal_connection->createQueryBuilder()->insert($this->connection->getPrefixedTableName($this->table, TRUE));

    foreach ($this->defaultFields as $field) {
      $dbal_query->setValue($dbal_extension->getDbFieldName($field, TRUE), 'DEFAULT');
    }
    $max_placeholder = 0;
    foreach ($this->insertFields as $field) {
      $dbal_query->setValue($dbal_extension->getDbFieldName($field, TRUE), ':db_insert_placeholder_' . $max_placeholder++);
    }
    return $comments . $dbal_query->getSQL();
  }

  /**
   * Executes an UPDATE when the INSERT fails.
   *
   * @param array $insert_values
   *   The values that failed insert, and that need instead to update the
   *   record identified by the unique key.
   *
   * @return int
   *   The number of records updated (should be 1).
   */
  protected function fallbackUpdate(array $insert_values): int {
    $dbal_connection = $this->connection->getDbalConnection();
    $dbal_extension = $this->connection->getDbalExtension();

    // Use the DBAL query builder for the UPDATE. Need to pass the quoted table
    // name here.
    $dbal_query = $dbal_connection->createQueryBuilder()->update($this->connection->getPrefixedTableName($this->table, TRUE));

    // Set default fields first.
    foreach ($this->defaultFields as $field) {
      $dbal_query->set($dbal_extension->getDbFieldName($field), 'DEFAULT');
    }

    // Set values fields.
    for ($i = 0; $i < count($this->insertFields); $i++) {
      if ($this->insertFields[$i] != $this->key) {
        // Updating the unique / primary key is not necessary.
        $dbal_query
          ->set($dbal_extension->getDbFieldName($this->insertFields[$i], TRUE), ':db_update_placeholder_' . $i)
          ->setParameter('db_update_placeholder_' . $i, $insert_values[$i]);
      }
      else {
        // The unique / primary key is the WHERE condition for the UPDATE.
        $dbal_query
          ->where($dbal_query->expr()->eq($dbal_extension->getDbFieldName($this->insertFields[$i], TRUE), ':db_condition_placeholder_0'))
          ->setParameter('db_condition_placeholder_0', $insert_values[$i]);
      }
    }

    return $dbal_query->execute();
  }

}
