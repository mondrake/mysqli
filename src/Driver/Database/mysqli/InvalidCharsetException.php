<?php

namespace Drupal\mysqli\Driver\Database\mysqli;

use Drupal\Core\Database\DatabaseExceptionWrapper;

/**
 * This exception class signals an invalid charset is being used.
 */
class InvalidCharsetException extends DatabaseExceptionWrapper {
}
