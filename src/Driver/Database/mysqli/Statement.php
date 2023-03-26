<?php

namespace Drupal\mysqli\Driver\Database\mysqli;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Database\Event\StatementExecutionEndEvent;
use Drupal\Core\Database\Event\StatementExecutionStartEvent;
use Drupal\Core\Database\FetchModeTrait;
use Drupal\Core\Database\RowCountException;
use Drupal\Core\Database\StatementWrapperIterator;

/**
 * MySQLi implementation of \Drupal\Core\Database\Query\StatementInterface.
 */
class Statement extends StatementWrapperIterator {

  use FetchModeTrait;

  /**
   * Holds the index position of named parameters.
   */
  protected array $paramsPositions;

  /**
   * The default fetch mode.
   *
   * See http://php.net/manual/pdo.constants.php for the definition of the
   * constants used.
   */
  protected int $defaultFetchMode;

  /**
   * The class to be used for returning row results.
   *
   * Used when fetch mode is \PDO::FETCH_CLASS.
   */
  protected string $fetchClass;

  /**
   * The mysqli result object.
   *
   * Stores results of a data selection query.
   */
  protected ?\mysqli_result $mysqliResult;

  /**
   * Constructs a Statement object.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   Drupal database connection object.
   * @param \mysqli $mysqliConnection
   *   Client database connection object, for example \PDO.
   * @param string $queryString
   *   The SQL query string.
   * @param array $driverOpts
   *   (optional) Array of query options.
   * @param bool $rowCountEnabled
   *   (optional) Enables counting the rows affected. Defaults to FALSE.
   */
  public function __construct(
    protected readonly Connection $connection,
    protected readonly \mysqli $mysqliConnection,
    protected string $queryString,
    protected array $driverOpts = [],
    protected readonly bool $rowCountEnabled = FALSE,
  ) {
    $this->setFetchMode(\PDO::FETCH_OBJ);
  }

  /**
   * {@inheritdoc}
   */
  public function execute($args = [], $options = []) {
    // Prepare the lower-level statement if it's not been prepared already.
    if (!isset($this->clientStatement)) {
      // Replace named placeholders with positional ones if needed.
      $this->paramsPositions = array_flip(array_keys($args));
      [$this->queryString, $args] = $this->connection->convertNamedPlaceholdersToPositional($this->queryString, $args);
      $this->clientStatement = $this->mysqliConnection->prepare($this->queryString);
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
        $this->setFetchMode(\PDO::FETCH_CLASS, $options['fetch']);
      }
      else {
        $this->setFetchMode($options['fetch']);
      }
    }

    if ($this->connection->isEventEnabled(StatementExecutionStartEvent::class)) {
      $startEvent = new StatementExecutionStartEvent(
        spl_object_id($this),
        $this->connection->getKey(),
        $this->connection->getTarget(),
        $this->getQueryString(),
        $args ?? [],
        $this->connection->findCallerFromDebugBacktrace()
      );
      $this->connection->dispatchEvent($startEvent);
    }

    $return = $this->clientStatement->execute($args);
    $this->markResultsetIterable($return);
    $result = $this->clientStatement->get_result();
    $this->mysqliResult = $result !== FALSE ? $result : NULL;

    if (isset($startEvent) && $this->connection->isEventEnabled(StatementExecutionEndEvent::class)) {
      $this->connection->dispatchEvent(new StatementExecutionEndEvent(
        $startEvent->statementObjectId,
        $startEvent->key,
        $startEvent->target,
        $startEvent->queryString,
        $startEvent->args,
        $startEvent->caller,
        $startEvent->time
      ));
    }

    return $return;
  }

  /**
   * {@inheritdoc}
   */
  public function getQueryString() {
    return $this->queryString;
  }

  /**
   * {@inheritdoc}
   */
  public function fetch($mode = NULL, $cursor_orientation = NULL, $cursor_offset = NULL) {
    if (is_string($mode)) {
      $this->setFetchMode(\PDO::FETCH_CLASS, $mode);
      $mode = \PDO::FETCH_CLASS;
    }
    else {
      $mode = $mode ?: $this->defaultFetchMode;
    }

    $mysqli_row = $this->mysqliResult->fetch_assoc();

    if (!$mysqli_row) {
      $this->markResultsetFetchingComplete();
      return FALSE;
    }

    // Stringify all non-NULL column values.
    $row = [];
    foreach ($mysqli_row as $column => $value) {
      $row[$column] = $value === NULL ? NULL : (string) $value;
    }

    $ret = match($mode) {
      \PDO::FETCH_ASSOC => $row,
      \PDO::FETCH_BOTH => $this->assocToBoth($row),
      \PDO::FETCH_NUM => $this->assocToNum($row),
      \PDO::FETCH_LAZY, \PDO::FETCH_OBJ => $this->assocToObj($row),
      \PDO::FETCH_CLASS | \PDO::FETCH_CLASSTYPE => $this->assocToClassType($row, $this->fetchOptions['constructor_args']),
      \PDO::FETCH_CLASS => $this->assocToClass($row, $this->fetchClass, $this->fetchOptions['constructor_args']),
      \PDO::FETCH_INTO => $this->assocIntoObject($row, $this->fetchOptions['object']),
      \PDO::FETCH_COLUMN => $this->assocToColumn($row, $this->columnNames, $this->fetchOptions['column']),
      default => throw new DatabaseExceptionWrapper("Unknown fetch type '{$mode}'"),
    };

    $this->setResultsetCurrentRow($ret);
    return $ret;
  }

  /**
   * {@inheritdoc}
   */
  public function fetchAll($mode = NULL, $column_index = NULL, $constructor_arguments = NULL) {
    if (is_string($mode)) {
      $this->setFetchMode(\PDO::FETCH_CLASS, $mode);
      $mode = \PDO::FETCH_CLASS;
    }
    else {
      $mode = $mode ?: $this->defaultFetchMode;
    }

    $rows = [];
    if (\PDO::FETCH_COLUMN == $mode) {
      if ($column_index === NULL) {
        $column_index = 0;
      }
      while (($record = $this->fetch(\PDO::FETCH_ASSOC)) !== FALSE) {
        $cols = array_keys($record);
        $rows[] = $record[$cols[$column_index]];
      }
    }
    else {
      while (($row = $this->fetch($mode)) !== FALSE) {
        $rows[] = $row;
      }
    }

    return $rows;
  }

  /**
   * {@inheritdoc}
   */
  public function fetchAllAssoc($key, $fetch = NULL) {
    $return = [];
    if (isset($fetch)) {
      if (is_string($fetch)) {
        $this->setFetchMode(\PDO::FETCH_CLASS, $fetch);
      }
      else {
        $this->setFetchMode($fetch ?: $this->defaultFetchMode);
      }
    }

    while ($record = $this->fetch()) {
      $record_key = is_object($record) ? $record->$key : $record[$key];
      $return[$record_key] = $record;
    }

    return $return;
  }

  /**
   * {@inheritdoc}
   */
  public function fetchAllKeyed($key_index = 0, $value_index = 1) {
    $return = [];
    $this->setFetchMode(\PDO::FETCH_ASSOC);
    while ($record = $this->fetch(\PDO::FETCH_ASSOC)) {
      $cols = array_keys($record);
      $return[$record[$cols[$key_index]]] = $record[$cols[$value_index]];
    }
    return $return;
  }

  /**
   * {@inheritdoc}
   */
  public function fetchField($index = 0) {
    if (($ret = $this->fetch(\PDO::FETCH_NUM)) === FALSE) {
      return FALSE;
    }
    return $ret[$index] === NULL ? NULL : (string) $ret[$index];
  }

  /**
   * {@inheritdoc}
   */
  public function fetchObject(string $class_name = NULL, array $constructor_arguments = NULL) {
    if (isset($class_name)) {
      $this->fetchStyle = \PDO::FETCH_CLASS;
      $this->fetchOptions = [
        'class' => $class_name,
        'constructor_args' => $constructor_arguments,
      ];
    }
    return $this->fetch($class_name ?? \PDO::FETCH_OBJ);
  }

  /**
   * {@inheritdoc}
   */
  public function rowCount() {
    // SELECT query should not use this method.
    if ($this->rowCountEnabled) {
      // @todo The most accurate value to return for Drupal here is the first
      //   occurence of an integer in the string stored by the connection's
      //   $info property.
      //   This is something like 'Rows matched: 1  Changed: 1  Warnings: 0' for
      //   UPDATE or DELETE operations, and
      //   'Records: 2  Duplicates: 1  Warnings: 0' for INSERT ones.
      //   This however requires a regex parsing of the string which is
      //   expensive; $affected_rows would be less accurate but much faster. We
      //   would need Drupal to be less strict in testing, and never rely on
      //   this value in runtime (which would be healthy anyway).
      if ($this->mysqliConnection->info !== NULL) {
        $matches = [];
        if (preg_match('/\s(\d+)\s/', $this->mysqliConnection->info, $matches) === 1) {
          return (int) $matches[0];
        }
        else {
          throw new DatabaseExceptionWrapper('Invalid data in the $info property of the mysqli connection - ' . $this->mysqliConnection->info);
        }
      }
      elseif ($this->mysqliConnection->affected_rows !== NULL) {
        return $this->mysqliConnection->affected_rows;
      }
      throw new DatabaseExceptionWrapper('Unable to retrieve affected rows data');
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

}
