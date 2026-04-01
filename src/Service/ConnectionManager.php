<?php

declare(strict_types=1);

namespace Drupal\atmosphere\Service;

use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\State\StateInterface;

/**
 * Manages the AT Protocol connection state.
 *
 * Wraps Drupal's State API for persistent connection data (encrypted tokens,
 * DID, PDS endpoint) and KeyValueExpirable for short-lived OAuth flow state.
 */
class ConnectionManager {

  private const STATE_KEY = 'atmosphere.connection';

  private readonly KeyValueStoreExpirableInterface $transientStore;

  public function __construct(
    private readonly StateInterface $state,
    KeyValueExpirableFactoryInterface $keyValueFactory,
  ) {
    $this->transientStore = $keyValueFactory->get('atmosphere.oauth_transient');
  }

  /**
   * Checks if an AT Protocol account is connected.
   *
   * @return bool
   *   true if a DID and access token are present in state.
   */
  public function isConnected(): bool {
    $connection = $this->getConnection();
    return !empty($connection['access_token']) && !empty($connection['did']);
  }

  /**
   * Returns the full connection data array.
   *
   * @return array
   *   The raw connection state array stored in Drupal state.
   */
  public function getConnection(): array {
    return $this->state->get(self::STATE_KEY, []);
  }

  /**
   * Returns the connected DID.
   *
   * @return string
   *   The AT Protocol DID (e.g., did:plc:abc123), or empty string if not set.
   */
  public function getDid(): string {
    return $this->getConnection()['did'] ?? '';
  }

  /**
   * Returns the connected handle.
   *
   * @return string
   *   The AT Protocol handle (e.g., user.bsky.social), or empty string.
   */
  public function getHandle(): string {
    return $this->getConnection()['handle'] ?? '';
  }

  /**
   * Returns the PDS endpoint URL.
   *
   * @return string
   *   The base URL of the Personal Data Server, or empty string.
   */
  public function getPdsEndpoint(): string {
    return $this->getConnection()['pds_endpoint'] ?? '';
  }

  /**
   * Returns the token endpoint URL.
   *
   * @return string
   *   The OAuth token endpoint URL, or empty string.
   */
  public function getTokenEndpoint(): string {
    return $this->getConnection()['token_endpoint'] ?? '';
  }

  /**
   * Returns the auth server metadata.
   *
   * @return array
   *   The authorization server metadata array, or empty array.
   */
  public function getAuthServer(): array {
    return $this->getConnection()['auth_server'] ?? [];
  }

  /**
   * Returns the encrypted access token.
   *
   * @return string
   *   The encrypted access token string, or empty string.
   */
  public function getAccessToken(): string {
    return $this->getConnection()['access_token'] ?? '';
  }

  /**
   * Returns the encrypted refresh token.
   *
   * @return string
   *   The encrypted refresh token string, or empty string.
   */
  public function getRefreshToken(): string {
    return $this->getConnection()['refresh_token'] ?? '';
  }

  /**
   * Returns the encrypted DPoP JWK.
   *
   * @return string
   *   The encrypted DPoP JSON Web Key string, or empty string.
   */
  public function getDpopJwk(): string {
    return $this->getConnection()['dpop_jwk'] ?? '';
  }

  /**
   * Returns the token expiration timestamp.
   *
   * @return int
   *   Unix timestamp when the access token expires, or 0 if unknown.
   */
  public function getExpiresAt(): int {
    return (int) ($this->getConnection()['expires_at'] ?? 0);
  }

  /**
   * Stores the full connection data.
   */
  public function setConnection(array $data): void {
    $this->state->set(self::STATE_KEY, $data);
  }

  /**
   * Updates specific keys in the connection data.
   */
  public function updateConnection(array $data): void {
    $current = $this->getConnection();
    $this->state->set(self::STATE_KEY, array_merge($current, $data));
  }

  /**
   * Clears all connection data.
   */
  public function clearConnection(): void {
    $this->state->delete(self::STATE_KEY);
  }

  /**
   * Retrieves a short-lived OAuth transient value.
   */
  public function getOAuthTransient(string $key): mixed {
    return $this->transientStore->get($key);
  }

  /**
   * Stores a short-lived OAuth transient value.
   *
   * @param int $ttl
   *   Time to live in seconds. Defaults to 1 hour.
   */
  public function setOAuthTransient(string $key, mixed $value, int $ttl = 3600): void {
    $this->transientStore->setWithExpire($key, $value, $ttl);
  }

  /**
   * Deletes a short-lived OAuth transient value.
   */
  public function deleteOAuthTransient(string $key): void {
    $this->transientStore->delete($key);
  }

}
