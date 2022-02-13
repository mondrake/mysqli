<?php

namespace Drupal\KernelTests\Core\Database;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Database\TransactionOutOfOrderException;
use Drupal\Core\Database\TransactionNoActiveException;
use PHPUnit\Framework\Error\Warning;
use Drupal\KernelTests\Core\Database\DatabaseTestBase;

/**
 * Tests the transaction abstraction system.
 *
 * We test nesting by having two transaction layers, an outer and inner. The
 * outer layer encapsulates the inner layer. Our transaction nesting abstraction
 * should allow the outer layer function to call any function it wants,
 * especially the inner layer that starts its own transaction, and be
 * confident that, when the function it calls returns, its own transaction
 * is still "alive."
 *
 * Call structure:
 *   transactionOuterLayer()
 *     Start transaction
 *     transactionInnerLayer()
 *       Start transaction (does nothing in database)
 *       [Maybe decide to roll back]
 *     Do more stuff
 *     Should still be in transaction A
 *
 * @group Database
 */
class MysqliTransactionTest extends DatabaseTestBase {

  /**
   * Tests the compatibility of transactions with DDL statements.
   */
  public function testTransactionWithDdlStatement() {
    // First, test that a commit works normally, even with DDL statements.
dump('****** ONE');
    $transaction = $this->connection->startTransaction();
    $this->insertRow('row');
    $this->executeDDLStatement();
    unset($transaction);
    $this->assertRowPresent('row');

    // Even in different order.
dump('****** TWO');
    $this->cleanUp();
    $transaction = $this->connection->startTransaction();
    $this->executeDDLStatement();
    $this->insertRow('row');
    unset($transaction);
    $this->assertRowPresent('row');

    // Even with stacking.
dump('****** THREE');
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
dump('****** FOUR');
    $this->cleanUp();
    $transaction = $this->connection->startTransaction();
    $transaction2 = $this->connection->startTransaction();
    $this->executeDDLStatement();
dump('commit savepoint_1');
    unset($transaction2);
    $transaction3 = $this->connection->startTransaction();
dump('insert');
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
dump('****** FIVE');
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
        $this->fail('Rolling back a transaction containing DDL should produce a warning.');
      }
      catch (Warning $warning) {
        $this->assertSame('Rollback attempted when there is no active transaction. This can cause data integrity issues.', $warning->getMessage());
      }
      unset($transaction);
      $this->assertRowPresent('row');
    }
  }

  /**
   * Inserts a single row into the testing table.
   */
  protected function insertRow($name) {
    $this->connection->insert('test')
      ->fields([
        'name' => $name,
      ])
      ->execute();
  }

  /**
   * Executes a DDL statement.
   */
  protected function executeDDLStatement() {
    static $count = 0;
    $table = [
      'fields' => [
        'id' => [
          'type' => 'serial',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
      ],
      'primary key' => ['id'],
    ];
    $this->connection->schema()->createTable('database_test_' . ++$count, $table);
  }

  /**
   * Starts over for a new test.
   */
  protected function cleanUp() {
    $this->connection->truncate('test')
      ->execute();
  }

  /**
   * Asserts that a given row is present in the test table.
   *
   * @param string $name
   *   The name of the row.
   * @param string $message
   *   The message to log for the assertion.
   *
   * @internal
   */
  public function assertRowPresent(string $name, string $message = NULL): void {
    if (!isset($message)) {
      $message = new FormattableMarkup('Row %name is present.', ['%name' => $name]);
    }
    $present = (boolean) $this->connection->query('SELECT 1 FROM {test} WHERE [name] = :name', [':name' => $name])->fetchField();
    $this->assertTrue($present, $message);
  }

  /**
   * Asserts that a given row is absent from the test table.
   *
   * @param string $name
   *   The name of the row.
   * @param string $message
   *   The message to log for the assertion.
   *
   * @internal
   */
  public function assertRowAbsent(string $name, string $message = NULL): void {
    if (!isset($message)) {
      $message = new FormattableMarkup('Row %name is absent.', ['%name' => $name]);
    }
    $present = (boolean) $this->connection->query('SELECT 1 FROM {test} WHERE [name] = :name', [':name' => $name])->fetchField();
    $this->assertFalse($present, $message);
  }

  /**
   * Tests transaction stacking, commit, and rollback.
   */
/*  public function testTransactionStacking() {
    // Standard case: pop the inner transaction before the outer transaction.
    $transaction = $this->connection->startTransaction();
    $this->insertRow('outer');
    $transaction2 = $this->connection->startTransaction();
    $this->insertRow('inner');
    // Pop the inner transaction.
    unset($transaction2);
    $this->assertTrue($this->connection->inTransaction(), 'Still in a transaction after popping the inner transaction');
    // Pop the outer transaction.
    unset($transaction);
    $this->assertFalse($this->connection->inTransaction(), 'Transaction closed after popping the outer transaction');
    $this->assertRowPresent('outer');
    $this->assertRowPresent('inner');

    // Pop the transaction in a different order they have been pushed.
    $this->cleanUp();
    $transaction = $this->connection->startTransaction();
    $this->insertRow('outer');
    $transaction2 = $this->connection->startTransaction();
    $this->insertRow('inner');
    // Pop the outer transaction, nothing should happen.
    unset($transaction);
    $this->insertRow('inner-after-outer-commit');
    $this->assertTrue($this->connection->inTransaction(), 'Still in a transaction after popping the outer transaction');
    // Pop the inner transaction, the whole transaction should commit.
    unset($transaction2);
    $this->assertFalse($this->connection->inTransaction(), 'Transaction closed after popping the inner transaction');
    $this->assertRowPresent('outer');
    $this->assertRowPresent('inner');
    $this->assertRowPresent('inner-after-outer-commit');

    // Rollback the inner transaction.
    $this->cleanUp();
    $transaction = $this->connection->startTransaction();
    $this->insertRow('outer');
    $transaction2 = $this->connection->startTransaction();
    $this->insertRow('inner');
    // Now rollback the inner transaction.
    $transaction2->rollBack();
    unset($transaction2);
    $this->assertTrue($this->connection->inTransaction(), 'Still in a transaction after popping the outer transaction');
    // Pop the outer transaction, it should commit.
    $this->insertRow('outer-after-inner-rollback');
    unset($transaction);
    $this->assertFalse($this->connection->inTransaction(), 'Transaction closed after popping the inner transaction');
    $this->assertRowPresent('outer');
    $this->assertRowAbsent('inner');
    $this->assertRowPresent('outer-after-inner-rollback');

    // Rollback the inner transaction after committing the outer one.
    $this->cleanUp();
    $transaction = $this->connection->startTransaction();
    $this->insertRow('outer');
    $transaction2 = $this->connection->startTransaction();
    $this->insertRow('inner');
    // Pop the outer transaction, nothing should happen.
    unset($transaction);
    $this->assertTrue($this->connection->inTransaction(), 'Still in a transaction after popping the outer transaction');
    // Now rollback the inner transaction, it should rollback.
    $transaction2->rollBack();
    unset($transaction2);
    $this->assertFalse($this->connection->inTransaction(), 'Transaction closed after popping the inner transaction');
    $this->assertRowPresent('outer');
    $this->assertRowAbsent('inner');

    // Rollback the outer transaction while the inner transaction is active.
    // In that case, an exception will be triggered because we cannot
    // ensure that the final result will have any meaning.
    $this->cleanUp();
    $transaction = $this->connection->startTransaction();
    $this->insertRow('outer');
    $transaction2 = $this->connection->startTransaction();
    $this->insertRow('inner');
    $transaction3 = $this->connection->startTransaction();
    $this->insertRow('inner2');
    // Rollback the outer transaction.
    try {
      $transaction->rollBack();
      unset($transaction);
      $this->fail('Rolling back the outer transaction while the inner transaction is active resulted in an exception.');
    }
    catch (TransactionOutOfOrderException $e) {
      // Expected exception; just continue testing.
    }
    $this->assertFalse($this->connection->inTransaction(), 'No more in a transaction after rolling back the outer transaction');
    // Try to commit one inner transaction.
    unset($transaction3);

    // Try to rollback one inner transaction.
    try {
      $transaction->rollBack();
      unset($transaction2);
      $this->fail('Trying to commit an inner transaction resulted in an exception.');
    }
    catch (TransactionNoActiveException $e) {
      // Expected exception; just continue testing.
    }
    $this->assertRowAbsent('outer');
    $this->assertRowAbsent('inner');
    $this->assertRowAbsent('inner2');
  }
*/

}
