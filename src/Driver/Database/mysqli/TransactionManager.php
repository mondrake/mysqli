<?php

declare(strict_types=1);

namespace Drupal\mysqli\Driver\Database\mysqli;

use Drupal\Core\Database\Transaction\ClientConnectionTransactionState;
use Drupal\Core\Database\Transaction\TransactionManagerBase;

/**
 * MySqli implementation of TransactionManagerInterface.
 */
class TransactionManager extends TransactionManagerBase {

  /**
   * {@inheritdoc}
   */
  protected function beginClientTransaction(): bool {
    return $this->connection->getClientConnection()->begin_transaction();
  }

  /**
   * {@inheritdoc}
   */
  protected function addClientSavepoint(string $name): bool {
    return $this->connection->getClientConnection()->savepoint($name);
  }

  /**
   * {@inheritdoc}
   */
  protected function rollbackClientSavepoint(string $name): bool {
    // Mysqli does not have a rollback_to_savepoint method, and it does not
    // allow a prepared statement for 'ROLLBACK TO SAVEPOINT', so we need to
    // fallback to query on the client connection directly.
    return (bool) $this->connection->getClientConnection()->query('ROLLBACK TO SAVEPOINT ' . $name);
  }

  /**
   * {@inheritdoc}
   */
  protected function releaseClientSavepoint(string $name): bool {
    return $this->connection->getClientConnection()->release_savepoint($name);
  }

  /**
   * {@inheritdoc}
   */
  protected function rollbackClientTransaction(): bool {
    return $this->connection->getClientConnection()->rollback();
  }

  /**
   * {@inheritdoc}
   */
  protected function commitClientTransaction(): bool {
    return $this->connection->getClientConnection()->commit();
  }

}
