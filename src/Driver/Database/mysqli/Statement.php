<?php

namespace Drupal\mysqli\Driver\Database\mysqli;

use Drupal\Core\Database\StatementWrapper;

/**
 * MySQLi implementation of \Drupal\Core\Database\Query\StatementInterface.
 */
class Statement extends StatementWrapper {

  /**
   * Constructs a Statement object.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   Drupal database connection object.
   * @param \mysqli $client_connection
   *   Client database connection object, for example \PDO.
   * @param string $query
   *   The SQL query string.
   * @param array $options
   *   Array of query options.
   * @param bool $row_count_enabled
   *   (optional) Enables counting the rows affected. Defaults to FALSE.
   */
  public function __construct(Connection $connection, \mysqli $client_connection, string $query, array $options, bool $row_count_enabled = FALSE) {
    $this->connection = $connection;
    $this->clientStatement = $client_connection->prepare($query, $options);
    $this->rowCountEnabled = $row_count_enabled;
    $this->setFetchMode(\PDO::FETCH_OBJ);
  }

  /**
   * {@inheritdoc}
   */
  public function execute($args = [], $options = []) {
    if (isset($options['fetch'])) {
      if (is_string($options['fetch'])) {
        // \PDO::FETCH_PROPS_LATE tells __construct() to run before properties
        // are added to the object.
        $this->setFetchMode(\PDO::FETCH_CLASS | \PDO::FETCH_PROPS_LATE, $options['fetch']);
      }
      else {
        $this->setFetchMode($options['fetch']);
      }
    }

    $logger = $this->connection->getLogger();
    if (!empty($logger)) {
      $query_start = microtime(TRUE);
    }

    $return = $this->clientStatement->execute($args);

    if (!empty($logger)) {
      $query_end = microtime(TRUE);
      $logger->log($this, $args, $query_end - $query_start, $query_start);
    }

    return $return;
  }

  /**
   * {@inheritdoc}
   */
  public function getQueryString() {
    return $this->clientStatement->queryString;
  }

  /**
   * {@inheritdoc}
   */
  public function fetchField($index = 0) {
    // Call \PDOStatement::fetchColumn to fetch the field.
    return $this->clientStatement->fetchColumn($index);
  }

  /**
   * {@inheritdoc}
   */
  public function fetchObject(string $class_name = NULL, array $constructor_arguments = NULL) {
    if ($class_name) {
      return $this->clientStatement->fetchObject($class_name, $constructor_arguments);
    }
    return $this->clientStatement->fetchObject();
  }

  /**
   * {@inheritdoc}
   */
  public function rowCount() {
    // SELECT query should not use the method.
    if ($this->rowCountEnabled) {
      return $this->clientStatement->rowCount();
    }
    else {
      throw new RowCountException();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function setFetchMode($mode, $a1 = NULL, $a2 = []) {
    // Call \PDOStatement::setFetchMode to set fetch mode.
    // \PDOStatement is picky about the number of arguments in some cases so we
    // need to be pass the exact number of arguments we where given.
    switch (func_num_args()) {
      case 1:
        return $this->clientStatement->setFetchMode($mode);

      case 2:
        return $this->clientStatement->setFetchMode($mode, $a1);

      case 3:
      default:
        return $this->clientStatement->setFetchMode($mode, $a1, $a2);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function fetch($mode = NULL, $cursor_orientation = NULL, $cursor_offset = NULL) {
    // Call \PDOStatement::fetchAll to fetch all rows.
    // \PDOStatement is picky about the number of arguments in some cases so we
    // need to be pass the exact number of arguments we where given.
    switch (func_num_args()) {
      case 0:
        return $this->clientStatement->fetch();

      case 1:
        return $this->clientStatement->fetch($mode);

      case 2:
        return $this->clientStatement->fetch($mode, $cursor_orientation);

      case 3:
      default:
        return $this->clientStatement->fetch($mode, $cursor_orientation, $cursor_offset);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function fetchAll($mode = NULL, $column_index = NULL, $constructor_arguments = NULL) {
    // Call \PDOStatement::fetchAll to fetch all rows.
    // \PDOStatement is picky about the number of arguments in some cases so we
    // need to be pass the exact number of arguments we where given.
    switch (func_num_args()) {
      case 0:
        return $this->clientStatement->fetchAll();

      case 1:
        return $this->clientStatement->fetchAll($mode);

      case 2:
        return $this->clientStatement->fetchAll($mode, $column_index);

      case 3:
      default:
        return $this->clientStatement->fetchAll($mode, $column_index, $constructor_arguments);
    }
  }

}
