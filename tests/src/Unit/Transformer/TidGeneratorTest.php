<?php

declare(strict_types=1);

namespace Drupal\Tests\atmosphere\Unit\Transformer;

use Drupal\atmosphere\Transformer\TidGenerator;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\atmosphere\Transformer\TidGenerator
 */
class TidGeneratorTest extends TestCase {

  private TidGenerator $generator;

  protected function setUp(): void {
    parent::setUp();
    $this->generator = new TidGenerator();
  }

  /**
   * @covers ::generate
   */
  public function testGenerateReturns13Characters(): void {
    $tid = $this->generator->generate();
    $this->assertSame(13, strlen($tid));
  }

  /**
   * @covers ::generate
   */
  public function testGenerateUsesValidCharset(): void {
    $tid = $this->generator->generate();
    $this->assertMatchesRegularExpression('/^[234567a-z]{13}$/', $tid);
  }

  /**
   * @covers ::generate
   */
  public function testGenerateIsMonotonicallyIncreasing(): void {
    $tids = [];
    for ($i = 0; $i < 100; $i++) {
      $tids[] = $this->generator->generate();
    }

    $sorted = $tids;
    sort($sorted);

    $this->assertSame($sorted, $tids, 'TIDs should be sortable and monotonically increasing.');
  }

  /**
   * @covers ::generate
   */
  public function testGenerateProducesUniqueTids(): void {
    $tids = [];
    for ($i = 0; $i < 1000; $i++) {
      $tids[] = $this->generator->generate();
    }

    $this->assertCount(1000, array_unique($tids), 'All generated TIDs should be unique.');
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidAcceptsGeneratedTid(): void {
    $tid = $this->generator->generate();
    $this->assertTrue($this->generator->isValid($tid));
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidRejectsWrongLength(): void {
    $this->assertFalse($this->generator->isValid('abc'));
    $this->assertFalse($this->generator->isValid(''));
    $this->assertFalse($this->generator->isValid('22222222222222')); // 14 chars.
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidRejectsInvalidCharacters(): void {
    // '1' and '0' are not in the charset.
    $this->assertFalse($this->generator->isValid('1000000000000'));
    // Uppercase not in charset.
    $this->assertFalse($this->generator->isValid('AAAAAAAAAAAAA'));
  }

}
