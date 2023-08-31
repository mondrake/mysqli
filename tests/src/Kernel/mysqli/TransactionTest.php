<?php

namespace Drupal\Tests\mysqli\Kernel\mysqli;

use Drupal\KernelTests\Core\Database\DriverSpecificTransactionTestBase;
use PHPUnit\Framework\Error\Warning;

/**
 * Tests transaction for the MySQL driver.
 *
 * @group Database
 */
class TransactionTest extends DriverSpecificTransactionTestBase {

  /**
   * Tests the compatibility of transactions with DDL statements.
   */
  public function testTransactionWithDdlStatement() {
    // First, test that a commit works normally, even with DDL statements.
    $transaction = $this->connection->startTransaction();
    $this->insertRow('row');
    $this->executeDDLStatement();
    unset($transaction);
    $this->assertRowPresent('row');

    // Even in different order.
    $this->cleanUp();
    $transaction = $this->connection->startTransaction();
    $this->executeDDLStatement();
    $this->insertRow('row');
    unset($transaction);
    $this->assertRowPresent('row');

    // Even with stacking.
    $this->cleanUp();
    $transaction = $this->connection->startTransaction();
    $transaction2 = $this->connection->startTransaction();
    $this->executeDDLStatement();
    unset($transaction2);
    $transaction3 = $this->connection->startTransaction();
    $this->insertRow('row');
    unset($transaction3);
    unset($transaction);
    $this->assertRowPresent('row');

    // Note: THIS IS DIFFERENT FROM MySQL.
    // A transaction after a DDL statement should still work the same.
    $this->cleanUp();
    $transaction = $this->connection->startTransaction();
    $transaction2 = $this->connection->startTransaction();
    $this->executeDDLStatement();
    unset($transaction2);
    $transaction3 = $this->connection->startTransaction();
    $this->insertRow('row');
    // MySQLi will only cleanup the transaction stack on rollback, because the
    // rollback will fail since no savepoint is any longer present given the
    // auto-commit related to the DDL statement.
    $transaction3->rollBack();
    unset($transaction3);
    unset($transaction);
    $this->assertRowAbsent('row');

    // The behavior of a rollback depends on the type of database server.
    if ($this->connection->supportsTransactionalDDL()) {
      // For database servers that support transactional DDL, a rollback
      // of a transaction including DDL statements should be possible.
      $this->cleanUp();
      $transaction = $this->connection->startTransaction();
      $this->insertRow('row');
      $this->executeDDLStatement();
      $transaction->rollBack();
      unset($transaction);
      $this->assertRowAbsent('row');

      // Including with stacking.
      $this->cleanUp();
      $transaction = $this->connection->startTransaction();
      $transaction2 = $this->connection->startTransaction();
      $this->executeDDLStatement();
      unset($transaction2);
      $transaction3 = $this->connection->startTransaction();
      $this->insertRow('row');
      unset($transaction3);
      $transaction->rollBack();
      unset($transaction);
      $this->assertRowAbsent('row');
    }
    else {
      // For database servers that do not support transactional DDL,
      // the DDL statement should commit the transaction stack.
      $this->cleanUp();
      $transaction = $this->connection->startTransaction();
      $this->insertRow('row');
      $this->executeDDLStatement();

      try {
        // Rollback the outer transaction.
        $transaction->rollBack();
        // Note: Difference from MySQL.
        // MySQLi does not fail when rolling back and no transaction active.
        // $this->fail('Rolling back a transaction containing DDL should produce a warning.');
      }
      catch (Warning $warning) {
        $this->assertSame('Rollback attempted when there is no active transaction. This can cause data integrity issues.', $warning->getMessage());
      }
      unset($transaction);
      $this->assertRowPresent('row');
    }
  }

  /**
   * Tests deprecation of Connection methods.
   *
   * @group legacy
   */
  public function testConnectionDeprecations(): void {
    $this->markTestSkipped('Skipping this test for MySQLi.');
  }

  /**
   * Tests starting a transaction when there's one active on the client.
   *
   * MySQLi does not fail if multiple transactions are begun on the client, so
   * this test is failing. Let's change this when MySQLi will provide a way to
   * check if a client transaction is active.
   */
  public function testStartTransactionWhenActive(): void {
    $this->markTestSkipped('Skipping this while MySQLi cannot detect if a client transaction is active.');
    $this->connection->getClientConnection()->begin_transaction();
    $this->connection->startTransaction();
    $this->assertFalse($this->connection->inTransaction());
  }

  /**
   * Tests committing a transaction when there's none active on the client.
   *
   * MySQLi does not fail if multiple commits are made on the client, so this
   * test is failing. Let's change this when MySQLi will provide a way to check
   * if a client transaction is active.
   */
  public function testCommitTransactionWhenInactive(): void {
    $this->markTestSkipped('Skipping this while MySQLi cannot detect if a client transaction is active.');
    $transaction = $this->connection->startTransaction();
    $this->assertTrue($this->connection->inTransaction());
    $this->connection->getClientConnection()->commit();
    $this->assertFalse($this->connection->inTransaction());
    $transaction = NULL;
  }

}
