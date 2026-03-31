<?php

declare(strict_types=1);

namespace Drupal\atmosphere\OAuth;

use Drupal\Core\Site\Settings;

/**
 * Symmetric encryption at rest using libsodium.
 *
 * Derives the encryption key from Drupal's hash salt, providing
 * environment-specific encryption for OAuth tokens stored in state.
 */
class Encryption {

  public function __construct(
    private readonly Settings $settings,
  ) {}

  /**
   * Encrypts a plaintext string.
   *
   * @return string
   *   Base64-encoded nonce + ciphertext.
   */
  public function encrypt(string $plaintext): string {
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key());

    return base64_encode($nonce . $ciphertext);
  }

  /**
   * Decrypts an encrypted string.
   *
   * @return string|false
   *   The decrypted plaintext, or FALSE on failure.
   */
  public function decrypt(string $encoded): string|false {
    $decoded = base64_decode($encoded, TRUE);

    if ($decoded === FALSE) {
      return FALSE;
    }

    $nonceLength = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

    if (strlen($decoded) < $nonceLength) {
      return FALSE;
    }

    $nonce = substr($decoded, 0, $nonceLength);
    $ciphertext = substr($decoded, $nonceLength);

    return sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key());
  }

  /**
   * Derives the encryption key from the site's hash salt.
   */
  private function key(): string {
    return sodium_crypto_generichash(
      Settings::getHashSalt(),
      '',
      SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
    );
  }

}
