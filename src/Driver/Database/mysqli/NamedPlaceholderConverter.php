<?php

namespace Drupal\mysqli\Driver\Database\mysqli;

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
final class NamedPlaceholderConverter
{
  private const SPECIAL_CHARS = ':\?\'"`\\[\\-\\/';

  private const BACKTICK_IDENTIFIER = '`[^`]*`';
  private const BRACKET_IDENTIFIER = '(?<!\b(?i:ARRAY))\[(?:[^\]])*\]';
  private const MULTICHAR = ':{2,}';
  private const NAMED_PARAMETER = ':[a-zA-Z0-9_]+';
  private const POSITIONAL_PARAMETER = '(?<!\\?)\\?(?!\\?)';
  private const ONE_LINE_COMMENT = '--[^\r\n]*';
  private const MULTI_LINE_COMMENT = '/\*([^*]+|\*+[^/*])*\**\*/';
  private const SPECIAL = '[' . self::SPECIAL_CHARS . ']';
  private const OTHER = '[^' . self::SPECIAL_CHARS . ']+';

  private string $sqlPattern;

  /** @var array<int,mixed>|array<string,mixed> */
  private array $originalParameters = [];

  private int $originalParameterIndex = 0;

  /** @var list<string> */
  private array $convertedSQL = [];

  /** @var list<mixed> */
  private array $convertedParameters = [];

  public function __construct() {
    $this->sqlPattern = sprintf('(%s)', implode('|', [
      $this->getAnsiSQLStringLiteralPattern("'"),
      $this->getAnsiSQLStringLiteralPattern('"'),
      self::BACKTICK_IDENTIFIER,
      self::BRACKET_IDENTIFIER,
      self::MULTICHAR,
      self::ONE_LINE_COMMENT,
      self::MULTI_LINE_COMMENT,
      self::OTHER,
    ]));
  }

  /**
   * Parses the given SQL statement
   *
   * @todo
   */
  public function parse(string $sql, array $args): void {
    // Remove the initial colon from the placeholders.
    foreach($args as $key => $value) {
      $this->originalParameters[substr($key, 1)] = $value;
    }
    $this->originalParameterIndex = 0;
    $this->convertedSQL = [];
    $this->convertedParameters = [];

    /** @var array<string,callable> $patterns */
    $patterns = [
      self::NAMED_PARAMETER => function (string $sql): void {
        $this->acceptNamedParameter($sql);
      },
      self::POSITIONAL_PARAMETER => function (string $sql): void {
        $this->acceptPositionalParameter($sql);
      },
      $this->sqlPattern => function (string $sql): void {
        $this->acceptOther($sql);
      },
      self::SPECIAL => function (string $sql): void {
        $this->acceptOther($sql);
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

  /**
   * @todo
   */
  private function acceptPositionalParameter(string $sql): void {
    $index = $this->originalParameterIndex;

    if (!array_key_exists($index, $this->originalParameters)) {
      throw \RuntimeException('Missing Positional Parameter ' . $index);
    }

    $this->acceptParameter($this->originalParameters[$index]);

    $this->originalParameterIndex++;
  }

  /**
   * @todo
   */
  private function acceptNamedParameter(string $sql): void {
    $name = substr($sql, 1);

    if (!array_key_exists($name, $this->originalParameters)) {
      throw \RuntimeException('Missing Named Parameter ' . $name);
    }

    $this->acceptParameter($this->originalParameters[$name]);
  }

  /**
   * @todo
   */
  private function acceptOther(string $sql): void {
    $this->convertedSQL[] = $sql;
  }

  /**
   * @todo
   */
  public function getConvertedSQL(): string {
    return implode('', $this->convertedSQL);
  }

  /**
   * @todo
   */
  public function getConvertedParameters(): array {
    return $this->convertedParameters;
  }

  /**
   * @todo
   */
  private function acceptParameter(mixed $value): void {
    $this->convertedSQL[] = '?';
    $this->convertedParameters[] = $value;
  }

}
