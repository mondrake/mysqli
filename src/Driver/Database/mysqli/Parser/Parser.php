<?php

namespace Drupal\mysqli\Driver\Database\mysqli\Parser;

/**
 * The SQL parser that focuses on identifying prepared statement parameters. It implements parsing other tokens like
 * string literals and comments only as a way to not confuse their contents with the the parameter placeholders.
 *
 * The parsing logic and the implementation is inspired by the PHP PDO parser.
 *
 * @internal
 *
 * @see https://github.com/php/php-src/blob/php-7.4.12/ext/pdo/pdo_sql_parser.re#L49-L69
 */
final class Parser
{
  private const SPECIAL_CHARS = ':\?\'"`\\[\\-\\/';

  private const BACKTICK_IDENTIFIER  = '`[^`]*`';
  private const BRACKET_IDENTIFIER   = '(?<!\b(?i:ARRAY))\[(?:[^\]])*\]';
  private const MULTICHAR            = ':{2,}';
  private const NAMED_PARAMETER      = ':[a-zA-Z0-9_]+';
  private const POSITIONAL_PARAMETER = '(?<!\\?)\\?(?!\\?)';
  private const ONE_LINE_COMMENT     = '--[^\r\n]*';
  private const MULTI_LINE_COMMENT   = '/\*([^*]+|\*+[^/*])*\**\*/';
  private const SPECIAL              = '[' . self::SPECIAL_CHARS . ']';
  private const OTHER                = '[^' . self::SPECIAL_CHARS . ']+';

  private string $sqlPattern;

  public function __construct()
  {
    $patterns = [
      $this->getAnsiSQLStringLiteralPattern("'"),
      $this->getAnsiSQLStringLiteralPattern('"'),
    ];

    $patterns = array_merge($patterns, [
      self::BACKTICK_IDENTIFIER,
      self::BRACKET_IDENTIFIER,
      self::MULTICHAR,
      self::ONE_LINE_COMMENT,
      self::MULTI_LINE_COMMENT,
      self::OTHER,
    ]);

    $this->sqlPattern = sprintf('(%s)', implode('|', $patterns));
  }

  /**
   * Parses the given SQL statement
   *
   * @todo
   */
  public function parse(string $sql, Visitor $visitor): void {
    /** @var array<string,callable> $patterns */
    $patterns = [
      self::NAMED_PARAMETER => static function (string $sql) use ($visitor): void {
        $visitor->acceptNamedParameter($sql);
      },
      self::POSITIONAL_PARAMETER => static function (string $sql) use ($visitor): void {
        $visitor->acceptPositionalParameter($sql);
      },
      $this->sqlPattern => static function (string $sql) use ($visitor): void {
        $visitor->acceptOther($sql);
      },
      self::SPECIAL => static function (string $sql) use ($visitor): void {
        $visitor->acceptOther($sql);
      },
    ];

    $offset = 0;

    while (($handler = current($patterns)) !== false) {
      if (preg_match('~\G' . key($patterns) . '~s', $sql, $matches, 0, $offset) === 1) {
        $handler($matches[0]);
        reset($patterns);

        $offset += strlen($matches[0]);
      }
      elseif (preg_last_error() !== PREG_NO_ERROR) {
        throw \RuntimeException('Regular expression error');
      }
      else {
        next($patterns);
      }
    }

    assert($offset === strlen($sql));
  }

  /**
   * @todo
   */
  private function getAnsiSQLStringLiteralPattern(string $delimiter): string {
    return $delimiter . '[^' . $delimiter . ']*' . $delimiter;
  }

}
