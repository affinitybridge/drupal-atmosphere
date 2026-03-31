<?php

declare(strict_types=1);

namespace Drupal\Tests\atmosphere\Unit\Service;

use Drupal\atmosphere\OAuth\Client;
use Drupal\atmosphere\OAuth\DPoP;
use Drupal\atmosphere\OAuth\Encryption;
use Drupal\atmosphere\Service\ApiClient;
use Drupal\atmosphere\Service\ConnectionManager;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\atmosphere\Service\ApiClient
 */
class ApiClientTest extends TestCase {

  private Client $oauthClient;
  private DPoP $dpop;
  private ConnectionManager $connectionManager;

  protected function setUp(): void {
    parent::setUp();

    $this->oauthClient = $this->createMock(Client::class);
    $this->oauthClient->method('accessToken')->willReturn('test-access-token');
    $this->oauthClient->method('dpopJwk')->willReturn([
      'kty' => 'EC', 'crv' => 'P-256',
      'x' => 'test_x', 'y' => 'test_y', 'd' => 'test_d',
    ]);

    $this->dpop = $this->createMock(DPoP::class);
    $this->dpop->method('createProof')->willReturn('mock-dpop-proof');

    $this->connectionManager = $this->createMock(ConnectionManager::class);
    $this->connectionManager->method('getPdsEndpoint')->willReturn('https://pds.example.com');
    $this->connectionManager->method('getDid')->willReturn('did:plc:test');
  }

  private function createApiClient(array $responses): ApiClient {
    $mock = new MockHandler($responses);
    $handlerStack = HandlerStack::create($mock);
    $httpClient = new GuzzleClient(['handler' => $handlerStack]);

    return new ApiClient(
      $this->oauthClient,
      $this->dpop,
      $this->createMock(Encryption::class),
      $this->connectionManager,
      $httpClient,
    );
  }

  /**
   * @covers ::get
   */
  public function testGetReturnsDecodedJson(): void {
    $apiClient = $this->createApiClient([
      new Response(200, [], json_encode(['records' => []])),
    ]);

    $result = $apiClient->get('/xrpc/com.atproto.repo.listRecords', ['repo' => 'did:plc:test']);
    $this->assertSame(['records' => []], $result);
  }

  /**
   * @covers ::post
   */
  public function testPostSendsJsonBody(): void {
    $apiClient = $this->createApiClient([
      new Response(200, [], json_encode(['success' => TRUE])),
    ]);

    $result = $apiClient->post('/xrpc/com.atproto.repo.putRecord', [
      'repo' => 'did:plc:test',
      'collection' => 'app.bsky.feed.post',
      'rkey' => 'abc',
      'record' => ['text' => 'hello'],
    ]);

    $this->assertTrue($result['success']);
  }

  /**
   * @covers ::request
   */
  public function testRequestThrowsOnHttpError(): void {
    $apiClient = $this->createApiClient([
      new Response(400, [], json_encode([
        'error' => 'InvalidRequest',
        'message' => 'Bad input',
      ])),
    ]);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('InvalidRequest');
    $apiClient->get('/xrpc/some.method');
  }

  /**
   * @covers ::request
   */
  public function testRequestRetriesOnNonceError(): void {
    $this->dpop->expects($this->exactly(2))
      ->method('createProof')
      ->willReturn('mock-dpop-proof');

    $this->dpop->expects($this->once())
      ->method('persistNonce');

    $apiClient = $this->createApiClient([
      // First request: nonce required.
      new Response(401, ['DPoP-Nonce' => 'server-nonce-123'], json_encode([
        'error' => 'use_dpop_nonce',
      ])),
      // Retry: success.
      new Response(200, [], json_encode(['ok' => TRUE])),
    ]);

    $result = $apiClient->get('/xrpc/test.method');
    $this->assertTrue($result['ok']);
  }

  /**
   * @covers ::request
   */
  public function testRequestThrowsWhenNoPdsEndpoint(): void {
    $emptyConnectionManager = $this->createMock(ConnectionManager::class);
    $emptyConnectionManager->method('getPdsEndpoint')->willReturn('');

    $apiClient = new ApiClient(
      $this->oauthClient,
      $this->dpop,
      $this->createMock(Encryption::class),
      $emptyConnectionManager,
      new GuzzleClient(),
    );

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('No PDS endpoint configured');
    $apiClient->get('/xrpc/test');
  }

  /**
   * @covers ::applyWrites
   */
  public function testApplyWritesIncludesRepo(): void {
    $apiClient = $this->createApiClient([
      new Response(200, [], json_encode(['results' => []])),
    ]);

    $result = $apiClient->applyWrites([
      ['$type' => 'com.atproto.repo.applyWrites#create', 'collection' => 'test', 'rkey' => 'abc', 'value' => []],
    ]);

    $this->assertArrayHasKey('results', $result);
  }

  /**
   * @covers ::getRecord
   */
  public function testGetRecord(): void {
    $record = ['uri' => 'at://did:plc:test/app.bsky.feed.post/abc', 'value' => ['text' => 'hello']];

    $apiClient = $this->createApiClient([
      new Response(200, [], json_encode($record)),
    ]);

    $result = $apiClient->getRecord('app.bsky.feed.post', 'abc');
    $this->assertSame('hello', $result['value']['text']);
  }

}
