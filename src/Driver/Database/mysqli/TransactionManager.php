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
  protected function processRootCommit(): void {
    if (!$this->connection->inTransaction()) {
      $this->setConnectionTransactionState(ClientConnectionTransactionState::Voided);
      $this->processPostTransactionCallbacks();
      return;
    }
    parent::processRootCommit();
  }

  /**
   * {@inheritdoc}
   */
  protected function rollbackClientSavepoint(string $name): bool {
    if (!$this->connection->inTransaction()) {
      $this->resetStack();
      $this->setConnectionTransactionState(ClientConnectionTransactionState::Voided);
      $this->processPostTransactionCallbacks();
      return TRUE;
    }
    return parent::rollbackClientSavepoint($name);
  }

  /**
   * {@inheritdoc}
   */
  protected function releaseClientSavepoint(string $name): bool {
    if (!$this->connection->inTransaction()) {
      $this->resetStack();
      $this->setConnectionTransactionState(ClientConnectionTransactionState::Voided);
      $this->processPostTransactionCallbacks();
      return TRUE;
    }
    return parent::releaseClientSavepoint($name);
  }

  /**
   * {@inheritdoc}
   */
  protected function commitClientTransaction(): bool {
    if (!$this->connection->inTransaction()) {
      $this->setConnectionTransactionState(ClientConnectionTransactionState::Voided);
      $this->processPostTransactionCallbacks();
      return TRUE;
    }
    $clientCommit = $this->connection->getClientConnection()->commit();
    $this->setConnectionTransactionState($clientCommit ?
      ClientConnectionTransactionState::Committed :
      ClientConnectionTransactionState::CommitFailed
    );
    return $clientCommit;
  }

  /**
   * {@inheritdoc}
   */
  protected function rollbackClientTransaction(): bool {
    if (!$this->connection->inTransaction()) {
      $this->setConnectionTransactionState(ClientConnectionTransactionState::Voided);
      $this->processPostTransactionCallbacks();
      trigger_error('Rollback attempted when there is no active transaction. This can cause data integrity issues.', E_USER_WARNING);
    }
    $clientRollback = $this->connection->getClientConnection()->rollback();
    $this->setConnectionTransactionState($clientRollback ?
      ClientConnectionTransactionState::RolledBack :
      ClientConnectionTransactionState::RollbackFailed
    );
    return $clientRollback;
  }

}