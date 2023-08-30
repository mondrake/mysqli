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

    // A transaction after a DDL statement should still work the same.
    $this->cleanUp();
    $transaction = $this->connection->startTransaction();
    $transaction2 = $this->connection->startTransaction();
    $this->executeDDLStatement();
    unset($transaction2);
    $transaction3 = $this->connection->startTransaction();
    $this->insertRow('row');
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
        // @see \Drupal\mysql\Driver\Database\mysql\Connection::rollBack()
// @todo mysqli does not fail when rolling back and no transaction active.
//        $this->fail('Rolling back a transaction containing DDL should produce a warning.');
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
    $this->markTestSkipped('Skipping this for mysqli');
  }

}
