<?php

declare(strict_types=1);

namespace Drupal\atmosphere\Transformer;

/**
 * Generates AT Protocol Timestamp Identifiers (TIDs).
 *
 * TIDs are 13-character base-32 encoded identifiers combining a microsecond
 * timestamp (left-shifted 10 bits) with a per-process clock ID. They are
 * monotonically increasing and sortable.
 *
 * Requires 64-bit PHP (the left-shift produces values exceeding 32-bit range).
 */
class TidGenerator {

  /**
   * @throws \RuntimeException
   *   If running on 32-bit PHP.
   */
  public function __construct() {
    if (PHP_INT_SIZE < 8) {
      throw new \RuntimeException('TidGenerator requires 64-bit PHP (PHP_INT_SIZE >= 8).');
    }
  }

  private const CHARSET = '234567abcdefghijklmnopqrstuvwxyz';

  private const LEN = 13;

  private static int $lastTs = 0;

  private static ?int $clockId = NULL;

  /**
   * Generates a new TID.
   */
  public function generate(): string {
    if (self::$clockId === NULL) {
      self::$clockId = random_int(0, 1023);
    }

    $ts = (int) (microtime(true) * 1_000_000);

    if ($ts <= self::$lastTs) {
      $ts = self::$lastTs + 1;
    }

    self::$lastTs = $ts;

    $id = ($ts << 10) | self::$clockId;

    return $this->encode($id);
  }

  /**
   * Validates a TID string.
   */
  public function isValid(string $tid): bool {
    if (strlen($tid) !== self::LEN) {
      return false;
    }

    for ($i = 0; $i < self::LEN; $i++) {
      if (!str_contains(self::CHARSET, $tid[$i])) {
        return false;
      }
    }

    return true;
  }

  /**
   * Encodes an integer as a base-32 TID string.
   */
  private function encode(int $id): string {
    $result = '';
    for ($i = 0; $i < self::LEN; $i++) {
      $result = self::CHARSET[$id & 0x1F] . $result;
      $id >>= 5;
    }

    return $result;
  }

}
