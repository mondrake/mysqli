<?php

namespace Drupal\mysqli\Driver\Database\mysqli\Install;

use Drupal\Core\Database\ConnectionNotDefinedException;
use Drupal\Core\Database\Database;
use Drupal\Core\Database\DatabaseNotFoundException;
use Drupal\mysql\Driver\Database\mysql\Install\Tasks as BaseInstallTasks;
use Drupal\mysqli\Driver\Database\mysqli\Connection;

/**
 * Specifies installation tasks for MySQLi.
 */
class Tasks extends BaseInstallTasks {

  /**
   * {@inheritdoc}
   */
  public function installable() {
    return extension_loaded('mysqli');
  }

  /**
   * {@inheritdoc}
   */
  public function name() {
    return $this->t('@parent via mysqli', ['@parent' => parent::name()]);
  }

  /**
   * {@inheritdoc}
   */
  protected function connect() {
    try {
      // This doesn't actually test the connection.
      Database::setActiveConnection();
      // Now actually do a check.
      try {
        Database::getConnection();
      }
      catch (\Exception $e) {
        // Detect utf8mb4 incompatibility.
        if ($e->getCode() == Connection::UNSUPPORTED_CHARSET || ($e->getCode() == Connection::SQLSTATE_SYNTAX_ERROR && $e->errorInfo[1] == Connection::UNKNOWN_CHARSET)) {
          $this->fail(t('Your MySQL server and PHP MySQL driver must support utf8mb4 character encoding. Make sure to use a database system that supports this (such as MySQL/MariaDB/Percona 5.5.3 and up), and that the utf8mb4 character set is compiled in. See the <a href=":documentation" target="_blank">MySQL documentation</a> for more information.', [':documentation' => 'https://dev.mysql.com/doc/refman/5.0/en/cannot-initialize-character-set.html']));
          $info = Database::getConnectionInfo();
          $info_copy = $info;
          // Set a flag to fall back to utf8. Note: this flag should only be
          // used here and is for internal use only.
          $info_copy['default']['_dsn_utf8_fallback'] = TRUE;
          // In order to change the Database::$databaseInfo array, we need to
          // remove the active connection, then re-add it with the new info.
          Database::removeConnection('default');
          Database::addConnectionInfo('default', 'default', $info_copy['default']);
          // Connect with the new database info, using the utf8 character set so
          // that we can run the checkEngineVersion test.
          Database::getConnection();
          // Revert to the old settings.
          Database::removeConnection('default');
          Database::addConnectionInfo('default', 'default', $info['default']);
        }
        else {
          // Rethrow the exception.
          throw $e;
        }
      }
      $this->pass('Drupal can CONNECT to the database ok.');
    }
    catch (\Exception $e) {
      // Attempt to create the database if it is not found.
      if ($e->getCode() == Connection::DATABASE_NOT_FOUND) {
        // Remove the database string from connection info.
        $connection_info = Database::getConnectionInfo();
        $database = $connection_info['default']['database'];
        unset($connection_info['default']['database']);

        // In order to change the Database::$databaseInfo array, need to remove
        // the active connection, then re-add it with the new info.
        Database::removeConnection('default');
        Database::addConnectionInfo('default', 'default', $connection_info['default']);

        try {
          // Now, attempt the connection again; if it's successful, attempt to
          // create the database.
          Database::getConnection()->createDatabase($database);
          Database::closeConnection();

          // Now, restore the database config.
          Database::removeConnection('default');
          $connection_info['default']['database'] = $database;
          Database::addConnectionInfo('default', 'default', $connection_info['default']);

          // Check the database connection.
          Database::getConnection();
          $this->pass('Drupal can CONNECT to the database ok.');
        }
        catch (DatabaseNotFoundException $e) {
          // Still no dice; probably a permission issue. Raise the error to the
          // installer.
          $this->fail(t('Database %database not found. The server reports the following message when attempting to create the database: %error.', ['%database' => $database, '%error' => $e->getMessage()]));
        }
      }
      else {
        // Database connection failed for some other reason than a non-existent
        // database.
        $this->fail(t('Failed to connect to your database server. The server reports the following message: %error.<ul><li>Is the database server running?</li><li>Does the database exist or does the database user have sufficient privileges to create the database?</li><li>Have you entered the correct database name?</li><li>Have you entered the correct username and password?</li><li>Have you entered the correct database hostname and port number?</li></ul>', ['%error' => $e->getMessage()]));
        return FALSE;
      }
    }
    return TRUE;
  }

}
