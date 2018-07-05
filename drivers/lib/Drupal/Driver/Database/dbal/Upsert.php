<?php

namespace Drupal\Driver\Database\dbal;

use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Database\Query\Upsert as QueryUpsert;

/**
 * DruDbal implementation of \Drupal\Core\Database\Query\Upsert.
 *
 * Note: there should not be db platform specific code here. Any tasks that
 * cannot be managed by Doctrine DBAL should be added to extension specific
 * code in Drupal\Driver\Database\dbal\DbalExtension\[dbal_driver_name]
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

    if ($this->connection->supportsTransactions()) {
      // @codingStandardsIgnoreLine
      $trn = $this->connection->startTransaction();
    }

    // Loop through the values to be UPSERTed.
    $last_insert_id = NULL;
    if ($this->insertValues) {
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
    else {
      // If there are no values, then this is a default-only query. We still
      // need to handle that.
      try {
        $last_insert_id = $this->connection->query($sql, [], $this->queryOptions);
      }
      catch (IntegrityConstraintViolationException $e) {
        // Update the record at key in case of integrity constraint
        // violation.
        $this->fallbackUpdate([]);
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
    $dbal_connection = $this->connection->getDbalConnection();
    $prefixed_table = $this->connection->getPrefixedTableName($this->table);

    // Use DBAL query builder to prepare an INSERT query.
    $dbal_query = $dbal_connection->createQueryBuilder()->insert($prefixed_table);

    foreach ($this->defaultFields as $field) {
      $dbal_query->setValue($dbal_extension->getDbFieldName($field), 'DEFAULT');
    }
    $max_placeholder = 0;
    foreach ($this->insertFields as $field) {
      $dbal_query->setValue($dbal_extension->getDbFieldName($field), ':db_insert_placeholder_' . $max_placeholder++);
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
  protected function fallbackUpdate(array $insert_values) {
    $dbal_connection = $this->connection->getDbalConnection();
    $dbal_extension = $this->connection->getDbalExtension();

    $prefixed_table = $this->connection->getPrefixedTableName($this->table);

    // Use the DBAL query builder for the UPDATE.
    $dbal_query = $dbal_connection->createQueryBuilder()->update($prefixed_table);

    // Set default fields first.
    foreach ($this->defaultFields as $field) {
      $dbal_query->set($dbal_extension->getDbFieldName($field), 'DEFAULT');
    }

    // Set values fields.
    for ($i = 0; $i < count($this->insertFields); $i++) {
      if ($this->insertFields[$i] != $this->key) {
        // Updating the unique / primary key is not necessary.
        $dbal_query
          ->set($dbal_extension->getDbFieldName($this->insertFields[$i]), ':db_update_placeholder_' . $i)
          ->setParameter(':db_update_placeholder_' . $i, $insert_values[$i]);
      }
      else {
        // The unique / primary key is the WHERE condition for the UPDATE.
        $dbal_query
          ->where($dbal_query->expr()->eq($this->insertFields[$i], ':db_condition_placeholder_0'))
          ->setParameter(':db_condition_placeholder_0', $insert_values[$i]);
      }
    }

    // Execute the DBAL query directly.
    // @todo note this drops support for comments.
    return $dbal_query->execute();
  }

}
