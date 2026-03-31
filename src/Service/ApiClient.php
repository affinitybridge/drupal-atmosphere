<?php

declare(strict_types=1);

namespace Drupal\atmosphere\Service;

use Drupal\atmosphere\OAuth\Client;
use Drupal\atmosphere\OAuth\DPoP;
use Drupal\atmosphere\OAuth\Encryption;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * DPoP-authenticated HTTP client for AT Protocol PDS operations.
 *
 * Wraps Guzzle with automatic DPoP proof generation, access token
 * injection, and nonce retry handling.
 */
class ApiClient {

  public function __construct(
    private readonly Client $oauthClient,
    private readonly DPoP $dpop,
    private readonly Encryption $encryption,
    private readonly ConnectionManager $connectionManager,
    private readonly ClientInterface $httpClient,
  ) {}

  /**
   * Sends an authenticated request to the PDS.
   *
   * @param string $method
   *   HTTP method (GET, POST, etc.).
   * @param string $endpoint
   *   The XRPC endpoint path (e.g., '/xrpc/com.atproto.repo.applyWrites').
   * @param array $options
   *   Additional Guzzle request options.
   * @param string|null $nonce
   *   An explicit DPoP nonce for retry.
   *
   * @return array
   *   Decoded JSON response body.
   *
   * @throws \RuntimeException
   */
  public function request(string $method, string $endpoint, array $options = [], ?string $nonce = NULL): array {
    $pds = $this->connectionManager->getPdsEndpoint();
    if (empty($pds)) {
      throw new \RuntimeException('No PDS endpoint configured.');
    }

    $url = $pds . $endpoint;
    $accessToken = $this->oauthClient->accessToken();
    $dpopJwk = $this->oauthClient->dpopJwk();

    $dpopProof = $this->dpop->createProof($dpopJwk, $method, $url, $nonce, $accessToken);
    if ($dpopProof === FALSE) {
      throw new \RuntimeException('Failed to create DPoP proof.');
    }

    $options['headers'] = array_merge($options['headers'] ?? [], [
      'Authorization' => 'DPoP ' . $accessToken,
      'DPoP' => $dpopProof,
    ]);

    $options['timeout'] = $options['timeout'] ?? 30;
    $options['http_errors'] = FALSE;

    try {
      $response = $this->httpClient->request($method, $url, $options);
    }
    catch (GuzzleException $e) {
      throw new \RuntimeException('PDS request failed: ' . $e->getMessage());
    }

    $statusCode = $response->getStatusCode();
    $body = json_decode((string) $response->getBody(), TRUE) ?? [];

    // Handle DPoP nonce retry (once).
    if ($nonce === NULL && $this->isNonceError($statusCode, $body)) {
      $newNonce = $response->getHeaderLine('DPoP-Nonce');
      if (!empty($newNonce)) {
        $this->dpop->persistNonce($url, $newNonce);
        return $this->request($method, $endpoint, $options, $newNonce);
      }
    }

    // Persist any nonce returned by the server for future requests.
    $responseNonce = $response->getHeaderLine('DPoP-Nonce');
    if (!empty($responseNonce)) {
      $this->dpop->persistNonce($url, $responseNonce);
    }

    if ($statusCode >= 400) {
      $error = $body['error'] ?? 'Unknown';
      $message = $body['message'] ?? ($body['error_description'] ?? '');
      throw new \RuntimeException("PDS request failed ({$statusCode}): {$error} — {$message}");
    }

    return $body;
  }

  /**
   * Sends a GET request.
   */
  public function get(string $endpoint, array $params = []): array {
    $options = [];
    if (!empty($params)) {
      $options['query'] = $params;
    }

    return $this->request('GET', $endpoint, $options);
  }

  /**
   * Sends a POST request with a JSON body.
   */
  public function post(string $endpoint, array $body = []): array {
    return $this->request('POST', $endpoint, [
      'json' => $body,
      'headers' => [
        'Content-Type' => 'application/json',
      ],
    ]);
  }

  /**
   * Uploads a blob (file) to the PDS.
   */
  public function uploadBlob(string $filePath, string $mimeType): array {
    $contents = file_get_contents($filePath);
    if ($contents === FALSE) {
      throw new \RuntimeException("Failed to read file: {$filePath}");
    }

    return $this->request('POST', '/xrpc/com.atproto.repo.uploadBlob', [
      'body' => $contents,
      'headers' => [
        'Content-Type' => $mimeType,
      ],
      'timeout' => 60,
    ]);
  }

  /**
   * Executes atomic batch writes.
   */
  public function applyWrites(array $writes): array {
    return $this->post('/xrpc/com.atproto.repo.applyWrites', [
      'repo' => $this->connectionManager->getDid(),
      'writes' => $writes,
    ]);
  }

  /**
   * Retrieves a single record from the PDS.
   */
  public function getRecord(string $collection, string $rkey): array {
    return $this->get('/xrpc/com.atproto.repo.getRecord', [
      'repo' => $this->connectionManager->getDid(),
      'collection' => $collection,
      'rkey' => $rkey,
    ]);
  }

  /**
   * Lists records in a collection.
   */
  public function listRecords(string $collection, int $limit = 50, ?string $cursor = NULL): array {
    $params = [
      'repo' => $this->connectionManager->getDid(),
      'collection' => $collection,
      'limit' => $limit,
    ];

    if ($cursor !== NULL) {
      $params['cursor'] = $cursor;
    }

    return $this->get('/xrpc/com.atproto.repo.listRecords', $params);
  }

  /**
   * Puts (creates or updates) a single record.
   */
  public function putRecord(string $collection, string $rkey, array $record): array {
    return $this->post('/xrpc/com.atproto.repo.putRecord', [
      'repo' => $this->connectionManager->getDid(),
      'collection' => $collection,
      'rkey' => $rkey,
      'record' => $record,
    ]);
  }

  /**
   * Checks if a response indicates a DPoP nonce is required.
   */
  private function isNonceError(int $statusCode, array $body): bool {
    return ($statusCode === 400 || $statusCode === 401)
      && ($body['error'] ?? '') === 'use_dpop_nonce';
  }

}
