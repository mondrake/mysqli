<?php

namespace Drupal\mysqli\Driver\Database\mysqli;

use Drupal\Core\Database\Connection as BaseConnection;
use Drupal\Core\Database\Database;
use Drupal\Core\Database\TransactionNameNonUniqueException;
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
    $this->identifierQuotes = ['`', '`'];

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
    $connection_options['pdo'] += [
      \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
      // So we don't have to mess around with cursors and unbuffered queries by default.
      \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => TRUE,
      // Make sure MySQL returns all matched rows on update queries including
      // rows that actually didn't have to be updated because the values didn't
      // change. This matches common behavior among other database systems.
      \PDO::MYSQL_ATTR_FOUND_ROWS => TRUE,
      // Because MySQL's prepared statements skip the query cache, because it's dumb.
      \PDO::ATTR_EMULATE_PREPARES => TRUE,
      // Limit SQL to a single statement like mysqli.
      \PDO::MYSQL_ATTR_MULTI_STATEMENTS => FALSE,
      // Convert numeric values to strings when fetching. In PHP 8.1,
      // \PDO::ATTR_EMULATE_PREPARES now behaves the same way as non emulated
      // prepares and returns integers. See https://externals.io/message/113294
      // for further discussion.
      \PDO::ATTR_STRINGIFY_FETCHES => TRUE,
    ];

    try {
      $mysqli = new \mysqli(
        $connection_options['host'],
        $connection_options['username'],
        $connection_options['password'],
        $connection_options['database'] ?? '',
        $connection_options['port'] ?? 3306,
        $connection_options['unix_socket'] ?? ''
      );
      $mysqli->set_charset($charset);
    }
    catch (\PDOException $e) {
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
      $this->query('SAVEPOINT ' . $name);
    }
    else {
      $this->connection->begin_transaction(0, $name);
    }
    $this->transactionLayers[$name] = $name;
  }

  /**
   * {@inheritdoc}
   */
  public function rollBack($savepoint_name = 'drupal_transaction') {
    return BaseConnection::rollBack($savepoint_name);
  }

  /**
   * {@inheritdoc}
   */
  protected function doCommit() {
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

  /**
   * {@inheritdoc}
   *
   * @todo we can avoid this override if core queries the Connection instead of
   *   the wrapped client one.
   */
  protected function getServerVersion(): string {
    if (!isset($this->serverVersion)) {
      $this->serverVersion = $this->query('SELECT VERSION()')->fetchField();
    }
    return $this->serverVersion;
  }

  /**
   * {@inheritdoc}
   *
   * @todo we can avoid this override if core uses the Connection lastInsertId
   *   method.
   */
  public function query($query, array $args = [], $options = []) {
    assert(is_string($query), 'The \'$query\' argument to ' . __METHOD__ . '() must be a string');

    // Use default values if not already set.
    $options += $this->defaultOptions();

    if (isset($options['return'])) {
      @trigger_error('Passing "return" option to ' . __METHOD__ . '() is deprecated in drupal:9.4.0 and is removed in drupal:11.0.0. For data manipulation operations, use dynamic queries instead. See https://www.drupal.org/node/3185520', E_USER_DEPRECATED);
    }

    assert(!isset($options['target']), 'Passing "target" option to query() has no effect. See https://www.drupal.org/node/2993033');

    $this->expandArguments($query, $args);
    $stmt = $this->prepareStatement($query, $options);

    try {
      $stmt->execute($args, $options);

      // Depending on the type of query we may need to return a different value.
      // See DatabaseConnection::defaultOptions() for a description of each
      // value.
      switch ($options['return'] ?? Database::RETURN_STATEMENT) {
        case Database::RETURN_STATEMENT:
          return $stmt;

        // Database::RETURN_AFFECTED should not be used; enable row counting
        // by passing the appropriate argument to the constructor instead.
        // @see https://www.drupal.org/node/3186368
        case Database::RETURN_AFFECTED:
          $stmt->allowRowCount = TRUE;
          return $stmt->rowCount();

        case Database::RETURN_INSERT_ID:
          $sequence_name = $options['sequence_name'] ?? NULL;
          return $this->lastInsertId($sequence_name);

        case Database::RETURN_NULL:
          return NULL;

        default:
          throw new \PDOException('Invalid return directive: ' . $options['return']);

      }
    }
    catch (\Exception $e) {
      $this->exceptionHandler()->handleExecutionException($e, $stmt, $args, $options);
    }
  }

}
