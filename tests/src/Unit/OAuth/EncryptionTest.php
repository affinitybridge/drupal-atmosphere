<?php

declare(strict_types=1);

namespace Drupal\Tests\atmosphere\Unit\OAuth;

use Drupal\atmosphere\OAuth\Encryption;
use Drupal\Core\Site\Settings;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\atmosphere\OAuth\Encryption
 */
class EncryptionTest extends TestCase {

  private Encryption $encryption;

  protected function setUp(): void {
    parent::setUp();

    // Initialize Drupal Settings with a test hash salt.
    new Settings(['hash_salt' => 'test_salt_for_unit_tests_only']);

    $this->encryption = new Encryption();
  }

  /**
   * @covers ::encrypt
   * @covers ::decrypt
   */
  public function testEncryptDecryptRoundTrip(): void {
    $plaintext = 'my-secret-access-token';

    $encrypted = $this->encryption->encrypt($plaintext);
    $decrypted = $this->encryption->decrypt($encrypted);

    $this->assertSame($plaintext, $decrypted);
  }

  /**
   * @covers ::encrypt
   */
  public function testEncryptProducesDifferentOutputEachTime(): void {
    $plaintext = 'same-input';

    $a = $this->encryption->encrypt($plaintext);
    $b = $this->encryption->encrypt($plaintext);

    $this->assertNotSame($a, $b, 'Each encryption should use a random nonce.');
  }

  /**
   * @covers ::decrypt
   */
  public function testDecryptReturnsfalseForGarbage(): void {
    $result = $this->encryption->decrypt('not-valid-base64!!!');
    $this->assertFalse($result);
  }

  /**
   * @covers ::decrypt
   */
  public function testDecryptReturnsFalseForTruncatedData(): void {
    $encrypted = $this->encryption->encrypt('hello');
    // Truncate to break the ciphertext.
    $truncated = substr($encrypted, 0, 10);
    $result = $this->encryption->decrypt($truncated);
    $this->assertFalse($result);
  }

  /**
   * @covers ::decrypt
   */
  public function testDecryptReturnsFalseForTamperedData(): void {
    $encrypted = $this->encryption->encrypt('hello');
    // Flip a character in the ciphertext.
    $decoded = base64_decode($encrypted);
    $decoded[strlen($decoded) - 1] = chr(ord($decoded[strlen($decoded) - 1]) ^ 0xFF);
    $tampered = base64_encode($decoded);

    $result = $this->encryption->decrypt($tampered);
    $this->assertFalse($result);
  }

  /**
   * @covers ::encrypt
   * @covers ::decrypt
   */
  public function testHandlesEmptyString(): void {
    $encrypted = $this->encryption->encrypt('');
    $decrypted = $this->encryption->decrypt($encrypted);
    $this->assertSame('', $decrypted);
  }

  /**
   * @covers ::encrypt
   * @covers ::decrypt
   */
  public function testHandlesLongPayload(): void {
    $plaintext = str_repeat('a', 10000);
    $encrypted = $this->encryption->encrypt($plaintext);
    $decrypted = $this->encryption->decrypt($encrypted);
    $this->assertSame($plaintext, $decrypted);
  }

}
