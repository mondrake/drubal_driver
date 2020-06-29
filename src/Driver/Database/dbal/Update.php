<?php

namespace Drupal\drudbal\Driver\Database\dbal;

use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\Query\Update as QueryUpdate;
use Doctrine\DBAL\Exception\LockWaitTimeoutException as DBALLockWaitTimeoutException;

/**
 * DruDbal implementation of \Drupal\Core\Database\Query\Update.
 *
 * Note: there should not be db platform specific code here. Any tasks that
 * cannot be managed by Doctrine DBAL should be added to extension specific
 * code in Drupal\drudbal\Driver\Database\dbal\DbalExtension\[dbal_driver_name]
 * classes and execution handed over to there.
 */
class Update extends QueryUpdate {

  /**
   * A DBAL query builder object.
   *
   * @var \Doctrine\DBAL\Query\QueryBuilder
   */
  protected $dbalQuery;

  /**
   * {@inheritdoc}
   */
  public function execute() {
    // SQLite can raise "General error: 5 database is locked" errors when too
    // many concurrent operations are attempted on the db. We wait and retry
    // in such circumstance.
    for ($i = 0; $i < 50; $i++) {
      try {
        return $this->connection->query((string) $this, $this->dbalQuery->getParameters(), $this->queryOptions);
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
    $this->compileDbalQuery();
    return $comments . $this->dbalQuery->getSQL();
  }

  /**
   * Builds the query via DBAL Query Builder.
   */
  protected function compileDbalQuery() {
    $dbal_extension = $this->connection->getDbalExtension();

    // Need to pass the quoted table name here.
    $this->dbalQuery = $this->connection->getDbalConnection()
      ->createQueryBuilder()
      ->update($this->connection->getPrefixedTableName($this->table, TRUE));

    // Expressions take priority over literal fields, so we process those first
    // and remove any literal fields that conflict.
    $fields = $this->fields;
    foreach ($this->expressionFields as $field => $data) {
      // If arguments are set, these are are placeholders and values that need
      // to be passed as parameters to the query.
      if (!empty($data['arguments'])) {
        foreach ($data['arguments'] as $placeholder => $value) {
          $this->dbalQuery->setParameter($placeholder, $value);
        }
      }
      // If the expression is a select subquery, compile it and capture its
      // placeholders and values as parameters for the entire query. Otherwise,
      // just set the field to the expression.
      if ($data['expression'] instanceof SelectInterface) {
        $data['expression']->compile($this->connection, $this);
        $this->dbalQuery->set($dbal_extension->getDbFieldName($field), '(' . $data['expression'] . ')');
        foreach ($data['expression']->arguments() as $placeholder => $value) {
          $this->dbalQuery->setParameter($placeholder, $value);
        }
      }
      else {
        $this->dbalQuery->set($dbal_extension->getDbFieldName($field), $data['expression']);
      }
      unset($fields[$field]);
    }

    // Add fields that have to be updated to a given value.
    $max_placeholder = 0;
    foreach ($fields as $field => $value) {
      $this->dbalQuery
        ->set($dbal_extension->getDbFieldName($field), ':db_update_placeholder_' . ($max_placeholder))
        ->setParameter(':db_update_placeholder_' . ($max_placeholder), $value);
      $max_placeholder++;
    }

    // Adds the WHERE clause.
    // @todo this uses Drupal Condition API. Use DBAL expressions instead?
    if (count($this->condition)) {
      $this->condition->compile($this->connection, $this);
      $this->dbalQuery->where((string) $this->condition);
      foreach ($this->condition->arguments() as $placeholder => $value) {
        $this->dbalQuery->setParameter($placeholder, $value);
      }
    }

    return $this;
  }

}
