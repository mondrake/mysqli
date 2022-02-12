<?php

namespace Drush\Sql;

class SqlMysqli extends SqlMysql
{
  /**
   * Convert from an old-style database URL to an array of database settings.
   *
   * @param db_url
   *   A Drupal 6 db url string to convert, or an array with a 'default' element.
   *   An array of database values containing only the 'default' element of
   *   the db url. If the parse fails the array is empty.
   */
  public static function dbSpecFromDbUrl($db_url): array
  {
      $db_spec = [];

      $db_url_default = is_array($db_url) ? $db_url['default'] : $db_url;

      $url = parse_url($db_url_default);
      if ($url) {
          // Fill in defaults to prevent notices.
          $url += [
              'scheme' => null,
              'user'   => null,
              'pass'   => null,
              'host'   => null,
              'port'   => null,
              'path'   => null,
          ];
          $url = (object)array_map('urldecode', $url);
          $db_spec = [
              'driver'   => $url->scheme,
              'username' => $url->user,
              'password' => $url->pass,
              'host' => $url->host,
              'port' => $url->port,
              'database' => ltrim($url->path, '/'),
          ];
      }

      return $db_spec;
  }
}
