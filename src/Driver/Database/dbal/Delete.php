<?php

namespace Drupal\drudbal\Driver\Database\dbal;

use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Database\Query\Delete as QueryDelete;
use Doctrine\DBAL\Exception\LockWaitTimeoutException as DBALLockWaitTimeoutException;

/**
 * DruDbal implementation of \Drupal\Core\Database\Query\Delete.
 *
 * Note: there should not be db platform specific code here. Any tasks that
 * cannot be managed by Doctrine DBAL should be added to extension specific
 * code in Drupal\drudbal\Driver\Database\dbal\DbalExtension\[dbal_driver_name]
 * classes and execution handed over to there.
 */
class Delete extends QueryDelete {

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
    // Need to pass the quoted table name here.
    $this->dbalQuery = $this->connection->getDbalConnection()
      ->createQueryBuilder()
      ->delete($this->connection->getPrefixedTableName($this->table, TRUE));

    // Adds a WHERE clause if necessary.
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
