<?php

declare(strict_types=1);

namespace Drupal\Tests\atmosphere\Unit\Controller;

use Drupal\atmosphere\Controller\WellKnownController;
use Drupal\atmosphere\Service\ConnectionManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @coversDefaultClass \Drupal\atmosphere\Controller\WellKnownController
 */
class WellKnownControllerTest extends TestCase {

  /**
   * @covers ::atprotoDid
   */
  public function testAtprotoDidReturnsDidAsPlainText(): void {
    $connectionManager = $this->createMock(ConnectionManager::class);
    $connectionManager->method('getDid')->willReturn('did:plc:abc123xyz');

    $controller = new WellKnownController($connectionManager);
    $response = $controller->atprotoDid();

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('did:plc:abc123xyz', $response->getContent());
    $this->assertStringContainsString('text/plain', $response->headers->get('Content-Type'));
    $this->assertStringContainsString('public', $response->headers->get('Cache-Control'));
  }

  /**
   * @covers ::atprotoDid
   */
  public function testAtprotoDidThrows404WhenNotConnected(): void {
    $connectionManager = $this->createMock(ConnectionManager::class);
    $connectionManager->method('getDid')->willReturn('');

    $controller = new WellKnownController($connectionManager);

    $this->expectException(NotFoundHttpException::class);
    $controller->atprotoDid();
  }

}
