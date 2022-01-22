<?php

namespace Drupal\mysqli\Driver\Database\mysqli;

use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Database\StatementWrapper;

/**
 * MySQLi implementation of \Drupal\Core\Database\Query\StatementInterface.
 */
class Statement extends StatementWrapper {

  /**
   * The mysqli client connection.
   *
   * @var \mysqli
   */
  protected \mysqli $mysqliConnection;

  /**
   * Holds supplementary driver options.
   *
   * @var array
   */
  protected array $driverOpts;

  /**
   * Holds the index position of named parameters.
   *
   * @var array
   */
  protected array $paramsPositions;

  /**
   * The default fetch mode.
   *
   * See http://php.net/manual/pdo.constants.php for the definition of the
   * constants used.
   *
   * @var int
   */
  protected int $defaultFetchMode;

  /**
   * The class to be used for returning row results.
   *
   * Used when fetch mode is \PDO::FETCH_CLASS.
   *
   * @var string
   */
  protected string $fetchClass;

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
   *   (optional) Array of query options.
   * @param bool $row_count_enabled
   *   (optional) Enables counting the rows affected. Defaults to FALSE.
   */
  public function __construct(Connection $connection, \mysqli $client_connection, string $query, array $driver_options = [], bool $row_count_enabled = FALSE) {
    $this->connection = $connection;
    $this->mysqliConnection = $client_connection;
    $this->rowCountEnabled = $row_count_enabled;
    $this->queryString = $query;
    $this->setFetchMode(\PDO::FETCH_OBJ);
    $this->driverOpts = $driver_options;
  }

  /**
   * {@inheritdoc}
   */
  public function execute($args = [], $options = []) {
    // Prepare the lower-level statement if it's not been prepared already.
    if (!$this->clientStatement) {
      // Replace named placeholders with positional ones if needed.
      $this->paramsPositions = array_flip(array_keys($args));
      [$query, $args] = $this->connection->convertNamedPlaceholdersToPositional($this->queryString, $args);
      $this->queryString = $query;

      try {
        $this->clientStatement = $this->mysqliConnection->prepare($this->queryString);
      }
      catch (\mysqli_sql_exception $e) {
        throw new DatabaseExceptionWrapper($e->getMessage(), $e->getCode(), $e);
      }
    }
    else {
      // Transform the $args to positional.
      $tmp = [];
      foreach ($this->paramsPositions as $param => $pos) {
        $tmp[$pos] = $args[$param];
      }
      $args = $tmp;
    }

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
    return (string) $this->clientStatement->fetch_column($index);
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
    $this->defaultFetchMode = $mode;
    if ($mode === \PDO::FETCH_CLASS) {
      $this->fetchClass = $a1;
    }
    return TRUE;
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
