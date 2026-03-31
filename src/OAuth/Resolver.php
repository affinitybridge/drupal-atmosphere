<?php

declare(strict_types=1);

namespace Drupal\atmosphere\OAuth;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * AT Protocol identity resolution chain.
 *
 * Resolves: Handle -> DID -> DID Document -> PDS -> Auth Server.
 */
class Resolver {

  public function __construct(
    private readonly ClientInterface $httpClient,
  ) {}

  /**
   * Full resolution chain from handle to PDS and auth server.
   *
   * @return array{did: string, pds_endpoint: string, auth_server: array}
   *
   * @throws \RuntimeException
   */
  public function resolve(string $handle): array {
    $did = $this->handleToDid($handle);
    $didDoc = $this->resolveDid($did);
    $pds = $this->pdsFromDidDoc($didDoc);
    $authServer = $this->discoverAuthServer($pds);

    return [
      'did' => $did,
      'pds_endpoint' => $pds,
      'auth_server' => $authServer,
    ];
  }

  /**
   * Resolves a handle to a DID.
   *
   * Tries DNS TXT record first, then falls back to well-known HTTP.
   */
  public function handleToDid(string $handle): string {
    $handle = ltrim($handle, '@');

    // Try DNS TXT record: _atproto.<handle>.
    $records = @dns_get_record('_atproto.' . $handle, DNS_TXT);
    if ($records) {
      foreach ($records as $record) {
        if (isset($record['txt']) && str_starts_with($record['txt'], 'did=')) {
          return substr($record['txt'], 4);
        }
      }
    }

    // Fallback: HTTPS well-known.
    try {
      $response = $this->httpClient->request('GET', 'https://' . $handle . '/.well-known/atproto-did', [
        'timeout' => 10,
        'headers' => ['Accept' => 'text/plain'],
      ]);
      $did = trim((string) $response->getBody());

      if (str_starts_with($did, 'did:')) {
        return $did;
      }
    }
    catch (GuzzleException) {
      // Fall through to error.
    }

    throw new \RuntimeException("Could not resolve handle '{$handle}' to a DID.");
  }

  /**
   * Resolves a DID to its DID Document.
   */
  public function resolveDid(string $did): array {
    if (str_starts_with($did, 'did:plc:')) {
      $url = 'https://plc.directory/' . $did;
    }
    elseif (str_starts_with($did, 'did:web:')) {
      $domain = substr($did, 8);
      $url = 'https://' . $domain . '/.well-known/did.json';
    }
    else {
      throw new \RuntimeException("Unsupported DID method: {$did}");
    }

    try {
      $response = $this->httpClient->request('GET', $url, [
        'timeout' => 10,
        'headers' => ['Accept' => 'application/json'],
      ]);
      $doc = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);

      if (!is_array($doc)) {
        throw new \RuntimeException("Invalid DID document for {$did}.");
      }

      return $doc;
    }
    catch (GuzzleException $e) {
      throw new \RuntimeException("Failed to resolve DID '{$did}': " . $e->getMessage());
    }
  }

  /**
   * Extracts the PDS endpoint from a DID Document.
   */
  public function pdsFromDidDoc(array $didDoc): string {
    $services = $didDoc['service'] ?? [];

    foreach ($services as $service) {
      if (
        ($service['id'] === '#atproto_pds' || ($service['id'] ?? '') === ($didDoc['id'] ?? '') . '#atproto_pds')
        && ($service['type'] ?? '') === 'AtprotoPersonalDataServer'
      ) {
        return rtrim($service['serviceEndpoint'], '/');
      }
    }

    throw new \RuntimeException('No AtprotoPersonalDataServer found in DID document.');
  }

  /**
   * Discovers the OAuth authorization server for a PDS.
   */
  public function discoverAuthServer(string $pdsUrl): array {
    try {
      // Step 1: Get the issuer from the protected resource metadata.
      $response = $this->httpClient->request('GET', $pdsUrl . '/.well-known/oauth-protected-resource', [
        'timeout' => 10,
        'headers' => ['Accept' => 'application/json'],
      ]);
      $resource = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
      $issuer = $resource['authorization_servers'][0] ?? NULL;

      if (!$issuer) {
        throw new \RuntimeException('No authorization server found in protected resource metadata.');
      }

      // Step 2: Get full auth server metadata.
      $response = $this->httpClient->request('GET', $issuer . '/.well-known/oauth-authorization-server', [
        'timeout' => 10,
        'headers' => ['Accept' => 'application/json'],
      ]);
      $metadata = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);

      if (!is_array($metadata)) {
        throw new \RuntimeException('Invalid authorization server metadata.');
      }

      $metadata['issuer_url'] = $issuer;

      return $metadata;
    }
    catch (GuzzleException $e) {
      throw new \RuntimeException('Failed to discover auth server: ' . $e->getMessage());
    }
  }

}
