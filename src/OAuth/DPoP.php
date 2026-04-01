<?php

declare(strict_types=1);

namespace Drupal\atmosphere\OAuth;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;

/**
 * DPoP (Demonstration of Proof-of-Possession) proof generator.
 *
 * Creates ES256-signed JWT proofs for AT Protocol OAuth requests,
 * binding access tokens to specific HTTP requests.
 */
class DPoP {

  public function __construct(
    private readonly NonceStorage $nonceStorage,
  ) {}

  /**
   * Generates a new EC P-256 key pair as a JWK array.
   */
  public function generateKey(): array {
    $key = openssl_pkey_new([
      'curve_name' => 'prime256v1',
      'private_key_type' => OPENSSL_KEYTYPE_EC,
    ]);

    if ($key === false) {
      throw new \RuntimeException('Failed to generate EC key pair.');
    }

    $details = openssl_pkey_get_details($key);
    $ec = $details['ec'];

    return [
      'kty' => 'EC',
      'crv' => 'P-256',
      'x' => self::base64url($ec['x']),
      'y' => self::base64url($ec['y']),
      'd' => self::base64url($ec['d']),
    ];
  }

  /**
   * Creates a DPoP proof JWT.
   *
   * @param array $jwk
   *   The JWK key pair (including private key 'd').
   * @param string $method
   *   The HTTP method (GET, POST, etc.).
   * @param string $url
   *   The request URL.
   * @param string|null $nonce
   *   An explicit DPoP nonce, or NULL to look up a stored one.
   * @param string|null $accessToken
   *   If provided, binds the proof to this access token via the 'ath' claim.
   *
   * @return string|false
   *   The compact-serialized JWT, or false on failure.
   */
  public function createProof(array $jwk, string $method, string $url, ?string $nonce = NULL, ?string $accessToken = NULL): string|false {
    try {
      $algorithmManager = new AlgorithmManager([new ES256()]);
      $jwsBuilder = new JWSBuilder($algorithmManager);

      // Build public-only JWK for the header.
      $publicJwk = [
        'kty' => $jwk['kty'],
        'crv' => $jwk['crv'],
        'x' => $jwk['x'],
        'y' => $jwk['y'],
      ];

      $header = [
        'alg' => 'ES256',
        'typ' => 'dpop+jwt',
        'jwk' => $publicJwk,
      ];

      $payload = [
        'jti' => bin2hex(random_bytes(16)),
        'htm' => strtoupper($method),
        'htu' => $url,
        'iat' => time(),
      ];

      if ($nonce === NULL) {
        $nonce = $this->nonceStorage->get($url);
      }
      if ($nonce !== NULL) {
        $payload['nonce'] = $nonce;
      }

      if ($accessToken !== NULL) {
        $payload['ath'] = self::base64url(hash('sha256', $accessToken, true));
      }

      $signingKey = new JWK($jwk);
      $jws = $jwsBuilder
        ->create()
        ->withPayload(json_encode($payload, JSON_THROW_ON_ERROR))
        ->addSignature($signingKey, $header)
        ->build();

      $serializer = new CompactSerializer();
      return $serializer->serialize($jws, 0);
    }
    catch (\Throwable) {
      return false;
    }
  }

  /**
   * Persists a DPoP nonce received from the server.
   */
  public function persistNonce(string $url, string $nonce): void {
    $this->nonceStorage->set($url, $nonce);
  }

  /**
   * Base64url encoding without padding.
   */
  private static function base64url(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
  }

}
