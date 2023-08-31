<?php

namespace Drupal\mysqli\Driver\Database\mysqli;

/**
 * A class to convert a SQL statement with named placeholders to positional.
 *
 * The parsing logic and the implementation is inspired by the PHP PDO parser,
 * and a simplified copy of the parser implementation done by the Doctrine DBAL
 * project.
 *
 * @internal
 *
 * @see https://github.com/doctrine/dbal/tree/3.6.x/src/SQL/Parser
 */
final class NamedPlaceholderConverter
{
  /**
   * A list of regex patterns for parsing.
   */
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

  /**
   * The combined regex pattern for parsing.
   */
  private string $sqlPattern;

  /**
   * The list of original named arguments.
   *
   * The initial placeholder colon is removed.
   */
  private array $originalParameters = [];

  /**
   * The maximum positional placeholder parsed.
   *
   * Normally Drupal does not produce SQL with positional placeholders, but
   * this is to manage the edge case.
   */
  private int $originalParameterIndex = 0;

  /**
   * The converted SQL statement in its parts.
   *
   * @var string[]
   */
  private array $convertedSQL = [];

  /**
   * The list of converted arguments.
   *
   * @var mixed[]
   */
  private array $convertedParameters = [];

  public function __construct() {
    // Builds the combined regex pattern for parsing.
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
   * Parses an SQL statement with named placeholders.
   *
   * This methods explodes the SQL statement in parts that can be reassembled
   * into a string with positional placeholders.
   *
   * @param string $sql
   *   The SQL statement with named placeholders.
   * @param mixed[] $args
   *   The statement arguments.
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
        $this->addNamedParameter($sql);
      },
      self::POSITIONAL_PARAMETER => function (string $sql): void {
        $this->addPositionalParameter($sql);
      },
      $this->sqlPattern => function (string $sql): void {
        $this->addOther($sql);
      },
      self::SPECIAL => function (string $sql): void {
        $this->addOther($sql);
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
   * Helper to return a regex pattern from a delimiter character..
   *
   * @param string $delimiter
   *   A delimiter character.
   *
   * @return string
   *   The regex pattern.
   */
  private function getAnsiSQLStringLiteralPattern(string $delimiter): string {
    return $delimiter . '[^' . $delimiter . ']*' . $delimiter;
  }

  /**
   * Adds a positional placeholder to the converted parts.
   *
   * Normally Drupal does not produce SQL with positional placeholders, but
   * this is to manage the edge case.
   *
   * @param string $sql
   *   The SQL part.
   */
  private function addPositionalParameter(string $sql): void {
    $index = $this->originalParameterIndex;

    if (!array_key_exists($index, $this->originalParameters)) {
      throw \RuntimeException('Missing Positional Parameter ' . $index);
    }

    $this->convertedSQL[] = '?';
    $this->convertedParameters[] = $this->originalParameters[$index];

    $this->originalParameterIndex++;
  }

  /**
   * Adds a named placeholder to the converted parts.
   *
   * @param string $sql
   *   The SQL part.
   */
  private function addNamedParameter(string $sql): void {
    $name = substr($sql, 1);

    if (!array_key_exists($name, $this->originalParameters)) {
      throw \RuntimeException('Missing Named Parameter ' . $name);
    }

    $this->convertedSQL[] = '?';
    $this->convertedParameters[] = $this->originalParameters[$name];
  }

  /**
   * Adds a generic SQL string fragment to the converted parts.
   *
   * @param string $sql
   *   The SQL part.
   */
  private function addOther(string $sql): void {
    $this->convertedSQL[] = $sql;
  }

  /**
   * Returns the converted SQL statement with positional placeholders.
   *
   * @return string
   *   The converted SQL statement with positional placeholders.
   */
  public function getConvertedSQL(): string {
    return implode('', $this->convertedSQL);
  }

  /**
   * Returns the array of arguments for use with positional placeholders.
   *
   * @return mixed[]
   *   The array of arguments for use with positional placeholders.
   */
  public function getConvertedParameters(): array {
    return $this->convertedParameters;
  }

}
