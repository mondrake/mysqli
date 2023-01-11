<?php

namespace Drupal\Tests\mysqli\Kernel\mysqli;

use Drupal\Tests\mysql\Kernel\mysql\DatabaseExceptionWrapperTest as BaseMySqlTest;

/**
 * Tests exceptions thrown by queries.
 *
 * @group Database
 */
class DatabaseExceptionWrapperTest extends BaseMySqlTest {
  /**
   * Tests Connection::prepareStatement exceptions on preparation.
   *
   * Core database drivers use PDO emulated statements or the StatementPrefetch
   * class, which defer the statement check to the moment of the execution. In
   * order to test a failure at preparation time, we have to force the
   * connection not to emulate statement preparation. Still, this is only valid
   * for the MySql driver.
   */
  public function testPrepareStatementFailOnPreparation() {
    $this->markTestSkipped('mysqli is not a pdo driver.');
  }

  /**
   * Tests Connection::prepareStatement exception on execution.
   */
  public function testPrepareStatementFailOnExecution() {
    $this->expectException(\mysqli_sql_exception::class);
    $stmt = $this->connection->prepareStatement('bananas', []);
    $stmt->execute();
  }

}
