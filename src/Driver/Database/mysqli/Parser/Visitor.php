<?php

namespace Drupal\mysqli\Driver\Database\mysqli\Parser;

final class Visitor
{
  /** @var array<int,mixed>|array<string,mixed> */
  private $originalParameters = [];

  /** @var int */
  private $originalParameterIndex = 0;

  /** @var list<string> */
  private $convertedSQL = [];

  /** @var list<mixed> */
  private $convertedParameters = [];

  /**
   * @todo
   */
  public function __construct(array $parameters) {
    // Remove the initial colon from the placeholders.
    foreach($parameters as $key => $value) {
      $this->originalParameters[substr($key, 1)] = $value;
    }
  }

  /**
   * @todo
   */
  public function acceptPositionalParameter(string $sql): void {
  dump([__METHOD__, $sql]);
    $index = $this->originalParameterIndex;

    if (!array_key_exists($index, $this->originalParameters)) {
      throw \RuntimeException('Missing Positional Parameter ' . $index);
    }

    $this->acceptParameter($index, $this->originalParameters[$index]);

    $this->originalParameterIndex++;
  }

  /**
   * @todo
   */
  public function acceptNamedParameter(string $sql): void {
  dump([__METHOD__, $sql]);
    $name = substr($sql, 1);

    if (!array_key_exists($name, $this->originalParameters)) {
      throw \RuntimeException('Missing Named Parameter ' . $name);
    }

    $this->acceptParameter($name, $this->originalParameters[$name]);
  }

  /**
   * @todo
   */
  public function acceptOther(string $sql): void {
  dump([__METHOD__, $sql]);
    $this->convertedSQL[] = $sql;
  }

  /**
   * @todo
   */
  public function getSQL(): string {
    return implode('', $this->convertedSQL);
  }

  /**
   * @todo
   */
  public function getParameters(): array {
    return $this->convertedParameters;
  }

  /**
   * @todo
   */
  private function acceptParameter($key, $value): void {
    $this->convertedSQL[] = '?';
    $this->convertedParameters[] = $value;
  }

}
