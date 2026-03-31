<?php

declare(strict_types=1);

namespace Drupal\Tests\atmosphere\Unit\OAuth;

use Drupal\atmosphere\OAuth\Resolver;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\atmosphere\OAuth\Resolver
 */
class ResolverTest extends TestCase {

  /**
   * Creates a resolver with mocked HTTP responses.
   */
  private function createResolver(array $responses): Resolver {
    $mock = new MockHandler($responses);
    $handlerStack = HandlerStack::create($mock);
    $httpClient = new Client(['handler' => $handlerStack]);

    return new Resolver($httpClient);
  }

  /**
   * @covers ::handleToDid
   */
  public function testHandleToDidViaHttpFallback(): void {
    $resolver = $this->createResolver([
      new Response(200, ['Content-Type' => 'text/plain'], 'did:plc:abc123'),
    ]);

    $did = $resolver->handleToDid('test.bsky.social');
    $this->assertSame('did:plc:abc123', $did);
  }

  /**
   * @covers ::handleToDid
   */
  public function testHandleToDidStripsAtSign(): void {
    $resolver = $this->createResolver([
      new Response(200, ['Content-Type' => 'text/plain'], 'did:plc:abc123'),
    ]);

    $did = $resolver->handleToDid('@test.bsky.social');
    $this->assertSame('did:plc:abc123', $did);
  }

  /**
   * @covers ::handleToDid
   */
  public function testHandleToDidThrowsOnFailure(): void {
    $resolver = $this->createResolver([
      new Response(404),
    ]);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage("Could not resolve handle 'nonexistent.handle'");
    $resolver->handleToDid('nonexistent.handle');
  }

  /**
   * @covers ::resolveDid
   */
  public function testResolveDidPlc(): void {
    $didDoc = [
      'id' => 'did:plc:abc123',
      'service' => [
        [
          'id' => '#atproto_pds',
          'type' => 'AtprotoPersonalDataServer',
          'serviceEndpoint' => 'https://pds.example.com',
        ],
      ],
    ];

    $resolver = $this->createResolver([
      new Response(200, ['Content-Type' => 'application/json'], json_encode($didDoc)),
    ]);

    $doc = $resolver->resolveDid('did:plc:abc123');
    $this->assertSame('did:plc:abc123', $doc['id']);
  }

  /**
   * @covers ::resolveDid
   */
  public function testResolveDidWeb(): void {
    $didDoc = [
      'id' => 'did:web:example.com',
      'service' => [],
    ];

    $resolver = $this->createResolver([
      new Response(200, ['Content-Type' => 'application/json'], json_encode($didDoc)),
    ]);

    $doc = $resolver->resolveDid('did:web:example.com');
    $this->assertSame('did:web:example.com', $doc['id']);
  }

  /**
   * @covers ::resolveDid
   */
  public function testResolveDidThrowsForUnsupportedMethod(): void {
    $resolver = $this->createResolver([]);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Unsupported DID method');
    $resolver->resolveDid('did:key:z6MkhaXgBZD...');
  }

  /**
   * @covers ::pdsFromDidDoc
   */
  public function testPdsFromDidDocExtractsEndpoint(): void {
    $resolver = $this->createResolver([]);

    $didDoc = [
      'id' => 'did:plc:abc123',
      'service' => [
        [
          'id' => '#atproto_pds',
          'type' => 'AtprotoPersonalDataServer',
          'serviceEndpoint' => 'https://pds.example.com/',
        ],
      ],
    ];

    $pds = $resolver->pdsFromDidDoc($didDoc);
    // Should strip trailing slash.
    $this->assertSame('https://pds.example.com', $pds);
  }

  /**
   * @covers ::pdsFromDidDoc
   */
  public function testPdsFromDidDocThrowsWhenMissing(): void {
    $resolver = $this->createResolver([]);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('No AtprotoPersonalDataServer found');
    $resolver->pdsFromDidDoc(['id' => 'did:plc:test', 'service' => []]);
  }

  /**
   * @covers ::discoverAuthServer
   */
  public function testDiscoverAuthServer(): void {
    $resourceMeta = [
      'authorization_servers' => ['https://auth.bsky.social'],
    ];
    $authMeta = [
      'issuer' => 'https://auth.bsky.social',
      'authorization_endpoint' => 'https://auth.bsky.social/authorize',
      'token_endpoint' => 'https://auth.bsky.social/token',
      'pushed_authorization_request_endpoint' => 'https://auth.bsky.social/par',
    ];

    $resolver = $this->createResolver([
      new Response(200, ['Content-Type' => 'application/json'], json_encode($resourceMeta)),
      new Response(200, ['Content-Type' => 'application/json'], json_encode($authMeta)),
    ]);

    $server = $resolver->discoverAuthServer('https://pds.example.com');

    $this->assertSame('https://auth.bsky.social', $server['issuer']);
    $this->assertSame('https://auth.bsky.social/authorize', $server['authorization_endpoint']);
    $this->assertSame('https://auth.bsky.social/token', $server['token_endpoint']);
    $this->assertArrayHasKey('issuer_url', $server);
  }

  /**
   * @covers ::resolve
   */
  public function testFullResolveChain(): void {
    $didDoc = [
      'id' => 'did:plc:abc123',
      'service' => [
        [
          'id' => '#atproto_pds',
          'type' => 'AtprotoPersonalDataServer',
          'serviceEndpoint' => 'https://pds.example.com',
        ],
      ],
    ];

    $resourceMeta = [
      'authorization_servers' => ['https://auth.example.com'],
    ];

    $authMeta = [
      'issuer' => 'https://auth.example.com',
      'authorization_endpoint' => 'https://auth.example.com/authorize',
      'token_endpoint' => 'https://auth.example.com/token',
    ];

    $resolver = $this->createResolver([
      // handleToDid: well-known HTTP.
      new Response(200, ['Content-Type' => 'text/plain'], 'did:plc:abc123'),
      // resolveDid: PLC directory.
      new Response(200, ['Content-Type' => 'application/json'], json_encode($didDoc)),
      // discoverAuthServer: protected resource metadata.
      new Response(200, ['Content-Type' => 'application/json'], json_encode($resourceMeta)),
      // discoverAuthServer: auth server metadata.
      new Response(200, ['Content-Type' => 'application/json'], json_encode($authMeta)),
    ]);

    $result = $resolver->resolve('test.bsky.social');

    $this->assertSame('did:plc:abc123', $result['did']);
    $this->assertSame('https://pds.example.com', $result['pds_endpoint']);
    $this->assertSame('https://auth.example.com/token', $result['auth_server']['token_endpoint']);
  }

}
