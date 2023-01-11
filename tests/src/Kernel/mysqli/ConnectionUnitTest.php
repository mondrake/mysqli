<?php

namespace Drupal\Tests\mysqli\Kernel\mysqli;

use Drupal\Tests\mysql\Kernel\mysql\ConnectionUnitTest as BaseMySqlTest;

/**
 * MySQL-specific connection unit tests.
 *
 * @group Database
 */
class ConnectionUnitTest extends BaseMySqlTest {

  /**
   * Tests pdo options override.
   */
  public function testConnectionOpen() {
    $this->markTestSkipped('mysqli is not a pdo driver.');
  }

}
