<?php

namespace Drupal\Tests\mysqli\Kernel;

use Drupal\KernelTests\Core\Database\SqlModeTest as SqlModeTestBase;

/**
 * Tests compatibility of the MySQLi driver with various sql_mode options.
 *
 * @group Database
 */
class SqlModeTest extends SqlModeTestBase {

  /**
   * {@inheritdoc}
   */
  protected function getDatabaseConnectionInfo() {
    $info = parent::getDatabaseConnectionInfo();

    // This runs during setUp(), so is not yet skipped for non MySQL databases.
    // We defer skipping the test to later in setUp(), so that that can be
    // based on databaseType() rather than 'driver', but here all we have to go
    // on is 'driver'.
    if ($info['default']['driver'] === 'mysqli') {
      $info['default']['init_commands']['sql_mode'] = "SET sql_mode = ''";
    }

    return $info;
  }

}
