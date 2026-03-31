<?php

declare(strict_types=1);

namespace Drupal\atmosphere\OAuth;

use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;

/**
 * DPoP nonce persistence using Drupal's expirable key-value store.
 *
 * Nonces are stored with a 5-minute TTL, keyed by the MD5 hash of the
 * request URL they apply to.
 */
class NonceStorage {

  private const TTL = 300;

  private readonly KeyValueStoreExpirableInterface $store;

  public function __construct(KeyValueExpirableFactoryInterface $keyValueFactory) {
    $this->store = $keyValueFactory->get('atmosphere.dpop_nonce');
  }

  /**
   * Retrieves a stored nonce for the given URL.
   */
  public function get(string $url): ?string {
    $value = $this->store->get('nonce_' . md5($url));

    return is_string($value) ? $value : NULL;
  }

  /**
   * Stores a nonce for the given URL with a 5-minute TTL.
   */
  public function set(string $url, string $nonce): void {
    $this->store->setWithExpire('nonce_' . md5($url), $nonce, self::TTL);
  }

}
