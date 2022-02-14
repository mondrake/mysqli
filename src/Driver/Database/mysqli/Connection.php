<?php

namespace Drupal\mysqli\Driver\Database\mysqli;

use Drupal\Core\Database\Connection as BaseConnection;
use Drupal\Core\Database\Database;
use Drupal\Core\Database\TransactionNameNonUniqueException;
use Drupal\Core\Database\TransactionNoActiveException;
use Drupal\Core\Database\TransactionOutOfOrderException;
use Drupal\mysql\Driver\Database\mysql\Connection as BaseMySqlConnection;
use Drupal\mysqli\Driver\Database\mysqli\Parser\Parser;
use Drupal\mysqli\Driver\Database\mysqli\Parser\Visitor;
/**
 * MySQLi implementation of \Drupal\Core\Database\Connection.
 */
class Connection extends BaseMySqlConnection {

  /**
   * The SQL parser.
   *
   * @todo
   */
  protected Parser $parser;

  /**
   * {@inheritdoc}
   */
  protected $statementWrapperClass = Statement::class;

  /**
   * Stores the server version after it has been retrieved from the database.
   *
   * @var string
   */
  private string $serverVersion;

  /**
   * {@inheritdoc}
   */
  public function __construct(\mysqli $connection, array $connection_options) {
    // If the SQL mode doesn't include 'ANSI_QUOTES' (explicitly or via a
    // combination mode), then MySQL doesn't interpret a double quote as an
    // identifier quote, in which case use the non-ANSI-standard backtick.
    //
    // Because we still support MySQL 5.7, check for the deprecated combination
    // modes as well.
    //
    // @see https://dev.mysql.com/doc/refman/5.7/en/sql-mode.html#sqlmode_ansi_quotes
    $ansi_quotes_modes = ['ANSI_QUOTES', 'ANSI', 'DB2', 'MAXDB', 'MSSQL', 'ORACLE', 'POSTGRESQL'];
    $is_ansi_quotes_mode = FALSE;
    foreach ($ansi_quotes_modes as $mode) {
      // None of the modes in $ansi_quotes_modes are substrings of other modes
      // that are not in $ansi_quotes_modes, so a simple stripos() does not
      // return false positives.
      if (stripos($connection_options['init_commands']['sql_mode'], $mode) !== FALSE) {
        $is_ansi_quotes_mode = TRUE;
        break;
      }
    }
    if ($this->identifierQuotes === ['"', '"'] && !$is_ansi_quotes_mode) {
      $this->identifierQuotes = ['`', '`'];
    }

    // Manage the table prefix.
    $connection_options['prefix'] = $connection_options['prefix'] ?? '';
    $this->setPrefix($connection_options['prefix']);

    // Work out the database driver namespace if none is provided. This normally
    // written to setting.php by installer or set by
    // \Drupal\Core\Database\Database::parseConnectionInfo().
    if (empty($connection_options['namespace'])) {
      $connection_options['namespace'] = (new \ReflectionObject($this))->getNamespaceName();
    }

    $this->connection = $connection;
    $this->connectionOptions = $connection_options;
  }

  /**
   * {@inheritdoc}
   */
  public static function open(array &$connection_options = []) {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    if (isset($connection_options['_dsn_utf8_fallback']) && $connection_options['_dsn_utf8_fallback'] === TRUE) {
      // Only used during the installer version check, as a fallback from utf8mb4.
      $charset = 'utf8';
    }
    else {
      $charset = 'utf8mb4';
    }

    // Allow PDO options to be overridden.
    $connection_options += [
      'pdo' => [],
    ];

    try {
      $mysqli = new \mysqli(
        $connection_options['host'],
        $connection_options['username'],
        $connection_options['password'],
        $connection_options['database'] ?? '',
        empty($connection_options['port']) ? 3306 : (int) $connection_options['port'],
        $connection_options['unix_socket'] ?? ''
      );
      if (!$mysqli->set_charset($charset)) {
        throw new InvalidCharsetException('Invalid charset ' . $charset);
      }
    }
    catch (\mysqli_sql_exception $e) {
      if ($e->getCode() == static::DATABASE_NOT_FOUND) {
        throw new DatabaseNotFoundException($e->getMessage(), $e->getCode(), $e);
      }
      if ($e->getCode() == static::ACCESS_DENIED) {
        throw new DatabaseAccessDeniedException($e->getMessage(), $e->getCode(), $e);
      }
      throw $e;
    }

    // Force MySQL to use the UTF-8 character set. Also set the collation, if a
    // certain one has been set; otherwise, MySQL defaults to
    // 'utf8mb4_general_ci' (MySQL 5) or 'utf8mb4_0900_ai_ci' (MySQL 8) for
    // utf8mb4.
    if (!empty($connection_options['collation'])) {
      $mysqli->query('SET NAMES ' . $charset . ' COLLATE ' . $connection_options['collation']);
    }
    else {
      $mysqli->query('SET NAMES ' . $charset);
    }

    // Set MySQL init_commands if not already defined.  Default Drupal's MySQL
    // behavior to conform more closely to SQL standards.  This allows Drupal
    // to run almost seamlessly on many different kinds of database systems.
    // These settings force MySQL to behave the same as postgresql, or sqlite
    // in regards to syntax interpretation and invalid data handling.  See
    // https://www.drupal.org/node/344575 for further discussion. Also, as MySQL
    // 5.5 changed the meaning of TRADITIONAL we need to spell out the modes one
    // by one.
    $connection_options += [
      'init_commands' => [],
    ];

    $connection_options['init_commands'] += [
      'sql_mode' => "SET sql_mode = 'ANSI,TRADITIONAL'",
    ];

    // Execute initial commands.
    foreach ($connection_options['init_commands'] as $sql) {
      $mysqli->query($sql);
    }

    return $mysqli;
  }

  /**
   * {@inheritdoc}
   */
  public function driver() {
    return 'mysqli';
  }

  /**
   * {@inheritdoc}
   */
  public function clientVersion() {
    return \mysqli_get_client_info();
  }

  /**
   * {@inheritdoc}
   */
  public function quote($string, $parameter_type = \PDO::PARAM_STR) {
    return "'" . $this->connection->escape_string($string) . "'";
  }

  /**
   * {@inheritdoc}
   */
  public function lastInsertId(?string $name = NULL): string {
    return $this->connection->insert_id;
  }

  /**
   * {@inheritdoc}
   */
  public function pushTransaction($name) {
    if (isset($this->transactionLayers[$name])) {
      throw new TransactionNameNonUniqueException($name . " is already in use.");
    }
    // If we're already in a transaction then we want to create a savepoint
    // rather than try to create another transaction.
    if ($this->inTransaction()) {
//dump(['pushTransaction savepoint', $name]);
      $this->connection->savepoint($name);
    }
    else {
//dump(['pushTransaction begin_transaction', $name]);
      $this->connection->begin_transaction(0, $name);
    }
    $this->transactionLayers[$name] = $name;
//dump(['pushTransaction out', $this->transactionLayers]);
  }

  /**
   * {@inheritdoc}
   *
   * mysqli does not support query('RELEASE SAVEPOINT ' . $name), we
   * need to use direct rollback on the connection.
   */
  protected function popCommittableTransactions() {
//dump(['popCommittableTransactions in', $this->transactionLayers]);
    // Commit all the committable layers.
    foreach (array_reverse($this->transactionLayers) as $name => $active) {
      // Stop once we found an active transaction.
      if ($active) {
        break;
      }

      // If there are no more layers left then we should commit.
      unset($this->transactionLayers[$name]);
      if (empty($this->transactionLayers)) {
//dump(['popCommittableTransactions 1', $name]);
        $this->doCommit();
      }
      else {
//dump(['popCommittableTransactions 2', $name]);
        if (!$this->connection->release_savepoint($name)) {
//dump(['popCommittableTransactions 3', $name]);
          $this->transactionLayers = [];
          $this->doCommit();
        }
      }
    }
//dump(['popCommittableTransactions out', $this->transactionLayers]);
  }

  /**
   * {@inheritdoc}
   *
   * mysqli does not support query('ROLLBACK TO SAVEPOINT ' . $savepoint), we
   * need to use direct rollback on the connection.
   */
  public function rollBack($savepoint_name = 'drupal_transaction') {
//dump(['rollBack', $savepoint_name, $this->transactionLayers]);
    if (!$this->inTransaction()) {
      throw new TransactionNoActiveException();
    }
    // A previous rollback to an earlier savepoint may mean that the savepoint
    // in question has already been accidentally committed.
    if (!isset($this->transactionLayers[$savepoint_name])) {
      throw new TransactionNoActiveException();
    }

    // We need to find the point we're rolling back to, all other savepoints
    // before are no longer needed. If we rolled back other active savepoints,
    // we need to throw an exception.
    $rolled_back_other_active_savepoints = FALSE;
    while ($savepoint = array_pop($this->transactionLayers)) {
      if ($savepoint == $savepoint_name) {
        // If it is the last the transaction in the stack, then it is not a
        // savepoint, it is the transaction itself so we will need to roll back
        // the transaction rather than a savepoint.
        if (empty($this->transactionLayers)) {
//dump(['rollBack 2', $savepoint_name, $this->transactionLayers]);
          break;
        }
//dump($this->query('SELECT * FROM {test}')->fetchAll());
//        $success = $this->connection->rollback(0, $savepoint);
        $success = $this->connection->query('ROLLBACK TO SAVEPOINT ' . $savepoint_name);
//dump(['rollBack 3', $savepoint_name, $this->transactionLayers, $success]);
//dump($this->query('SELECT * FROM {test}')->fetchAll());
        $this->popCommittableTransactions();
        if ($rolled_back_other_active_savepoints) {
          throw new TransactionOutOfOrderException();
        }
        return;
      }
      else {
//dump(['rollBack 4', $savepoint, $savepoint_name, $this->transactionLayers]);
        $rolled_back_other_active_savepoints = TRUE;
      }
    }

    // Notify the callbacks about the rollback.
    $callbacks = $this->rootTransactionEndCallbacks;
    $this->rootTransactionEndCallbacks = [];
    foreach ($callbacks as $callback) {
      call_user_func($callback, FALSE);
    }

//dump(['in rollback 1']);
    if (!$this->connection->rollBack()) {
//dump(['in rollback 2']);
      trigger_error('Invalid rollback', E_USER_WARNING);
    }
    if ($rolled_back_other_active_savepoints) {
      throw new TransactionOutOfOrderException();
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function doCommit() {
//dump(['doCommit']);
    // MySQL will automatically commit transactions when tables are altered or
    // created (DDL transactions are not supported). Prevent triggering an
    // exception in this case as all statements have been committed.
    // mysqli does not detect if a transaction is active so we need to rely on
    // internals.
    if ($this->inTransaction()) {
      $success = BaseConnection::doCommit();
    }
    else {
      // Process the post-root (non-nested) transaction commit callbacks. The
      // following code is copied from
      // \Drupal\Core\Database\Connection::doCommit()
      $success = TRUE;
      if (!empty($this->rootTransactionEndCallbacks)) {
        $callbacks = $this->rootTransactionEndCallbacks;
        $this->rootTransactionEndCallbacks = [];
        foreach ($callbacks as $callback) {
          call_user_func($callback, $success);
        }
      }
    }
    return $success;
  }

  /**
   * @todo
   */
  public function convertNamedPlaceholdersToPositional(string $sql, array $args): array {
    if (!isset($this->parser)) {
      $this->parser = new Parser(FALSE);
    }

    $pms = [];
    foreach($args as $k => $v) {
      $pms[substr($k, 1)] = $v;
    }

    $visitor = new Visitor($pms);

    $this->parser->parse($sql, $visitor);

    return [
      $visitor->getSQL(),
      $visitor->getParameters(),
    ];
  }

}
