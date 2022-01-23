<?php

namespace Drupal\mysqli\Driver\Database\mysqli;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Database\ExceptionHandler as BaseExceptionHandler;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Database\StatementInterface;

/**
 * MySQLi database exception handler class.
 */
class ExceptionHandler extends BaseExceptionHandler {

  /**
   * {@inheritdoc}
   *
   * @todo note that mysqli_sql_exception::getSqlState() is only available as
   *   of PHP 8.1.2.
   */
  public function handleExecutionException(\Exception $exception, StatementInterface $statement, array $arguments = [], array $options = []): void {
//dump([$exception, $statement, $arguments, $options]);
    if ($exception instanceof \mysqli_sql_exception) {
      // Wrap the exception in another exception, because PHP does not allow
      // overriding Exception::getMessage(). Its message is the extra database
      // debug information.
      $code = is_int($exception->getCode()) ? $exception->getCode() : 0;

      // If a max_allowed_packet error occurs the message length is truncated.
      // This should prevent the error from recurring if the exception is logged
      // to the database using dblog or the like.
      if ($code === 1153) {
        $message = Unicode::truncateBytes($exception->getMessage(), Connection::MIN_MAX_ALLOWED_PACKET);
        throw new DatabaseExceptionWrapper($message, $code, $exception);
      }

      $message = $exception->getMessage() . ": " . $statement->getQueryString() . "; " . print_r($arguments, TRUE);

      // SQLSTATE 23xxx errors indicate an integrity constraint violation. Also,
      // in case of attempted INSERT of a record with an undefined column and no
      // default value indicated in schema, MySql returns a 1364 error code.
      if (
        substr($statement->getSqlState(), -6, -3) == '23' ||
        $code === 1364
      ) {
        throw new IntegrityConstraintViolationException($message, $code, $exception);
      }

      throw new DatabaseExceptionWrapper($message, 0, $exception);
    }

    throw $exception;
  }

}
