<?php

declare(strict_types=1);

namespace Drupal\Tests\atmosphere\Unit\Service;

use Drupal\atmosphere\Service\ConnectionManager;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\State\StateInterface;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\atmosphere\Service\ConnectionManager
 */
class ConnectionManagerTest extends TestCase {

  private StateInterface $state;
  private KeyValueStoreExpirableInterface $transientStore;
  private ConnectionManager $manager;

  protected function setUp(): void {
    parent::setUp();

    $this->state = $this->createMock(StateInterface::class);
    $this->transientStore = $this->createMock(KeyValueStoreExpirableInterface::class);

    $keyValueFactory = $this->createMock(KeyValueExpirableFactoryInterface::class);
    $keyValueFactory->method('get')
      ->with('atmosphere.oauth_transient')
      ->willReturn($this->transientStore);

    $this->manager = new ConnectionManager($this->state, $keyValueFactory);
  }

  /**
   * @covers ::isConnected
   */
  public function testIsConnectedReturnsFalseWhenEmpty(): void {
    $this->state->method('get')
      ->with('atmosphere.connection', [])
      ->willReturn([]);

    $this->assertFalse($this->manager->isConnected());
  }

  /**
   * @covers ::isConnected
   */
  public function testIsConnectedReturnsTrueWithTokenAndDid(): void {
    $this->state->method('get')
      ->with('atmosphere.connection', [])
      ->willReturn([
        'access_token' => 'encrypted-token',
        'did' => 'did:plc:abc123',
      ]);

    $this->assertTrue($this->manager->isConnected());
  }

  /**
   * @covers ::getDid
   */
  public function testGetDidReturnsStoredDid(): void {
    $this->state->method('get')
      ->with('atmosphere.connection', [])
      ->willReturn(['did' => 'did:plc:test']);

    $this->assertSame('did:plc:test', $this->manager->getDid());
  }

  /**
   * @covers ::getDid
   */
  public function testGetDidReturnsEmptyWhenNotSet(): void {
    $this->state->method('get')
      ->with('atmosphere.connection', [])
      ->willReturn([]);

    $this->assertSame('', $this->manager->getDid());
  }

  /**
   * @covers ::setConnection
   */
  public function testSetConnectionStoresData(): void {
    $data = ['did' => 'did:plc:abc', 'access_token' => 'tok'];

    $this->state->expects($this->once())
      ->method('set')
      ->with('atmosphere.connection', $data);

    $this->manager->setConnection($data);
  }

  /**
   * @covers ::clearConnection
   */
  public function testClearConnectionDeletesState(): void {
    $this->state->expects($this->once())
      ->method('delete')
      ->with('atmosphere.connection');

    $this->manager->clearConnection();
  }

  /**
   * @covers ::setOAuthTransient
   */
  public function testSetOAuthTransientWithTtl(): void {
    $this->transientStore->expects($this->once())
      ->method('setWithExpire')
      ->with('my_key', 'my_value', 3600);

    $this->manager->setOAuthTransient('my_key', 'my_value');
  }

  /**
   * @covers ::getOAuthTransient
   */
  public function testGetOAuthTransient(): void {
    $this->transientStore->method('get')
      ->with('my_key')
      ->willReturn('stored_value');

    $this->assertSame('stored_value', $this->manager->getOAuthTransient('my_key'));
  }

  /**
   * @covers ::deleteOAuthTransient
   */
  public function testDeleteOAuthTransient(): void {
    $this->transientStore->expects($this->once())
      ->method('delete')
      ->with('my_key');

    $this->manager->deleteOAuthTransient('my_key');
  }

  /**
   * @covers ::updateConnection
   */
  public function testUpdateConnectionMergesData(): void {
    $this->state->method('get')
      ->with('atmosphere.connection', [])
      ->willReturn(['did' => 'did:plc:abc', 'handle' => 'old']);

    $this->state->expects($this->once())
      ->method('set')
      ->with('atmosphere.connection', [
        'did' => 'did:plc:abc',
        'handle' => 'new-handle',
      ]);

    $this->manager->updateConnection(['handle' => 'new-handle']);
  }

}
