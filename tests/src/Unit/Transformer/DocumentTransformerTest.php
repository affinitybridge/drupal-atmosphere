<?php

declare(strict_types=1);

namespace Drupal\Tests\atmosphere\Unit\Transformer;

use Drupal\atmosphere\Service\ApiClient;
use Drupal\atmosphere\Service\ConnectionManager;
use Drupal\atmosphere\Transformer\DocumentTransformer;
use Drupal\atmosphere\Transformer\TidGenerator;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\node\NodeInterface;
use Drupal\path_alias\AliasManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\atmosphere\Transformer\DocumentTransformer
 */
class DocumentTransformerTest extends TestCase {

  private DocumentTransformer $transformer;
  private ConnectionManager $connectionManager;
  private AliasManagerInterface $aliasManager;
  private TidGenerator $tidGenerator;
  private ConfigFactoryInterface $configFactory;

  protected function setUp(): void {
    parent::setUp();

    $this->tidGenerator = $this->createMock(TidGenerator::class);
    $this->tidGenerator->method('generate')->willReturn('test-tid-123');

    $this->connectionManager = $this->createMock(ConnectionManager::class);
    $this->connectionManager->method('getDid')->willReturn('did:plc:testuser');

    $apiClient = $this->createMock(ApiClient::class);

    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnMap([
      ['publication_uri', 'at://did:plc:testuser/site.standard.publication/pub-tid'],
      ['syncable_node_types', ['article']],
    ]);

    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->configFactory->method('get')->willReturn($config);

    $this->aliasManager = $this->createMock(AliasManagerInterface::class);
    $this->aliasManager->method('getAliasByPath')
      ->willReturnCallback(fn(string $path) => match ($path) {
        '/node/42' => '/blog/test-article',
        default => $path,
      });

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('alter');
    $moduleHandler->method('invokeAll')->willReturn([]);

    $fileSystem = $this->createMock(FileSystemInterface::class);

    $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
    $urlGenerator->method('generateFromRoute')
      ->willReturn('https://example.com/');

    $this->transformer = new DocumentTransformer(
      $this->tidGenerator,
      $this->connectionManager,
      $apiClient,
      $this->configFactory,
      $this->aliasManager,
      $moduleHandler,
      $fileSystem,
      $urlGenerator,
    );
  }

  /**
   * Helper to create a mock node with minimal fields.
   */
  private function createMockNode(array $overrides = []): NodeInterface {
    $node = $this->createMock(NodeInterface::class);

    $node->method('id')->willReturn($overrides['id'] ?? '42');
    $node->method('getTitle')->willReturn($overrides['title'] ?? 'Test Article');
    $node->method('getCreatedTime')->willReturn($overrides['created'] ?? 1711756800);
    $node->method('getChangedTime')->willReturn($overrides['changed'] ?? 1711756800);
    $node->method('bundle')->willReturn('article');

    // Field storage for atmosphere base fields.
    $fieldValues = [
      'atmosphere_doc_tid' => $overrides['doc_tid'] ?? '',
      'atmosphere_doc_uri' => $overrides['doc_uri'] ?? '',
      'atmosphere_doc_cid' => $overrides['doc_cid'] ?? '',
      'atmosphere_bsky_tid' => '',
      'atmosphere_bsky_uri' => $overrides['bsky_uri'] ?? '',
      'atmosphere_bsky_cid' => $overrides['bsky_cid'] ?? '',
    ];

    $node->method('get')->willReturnCallback(function (string $fieldName) use (&$fieldValues) {
      $fieldList = $this->createMock(FieldItemListInterface::class);
      $fieldList->value = $fieldValues[$fieldName] ?? '';
      $fieldList->summary = '';
      $fieldList->method('__get')->willReturnCallback(function (string $prop) use ($fieldName, &$fieldValues) {
        if ($prop === 'value') {
          return $fieldValues[$fieldName] ?? '';
        }
        return '';
      });
      $fieldList->method('isEmpty')->willReturn(empty($fieldValues[$fieldName]));
      return $fieldList;
    });

    $node->method('set')->willReturnCallback(function (string $fieldName, $value) use (&$fieldValues) {
      $fieldValues[$fieldName] = $value;
    });

    $node->method('hasField')->willReturnCallback(function (string $fieldName) {
      return in_array($fieldName, ['body', 'atmosphere_doc_tid', 'atmosphere_doc_uri', 'atmosphere_doc_cid', 'atmosphere_bsky_uri', 'atmosphere_bsky_cid'], TRUE);
    });

    $node->method('getFieldDefinitions')->willReturn([]);

    return $node;
  }

  /**
   * @covers ::getCollection
   */
  public function testGetCollectionReturnsSiteStandardDocument(): void {
    $this->assertSame('site.standard.document', $this->transformer->getCollection());
  }

  /**
   * @covers ::getRkey
   */
  public function testGetRkeyGeneratesNewTidWhenEmpty(): void {
    $node = $this->createMockNode();

    $rkey = $this->transformer->getRkey($node);
    $this->assertSame('test-tid-123', $rkey);
  }

  /**
   * @covers ::getRkey
   */
  public function testGetRkeyReusesExistingTid(): void {
    $node = $this->createMockNode(['doc_tid' => 'existing-tid-456']);

    $rkey = $this->transformer->getRkey($node);
    $this->assertSame('existing-tid-456', $rkey);
  }

  /**
   * @covers ::getUri
   */
  public function testGetUriBuildAtUri(): void {
    $node = $this->createMockNode(['doc_tid' => 'my-tid']);

    $uri = $this->transformer->getUri($node);
    $this->assertSame('at://did:plc:testuser/site.standard.document/my-tid', $uri);
  }

  /**
   * @covers ::transform
   */
  public function testTransformProducesCorrectRecordStructure(): void {
    $node = $this->createMockNode();

    $record = $this->transformer->transform($node);

    $this->assertSame('site.standard.document', $record['$type']);
    $this->assertSame('Test Article', $record['title']);
    $this->assertArrayHasKey('publishedAt', $record);
    $this->assertStringMatchesFormat('%d-%d-%dT%d:%d:%d.000Z', $record['publishedAt']);
  }

  /**
   * @covers ::transform
   */
  public function testTransformUsesPublicationUriAsSiteReference(): void {
    $node = $this->createMockNode();

    $record = $this->transformer->transform($node);

    $this->assertSame('at://did:plc:testuser/site.standard.publication/pub-tid', $record['site']);
  }

  /**
   * @covers ::transform
   */
  public function testTransformResolvesPathAlias(): void {
    $node = $this->createMockNode(['id' => '42']);

    $record = $this->transformer->transform($node);

    $this->assertSame('/blog/test-article', $record['path']);
  }

  /**
   * @covers ::transform
   */
  public function testTransformIncludesUpdatedAtWhenModified(): void {
    $node = $this->createMockNode([
      'created' => 1711756800,
      'changed' => 1711843200,
    ]);

    $record = $this->transformer->transform($node);

    $this->assertArrayHasKey('updatedAt', $record);
  }

  /**
   * @covers ::transform
   */
  public function testTransformOmitsUpdatedAtWhenNotModified(): void {
    $node = $this->createMockNode([
      'created' => 1711756800,
      'changed' => 1711756800,
    ]);

    $record = $this->transformer->transform($node);

    $this->assertArrayNotHasKey('updatedAt', $record);
  }

  /**
   * @covers ::transform
   */
  public function testTransformIncludesBskyPostRefWhenPresent(): void {
    $node = $this->createMockNode([
      'bsky_uri' => 'at://did:plc:testuser/app.bsky.feed.post/bsky-tid',
      'bsky_cid' => 'bafyreia...',
    ]);

    $record = $this->transformer->transform($node);

    $this->assertArrayHasKey('bskyPostRef', $record);
    $this->assertSame('at://did:plc:testuser/app.bsky.feed.post/bsky-tid', $record['bskyPostRef']['uri']);
    $this->assertSame('bafyreia...', $record['bskyPostRef']['cid']);
  }

  /**
   * Verifies the constructor accepts AliasManagerInterface (not the deprecated
   * Core\Path\PathAliasManagerInterface), which was the fix in eab5daa.
   *
   * @covers ::__construct
   */
  public function testConstructorAcceptsPathAliasAliasManagerInterface(): void {
    // If this test runs without a TypeError, the fix is working.
    // The AliasManagerInterface mock is injected in setUp().
    $this->assertInstanceOf(DocumentTransformer::class, $this->transformer);
  }

}
