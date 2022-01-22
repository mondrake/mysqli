<?php

namespace Drupal\mysqli\Driver\Database\mysqli;

use Drupal\mysql\Driver\Database\mysql\Connection as BaseConnection;
use Drupal\mysqli\Driver\Database\mysqli\Parser\Parser;
use Drupal\mysqli\Driver\Database\mysqli\Parser\Visitor;

/**
 * MySQLi implementation of \Drupal\Core\Database\Connection.
 */
class Connection extends BaseConnection {

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
   * @todo
   */
  public function convertNamedPlaceholdersToPositional(string $sql, array $args): array {
    if ($this->parser === null) {
      $this->parser = new Parser();
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
