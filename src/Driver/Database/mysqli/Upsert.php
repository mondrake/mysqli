<?php

namespace Drupal\mysqli\Driver\Database\mysqli;

use Drupal\mysql\Driver\Database\mysql\Upsert as BaseUpsert;

/**
 * MySQLi implementation of \Drupal\Core\Database\Query\Upsert.
 */
class Upsert extends BaseUpsert {
  public function execute() {
    if (!$this->preExecute()) {
      return NULL;
    }

    $max_placeholder = 0;
    $values = [];
    foreach ($this->insertValues as $insert_values) {
      foreach ($insert_values as $value) {
        $values[':db_insert_placeholder_' . $max_placeholder++] = $value;
      }
    }

    $stmt = $this->connection->prepareStatement((string) $this, $this->queryOptions, TRUE);
    try {
      $stmt->execute($values, $this->queryOptions);
      $affected_rows = $stmt->rowCount();
dump([(string) $this, $values, $this->queryOptions, $affected_rows]);
    }
    catch (\Exception $e) {
      $this->connection->exceptionHandler()->handleExecutionException($e, $stmt, $values, $this->queryOptions);
    }

    // Re-initialize the values array so that we can re-use this query.
    $this->insertValues = [];

    return $affected_rows;
  }
}
