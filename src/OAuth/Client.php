<?php

declare(strict_types=1);

namespace Drupal\atmosphere\OAuth;

use Drupal\atmosphere\Service\ConnectionManager;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * AT Protocol OAuth 2.1 client with PKCE + DPoP + PAR.
 *
 * Manages the full OAuth lifecycle: authorization, token exchange,
 * token refresh, and disconnection.
 */
class Client {

  private const SCOPES = 'atproto transition:generic';

  public function __construct(
    private readonly Resolver $resolver,
    private readonly DPoP $dpop,
    private readonly Encryption $encryption,
    private readonly ConnectionManager $connectionManager,
    private readonly ClientInterface $httpClient,
    private readonly UrlGeneratorInterface $urlGenerator,
  ) {}

  /**
   * Returns the OAuth client_id (the client metadata URL).
   *
   * In loopback dev mode, returns 'http://localhost' per AT Protocol spec,
   * which tells the auth server to skip fetching client metadata.
   *
   * @return string
   *   The absolute URL of the client metadata endpoint.
   */
  public function clientId(): string {
    if ($this->loopbackPort()) {
      return 'http://localhost';
    }
    return $this->urlGenerator->generateFromRoute(
      'atmosphere.client_metadata',
      [],
      ['absolute' => true],
    );
  }

  /**
   * Returns the OAuth redirect URI.
   *
   * In loopback dev mode, returns an http://127.0.0.1:{port} URI per
   * the AT Protocol localhost client spec.
   *
   * @return string
   *   The absolute URL of the OAuth callback route.
   */
  public function redirectUri(): string {
    if ($port = $this->loopbackPort()) {
      return 'http://127.0.0.1:' . $port;
    }
    return $this->urlGenerator->generateFromRoute(
      'atmosphere.oauth_callback',
      [],
      ['absolute' => true],
    );
  }

  /**
   * Returns the loopback port if dev mode is enabled, or NULL.
   */
  private function loopbackPort(): ?int {
    $port = Settings::get('atmosphere_loopback_port');
    return $port ? (int) $port : NULL;
  }

  /**
   * Initiates the OAuth authorization flow.
   *
   * @param string $handle
   *   The AT Protocol handle (e.g., user.bsky.social).
   *
   * @return string
   *   The authorization URL to redirect the user to.
   *
   * @throws \RuntimeException
   */
  public function authorize(string $handle): string {
    $handle = ltrim($handle, '@');

    // Resolve handle to DID, PDS, and auth server.
    $resolved = $this->resolver->resolve($handle);

    // Generate PKCE verifier and challenge.
    $verifier = self::generateVerifier();
    $challenge = self::generateChallenge($verifier);

    // Generate DPoP key pair.
    $dpopJwk = $this->dpop->generateKey();

    // Generate CSRF state token.
    $state = bin2hex(random_bytes(16));

    // Store OAuth flow state in expirable key-value (1 hour TTL).
    $this->connectionManager->setOAuthTransient('atmosphere_oauth_state', $state);
    $this->connectionManager->setOAuthTransient('atmosphere_oauth_verifier', $verifier);
    $this->connectionManager->setOAuthTransient('atmosphere_oauth_dpop_jwk', $dpopJwk);
    $this->connectionManager->setOAuthTransient('atmosphere_oauth_resolved', $resolved);

    // In loopback mode, store the real settings URL so the loopback callback
    // can redirect back to the correct domain after token exchange.
    if ($this->loopbackPort()) {
      $this->connectionManager->setOAuthTransient('atmosphere_oauth_return_url',
        $this->urlGenerator->generateFromRoute('atmosphere.settings', [], ['absolute' => TRUE])
      );
    }

    $authServer = $resolved['auth_server'];

    // Common authorization parameters.
    // Loopback clients only support the 'atproto' scope; the auth server
    // rejects 'transition:generic' because it isn't declared in client metadata
    // (and loopback clients have no fetchable metadata).
    $scope = $this->loopbackPort() ? 'atproto' : self::SCOPES;
    $params = [
      'response_type' => 'code',
      'client_id' => $this->clientId(),
      'redirect_uri' => $this->redirectUri(),
      'scope' => $scope,
      'state' => $state,
      'code_challenge' => $challenge,
      'code_challenge_method' => 'S256',
      'login_hint' => $resolved['did'],
    ];

    // Try PAR (Pushed Authorization Request) first.
    $parEndpoint = $authServer['pushed_authorization_request_endpoint'] ?? NULL;
    $authEndpoint = $authServer['authorization_endpoint'] ?? NULL;

    if (!$authEndpoint) {
      throw new \RuntimeException('No authorization endpoint found in auth server metadata.');
    }

    if ($parEndpoint) {
      try {
        return $this->authorizeViaPar($parEndpoint, $authEndpoint, $dpopJwk, $params);
      }
      catch (\RuntimeException) {
        // Fall through to plain authorization URL.
      }
    }

    // Fallback: direct authorization URL with query parameters.
    return $authEndpoint . '?' . http_build_query($params);
  }

  /**
   * Handles the OAuth callback after user authorization.
   *
   * @param string $code
   *   The authorization code.
   * @param string $state
   *   The CSRF state token.
   *
   * @throws \RuntimeException
   */
  public function handleCallback(string $code, string $state): void {
    // Validate CSRF state.
    $storedState = $this->connectionManager->getOAuthTransient('atmosphere_oauth_state');
    if (!$storedState || !hash_equals($storedState, $state)) {
      throw new \RuntimeException('Invalid OAuth state parameter.');
    }

    $verifier = $this->connectionManager->getOAuthTransient('atmosphere_oauth_verifier');
    $dpopJwk = $this->connectionManager->getOAuthTransient('atmosphere_oauth_dpop_jwk');
    $resolved = $this->connectionManager->getOAuthTransient('atmosphere_oauth_resolved');

    if (!$verifier || !$dpopJwk || !$resolved) {
      throw new \RuntimeException('OAuth flow state has expired. Please try again.');
    }

    $tokenEndpoint = $resolved['auth_server']['token_endpoint'] ?? NULL;
    if (!$tokenEndpoint) {
      throw new \RuntimeException('No token endpoint found in auth server metadata.');
    }

    // Exchange authorization code for tokens.
    $tokenData = $this->exchangeCode($tokenEndpoint, $code, $verifier, $dpopJwk);

    // Store the connection.
    $this->connectionManager->setConnection([
      'did' => $resolved['did'],
      'handle' => $tokenData['sub'] ?? $resolved['did'],
      'pds_endpoint' => $resolved['pds_endpoint'],
      'auth_server' => $resolved['auth_server'],
      'token_endpoint' => $tokenEndpoint,
      'access_token' => $this->encryption->encrypt($tokenData['access_token']),
      'refresh_token' => $this->encryption->encrypt($tokenData['refresh_token'] ?? ''),
      'dpop_jwk' => $this->encryption->encrypt(json_encode($dpopJwk)),
      'expires_at' => time() + (int) ($tokenData['expires_in'] ?? 3600),
    ]);

    // Clean up OAuth transients.
    $this->connectionManager->deleteOAuthTransient('atmosphere_oauth_state');
    $this->connectionManager->deleteOAuthTransient('atmosphere_oauth_verifier');
    $this->connectionManager->deleteOAuthTransient('atmosphere_oauth_dpop_jwk');
    $this->connectionManager->deleteOAuthTransient('atmosphere_oauth_resolved');
  }

  /**
   * Refreshes the access token using the refresh token.
   *
   * @throws \RuntimeException
   */
  public function refresh(): void {
    $connection = $this->connectionManager->getConnection();
    $tokenEndpoint = $connection['token_endpoint'] ?? '';

    if (empty($tokenEndpoint)) {
      throw new \RuntimeException('No token endpoint configured.');
    }

    $refreshToken = $this->encryption->decrypt($connection['refresh_token'] ?? '');
    if ($refreshToken === false || $refreshToken === '') {
      $this->connectionManager->clearConnection();
      throw new \RuntimeException('Failed to decrypt refresh token. Connection cleared.');
    }

    $dpopJwkJson = $this->encryption->decrypt($connection['dpop_jwk'] ?? '');
    if ($dpopJwkJson === false) {
      $this->connectionManager->clearConnection();
      throw new \RuntimeException('Failed to decrypt DPoP key. Connection cleared.');
    }

    $dpopJwk = json_decode($dpopJwkJson, true);

    $body = [
      'grant_type' => 'refresh_token',
      'refresh_token' => $refreshToken,
      'client_id' => $this->clientId(),
    ];

    $tokenData = $this->tokenRequest($tokenEndpoint, $body, $dpopJwk);

    // Update stored connection with new tokens.
    $this->connectionManager->updateConnection([
      'access_token' => $this->encryption->encrypt($tokenData['access_token']),
      'refresh_token' => $this->encryption->encrypt($tokenData['refresh_token'] ?? $refreshToken),
      'expires_at' => time() + (int) ($tokenData['expires_in'] ?? 3600),
    ]);
  }

  /**
   * Returns a usable access token, refreshing if needed.
   *
   * @return string
   *   The decrypted access token.
   *
   * @throws \RuntimeException
   */
  public function accessToken(): string {
    $connection = $this->connectionManager->getConnection();
    $expiresAt = (int) ($connection['expires_at'] ?? 0);

    // Refresh if expiring within 5 minutes.
    if ($expiresAt > 0 && $expiresAt < (time() + 300)) {
      $this->refresh();
      $connection = $this->connectionManager->getConnection();
    }

    $token = $this->encryption->decrypt($connection['access_token'] ?? '');
    if ($token === false || $token === '') {
      throw new \RuntimeException('Failed to decrypt access token.');
    }

    return $token;
  }

  /**
   * Returns the decrypted DPoP JWK.
   *
   * @return array
   *   The JWK as an associative array.
   *
   * @throws \RuntimeException
   */
  public function dpopJwk(): array {
    $connection = $this->connectionManager->getConnection();
    $jwkJson = $this->encryption->decrypt($connection['dpop_jwk'] ?? '');

    if ($jwkJson === false) {
      throw new \RuntimeException('Failed to decrypt DPoP key.');
    }

    return json_decode($jwkJson, true);
  }

  /**
   * Disconnects the AT Protocol account.
   *
   * @return void
   */
  public function disconnect(): void {
    $this->connectionManager->clearConnection();
  }

  /**
   * Sends a Pushed Authorization Request (PAR).
   */
  private function authorizeViaPar(string $parEndpoint, string $authEndpoint, array $dpopJwk, array $params): string {
    $dpopProof = $this->dpop->createProof($dpopJwk, 'POST', $parEndpoint);
    if ($dpopProof === false) {
      throw new \RuntimeException('Failed to create DPoP proof for PAR.');
    }

    $response = $this->sendParRequest($parEndpoint, $params, $dpopProof);

    // Handle nonce retry.
    if ($this->isNonceError($response)) {
      $nonce = $response['headers']['dpop-nonce'] ?? NULL;
      if ($nonce) {
        $this->dpop->persistNonce($parEndpoint, $nonce);
        $dpopProof = $this->dpop->createProof($dpopJwk, 'POST', $parEndpoint, $nonce);
        if ($dpopProof === false) {
          throw new \RuntimeException('Failed to create DPoP proof for PAR retry.');
        }
        $response = $this->sendParRequest($parEndpoint, $params, $dpopProof);
      }
    }

    if (($response['status'] ?? 0) >= 400) {
      throw new \RuntimeException('PAR request failed: ' . ($response['body']['error_description'] ?? 'Unknown error'));
    }

    $requestUri = $response['body']['request_uri'] ?? NULL;
    if (!$requestUri) {
      throw new \RuntimeException('No request_uri in PAR response.');
    }

    return $authEndpoint . '?' . http_build_query([
      'client_id' => $params['client_id'],
      'request_uri' => $requestUri,
    ]);
  }

  /**
   * Sends a PAR HTTP request.
   */
  private function sendParRequest(string $endpoint, array $params, string $dpopProof): array {
    try {
      $response = $this->httpClient->request('POST', $endpoint, [
        'form_params' => $params,
        'headers' => [
          'DPoP' => $dpopProof,
        ],
        'timeout' => 30,
        'http_errors' => false,
      ]);

      $headers = [];
      foreach ($response->getHeaders() as $name => $values) {
        $headers[strtolower($name)] = $values[0] ?? '';
      }

      return [
        'status' => $response->getStatusCode(),
        'body' => json_decode((string) $response->getBody(), true) ?? [],
        'headers' => $headers,
      ];
    }
    catch (GuzzleException $e) {
      throw new \RuntimeException('PAR request failed: ' . $e->getMessage());
    }
  }

  /**
   * Exchanges an authorization code for tokens.
   */
  private function exchangeCode(string $tokenEndpoint, string $code, string $verifier, array $dpopJwk): array {
    $body = [
      'grant_type' => 'authorization_code',
      'code' => $code,
      'redirect_uri' => $this->redirectUri(),
      'client_id' => $this->clientId(),
      'code_verifier' => $verifier,
    ];

    return $this->tokenRequest($tokenEndpoint, $body, $dpopJwk);
  }

  /**
   * Sends a token endpoint request with DPoP proof and nonce retry.
   */
  private function tokenRequest(string $tokenEndpoint, array $body, array $dpopJwk): array {
    $dpopProof = $this->dpop->createProof($dpopJwk, 'POST', $tokenEndpoint);
    if ($dpopProof === false) {
      throw new \RuntimeException('Failed to create DPoP proof for token request.');
    }

    $response = $this->sendTokenRequest($tokenEndpoint, $body, $dpopProof);

    // Handle nonce retry.
    if ($this->isNonceError($response)) {
      $nonce = $response['headers']['dpop-nonce'] ?? NULL;
      if ($nonce) {
        $this->dpop->persistNonce($tokenEndpoint, $nonce);
        $dpopProof = $this->dpop->createProof($dpopJwk, 'POST', $tokenEndpoint, $nonce);
        if ($dpopProof === false) {
          throw new \RuntimeException('Failed to create DPoP proof for token retry.');
        }
        $response = $this->sendTokenRequest($tokenEndpoint, $body, $dpopProof);
      }
    }

    if (($response['status'] ?? 0) >= 400) {
      throw new \RuntimeException('Token request failed: ' . ($response['body']['error_description'] ?? $response['body']['error'] ?? 'Unknown error'));
    }

    return $response['body'];
  }

  /**
   * Sends a token endpoint HTTP request.
   */
  private function sendTokenRequest(string $endpoint, array $body, string $dpopProof): array {
    try {
      $response = $this->httpClient->request('POST', $endpoint, [
        'form_params' => $body,
        'headers' => [
          'DPoP' => $dpopProof,
          'Content-Type' => 'application/x-www-form-urlencoded',
        ],
        'timeout' => 30,
        'http_errors' => false,
      ]);

      $headers = [];
      foreach ($response->getHeaders() as $name => $values) {
        $headers[strtolower($name)] = $values[0] ?? '';
      }

      return [
        'status' => $response->getStatusCode(),
        'body' => json_decode((string) $response->getBody(), true) ?? [],
        'headers' => $headers,
      ];
    }
    catch (GuzzleException $e) {
      throw new \RuntimeException('Token request failed: ' . $e->getMessage());
    }
  }

  /**
   * Checks if a response indicates a DPoP nonce is required.
   */
  private function isNonceError(array $response): bool {
    $status = $response['status'] ?? 0;
    $error = $response['body']['error'] ?? '';

    return ($status === 400 || $status === 401) && $error === 'use_dpop_nonce';
  }

  /**
   * Generates a PKCE code verifier.
   */
  private static function generateVerifier(): string {
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
  }

  /**
   * Generates a PKCE code challenge from a verifier (S256).
   */
  private static function generateChallenge(string $verifier): string {
    return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
  }

}
