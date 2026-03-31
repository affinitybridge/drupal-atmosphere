<?php

declare(strict_types=1);

namespace Drupal\Tests\atmosphere\Unit\OAuth;

use Drupal\atmosphere\OAuth\DPoP;
use Drupal\atmosphere\OAuth\NonceStorage;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\atmosphere\OAuth\DPoP
 */
class DPoPTest extends TestCase {

  private DPoP $dpop;
  private NonceStorage $nonceStorage;

  protected function setUp(): void {
    parent::setUp();

    $this->nonceStorage = $this->createMock(NonceStorage::class);
    $this->dpop = new DPoP($this->nonceStorage);
  }

  /**
   * @covers ::generateKey
   */
  public function testGenerateKeyReturnsValidJwk(): void {
    $jwk = $this->dpop->generateKey();

    $this->assertSame('EC', $jwk['kty']);
    $this->assertSame('P-256', $jwk['crv']);
    $this->assertArrayHasKey('x', $jwk);
    $this->assertArrayHasKey('y', $jwk);
    $this->assertArrayHasKey('d', $jwk, 'Private key material must be present.');
  }

  /**
   * @covers ::generateKey
   */
  public function testGenerateKeyProducesUniqueKeys(): void {
    $a = $this->dpop->generateKey();
    $b = $this->dpop->generateKey();

    $this->assertNotSame($a['d'], $b['d'], 'Each key should be unique.');
  }

  /**
   * @covers ::createProof
   */
  public function testCreateProofReturnsCompactJwt(): void {
    $jwk = $this->dpop->generateKey();
    $proof = $this->dpop->createProof($jwk, 'POST', 'https://pds.example.com/xrpc/test');

    $this->assertIsString($proof);
    $this->assertNotFalse($proof);

    // Compact JWS has 3 parts separated by dots.
    $parts = explode('.', $proof);
    $this->assertCount(3, $parts, 'Compact JWT should have header.payload.signature');
  }

  /**
   * @covers ::createProof
   */
  public function testCreateProofHeaderHasCorrectFields(): void {
    $jwk = $this->dpop->generateKey();
    $proof = $this->dpop->createProof($jwk, 'GET', 'https://pds.example.com/xrpc/test');

    $parts = explode('.', $proof);
    $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), TRUE);

    $this->assertSame('ES256', $header['alg']);
    $this->assertSame('dpop+jwt', $header['typ']);
    $this->assertArrayHasKey('jwk', $header);
    // Header JWK should be public only (no 'd').
    $this->assertArrayNotHasKey('d', $header['jwk'], 'Private key must not be in header.');
    $this->assertSame('EC', $header['jwk']['kty']);
  }

  /**
   * @covers ::createProof
   */
  public function testCreateProofPayloadHasRequiredClaims(): void {
    $jwk = $this->dpop->generateKey();
    $proof = $this->dpop->createProof($jwk, 'POST', 'https://pds.example.com/xrpc/test');

    $parts = explode('.', $proof);
    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), TRUE);

    $this->assertArrayHasKey('jti', $payload);
    $this->assertSame('POST', $payload['htm']);
    $this->assertSame('https://pds.example.com/xrpc/test', $payload['htu']);
    $this->assertArrayHasKey('iat', $payload);
    $this->assertIsInt($payload['iat']);
  }

  /**
   * @covers ::createProof
   */
  public function testCreateProofIncludesNonceWhenProvided(): void {
    $jwk = $this->dpop->generateKey();
    $proof = $this->dpop->createProof($jwk, 'POST', 'https://example.com/token', 'server-nonce-123');

    $parts = explode('.', $proof);
    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), TRUE);

    $this->assertSame('server-nonce-123', $payload['nonce']);
  }

  /**
   * @covers ::createProof
   */
  public function testCreateProofIncludesAthWhenAccessTokenProvided(): void {
    $jwk = $this->dpop->generateKey();
    $proof = $this->dpop->createProof($jwk, 'GET', 'https://example.com/api', NULL, 'my-access-token');

    $parts = explode('.', $proof);
    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), TRUE);

    $this->assertArrayHasKey('ath', $payload);
    // ath should be base64url(sha256(token)).
    $expected = rtrim(strtr(base64_encode(hash('sha256', 'my-access-token', TRUE)), '+/', '-_'), '=');
    $this->assertSame($expected, $payload['ath']);
  }

  /**
   * @covers ::createProof
   */
  public function testCreateProofLooksUpStoredNonceWhenNoneProvided(): void {
    $this->nonceStorage->expects($this->once())
      ->method('get')
      ->with('https://example.com/token')
      ->willReturn('stored-nonce');

    $jwk = $this->dpop->generateKey();
    $proof = $this->dpop->createProof($jwk, 'POST', 'https://example.com/token');

    $parts = explode('.', $proof);
    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), TRUE);

    $this->assertSame('stored-nonce', $payload['nonce']);
  }

  /**
   * @covers ::persistNonce
   */
  public function testPersistNonceDelegatesToStorage(): void {
    $this->nonceStorage->expects($this->once())
      ->method('set')
      ->with('https://example.com/token', 'new-nonce');

    $this->dpop->persistNonce('https://example.com/token', 'new-nonce');
  }

}
