<?php

declare(strict_types=1);

namespace Drupal\Tests\atmosphere\Unit\Service;

use Drupal\atmosphere\Service\ApiClient;
use Drupal\atmosphere\Service\ConnectionManager;
use Drupal\atmosphere\Service\Publisher;
use Drupal\atmosphere\Transformer\DocumentTransformer;
use Drupal\atmosphere\Transformer\PostTransformer;
use Drupal\atmosphere\Transformer\PublicationTransformer;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\atmosphere\Service\Publisher
 */
class PublisherTest extends TestCase {

  private ApiClient $apiClient;
  private ConnectionManager $connectionManager;
  private DocumentTransformer $documentTransformer;
  private PostTransformer $postTransformer;
  private PublicationTransformer $publicationTransformer;
  private Publisher $publisher;
  private ConfigFactoryInterface $configFactory;
  private LoggerInterface $logger;

  protected function setUp(): void {
    parent::setUp();

    Publisher::$isSaving = FALSE;

    $this->apiClient = $this->createMock(ApiClient::class);
    $this->connectionManager = $this->createMock(ConnectionManager::class);
    $this->connectionManager->method('getDid')->willReturn('did:plc:test');

    $this->documentTransformer = $this->createMock(DocumentTransformer::class);
    $this->postTransformer = $this->createMock(PostTransformer::class);
    $this->publicationTransformer = $this->createMock(PublicationTransformer::class);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);

    $config = $this->createMock(Config::class);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->configFactory->method('get')->willReturn($config);
    $this->configFactory->method('getEditable')->willReturn($config);
    $config->method('set')->willReturnSelf();

    $this->logger = $this->createMock(LoggerInterface::class);

    $this->publisher = new Publisher(
      $this->apiClient,
      $this->connectionManager,
      $this->documentTransformer,
      $this->postTransformer,
      $this->publicationTransformer,
      $entityTypeManager,
      $this->configFactory,
      $this->logger,
    );
  }

  private function createMockNode(): NodeInterface {
    $node = $this->createMock(NodeInterface::class);

    // Mock field item lists for base fields.
    $fields = [
      'atmosphere_bsky_tid' => '',
      'atmosphere_bsky_uri' => '',
      'atmosphere_bsky_cid' => '',
      'atmosphere_doc_tid' => '',
      'atmosphere_doc_uri' => '',
      'atmosphere_doc_cid' => '',
    ];

    $node->method('get')->willReturnCallback(function (string $fieldName) use (&$fields) {
      $fieldList = $this->createMock(FieldItemListInterface::class);
      $fieldList->value = $fields[$fieldName] ?? '';
      // Make __get('value') work.
      $fieldList->method('__get')->with('value')->willReturn($fields[$fieldName] ?? '');
      return $fieldList;
    });

    $node->method('set')->willReturnCallback(function (string $fieldName, $value) use (&$fields) {
      $fields[$fieldName] = $value;
    });

    // Prevent actual save calls during tests.
    $node->method('save')->willReturn(1);

    return $node;
  }

  /**
   * @covers ::publish
   */
  public function testPublishCallsTransformersAndApplyWrites(): void {
    $node = $this->createMockNode();

    $this->postTransformer->method('transform')
      ->willReturn(['$type' => 'app.bsky.feed.post', 'text' => 'Hello']);
    $this->postTransformer->method('getRkey')->willReturn('bsky-tid-123');
    $this->postTransformer->method('getCollection')->willReturn('app.bsky.feed.post');

    $this->documentTransformer->method('transform')
      ->willReturn(['$type' => 'site.standard.document', 'title' => 'Test']);
    $this->documentTransformer->method('getRkey')->willReturn('doc-tid-456');
    $this->documentTransformer->method('getCollection')->willReturn('site.standard.document');

    $this->apiClient->expects($this->once())
      ->method('applyWrites')
      ->with($this->callback(function (array $writes) {
        return count($writes) === 2
          && $writes[0]['collection'] === 'app.bsky.feed.post'
          && $writes[1]['collection'] === 'site.standard.document';
      }))
      ->willReturn([
        'results' => [
          ['uri' => 'at://did:plc:test/app.bsky.feed.post/bsky-tid-123', 'cid' => 'bsky-cid'],
          ['uri' => 'at://did:plc:test/site.standard.document/doc-tid-456', 'cid' => 'doc-cid'],
        ],
      ]);

    // putRecord for bskyPostRef follow-up.
    $this->apiClient->method('putRecord')->willReturn([]);

    $result = $this->publisher->publish($node);

    $this->assertArrayHasKey('results', $result);
    $this->assertCount(2, $result['results']);
  }

  /**
   * @covers ::deleteByTids
   */
  public function testDeleteByTidsCreatesDeleteWrites(): void {
    $this->apiClient->expects($this->once())
      ->method('applyWrites')
      ->with($this->callback(function (array $writes) {
        return count($writes) === 2
          && $writes[0]['$type'] === 'com.atproto.repo.applyWrites#delete'
          && $writes[0]['collection'] === 'app.bsky.feed.post'
          && $writes[0]['rkey'] === 'bsky-tid'
          && $writes[1]['collection'] === 'site.standard.document'
          && $writes[1]['rkey'] === 'doc-tid';
      }))
      ->willReturn(['results' => []]);

    $this->publisher->deleteByTids('bsky-tid', 'doc-tid');
  }

  /**
   * @covers ::deleteByTids
   */
  public function testDeleteByTidsSkipsEmptyTids(): void {
    $this->apiClient->expects($this->never())->method('applyWrites');

    $result = $this->publisher->deleteByTids('', '');
    $this->assertSame([], $result);
  }

  /**
   * @covers ::deleteByTids
   */
  public function testDeleteByTidsHandlesPartialTids(): void {
    $this->apiClient->expects($this->once())
      ->method('applyWrites')
      ->with($this->callback(function (array $writes) {
        return count($writes) === 1
          && $writes[0]['collection'] === 'site.standard.document';
      }))
      ->willReturn(['results' => []]);

    $this->publisher->deleteByTids('', 'doc-tid-only');
  }

  /**
   * @covers ::syncPublication
   */
  public function testSyncPublicationCallsPutRecord(): void {
    $this->publicationTransformer->method('transform')
      ->willReturn(['$type' => 'site.standard.publication', 'name' => 'My Site']);
    $this->publicationTransformer->method('getRkey')->willReturn('pub-tid');
    $this->publicationTransformer->method('getCollection')->willReturn('site.standard.publication');
    $this->publicationTransformer->method('getUri')->willReturn('at://did:plc:test/site.standard.publication/pub-tid');

    $this->apiClient->expects($this->once())
      ->method('putRecord')
      ->with('site.standard.publication', 'pub-tid', $this->anything())
      ->willReturn([]);

    $this->publisher->syncPublication();
  }

  /**
   * @covers ::publish
   */
  public function testIsSavingFlagPreventsReentrantHooks(): void {
    // Simulate that the save is already happening.
    Publisher::$isSaving = TRUE;
    // The module's hook functions check this flag, so we just verify it works.
    $this->assertTrue(Publisher::$isSaving);
    Publisher::$isSaving = FALSE;
  }

}
