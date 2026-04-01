<?php

declare(strict_types=1);

namespace Drupal\atmosphere\Transformer;

use Drupal\atmosphere\ContentParser\ContentParserInterface;
use Drupal\atmosphere\Service\ApiClient;
use Drupal\atmosphere\Service\ConnectionManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\node\NodeInterface;

/**
 * Transforms a Drupal node into a site.standard.document record.
 */
class DocumentTransformer extends TransformerBase {

  private const COLLECTION = 'site.standard.document';

  public function __construct(
    private readonly TidGenerator $tidGenerator,
    private readonly ConnectionManager $connectionManager,
    private readonly ApiClient $apiClient,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AliasManagerInterface $pathAliasManager,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly FileSystemInterface $fileSystem,
    private readonly UrlGeneratorInterface $urlGenerator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getCollection(): string {
    return self::COLLECTION;
  }

  /**
   * {@inheritdoc}
   */
  public function getRkey(object $entity): string {
    assert($entity instanceof NodeInterface);

    $tid = $entity->get('atmosphere_doc_tid')->value ?? '';
    if (empty($tid)) {
      $tid = $this->tidGenerator->generate();
      $entity->set('atmosphere_doc_tid', $tid);
    }

    return $tid;
  }

  /**
   * {@inheritdoc}
   */
  public function getUri(object $entity): string {
    return $this->buildAtUri(
      $this->connectionManager->getDid(),
      $this->getCollection(),
      $this->getRkey($entity),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function transform(object $entity): array {
    assert($entity instanceof NodeInterface);

    $record = [
      '$type' => self::COLLECTION,
      'title' => $entity->getTitle(),
      'publishedAt' => $this->toIso8601($entity->getCreatedTime()),
    ];

    // Site reference: publication AT-URI or home URL.
    $publicationUri = $this->configFactory->get('atmosphere.settings')->get('publication_uri');
    if (!empty($publicationUri)) {
      $record['site'] = $publicationUri;
    }
    else {
      $record['site'] = $this->urlGenerator->generateFromRoute('<front>', [], ['absolute' => true]);
    }

    // Relative path from the canonical URL.
    $record['path'] = $this->getRelativePath($entity);

    // Description (excerpt).
    $excerpt = $this->getExcerpt($entity, 55);
    if (!empty($excerpt)) {
      $record['description'] = $excerpt;
    }

    // Cover image.
    $coverImage = $this->getCoverImage($entity);
    if ($coverImage !== NULL) {
      $record['coverImage'] = $coverImage;
    }

    // Plain text content.
    $textContent = $this->getTextContent($entity);
    if (!empty($textContent)) {
      $record['textContent'] = $textContent;
    }

    // Parsed rich content (via content parser hook).
    $content = $this->getContent($entity);
    if ($content !== NULL) {
      $record['content'] = $content;
    }

    // Tags.
    $tags = $this->collectTags($entity);
    if (!empty($tags)) {
      $record['tags'] = $tags;
    }

    // Bluesky cross-reference (if already published).
    $bskyUri = $entity->get('atmosphere_bsky_uri')->value ?? '';
    $bskyCid = $entity->get('atmosphere_bsky_cid')->value ?? '';
    if (!empty($bskyUri) && !empty($bskyCid)) {
      $record['bskyPostRef'] = [
        'uri' => $bskyUri,
        'cid' => $bskyCid,
      ];
    }

    // Updated timestamp (if modified after publication).
    $created = (int) $entity->getCreatedTime();
    $changed = (int) $entity->getChangedTime();
    if ($changed > $created) {
      $record['updatedAt'] = $this->toIso8601($changed);
    }

    // Allow other modules to alter the record.
    $this->moduleHandler->alter('atmosphere_document', $record, $entity);

    return $record;
  }

  /**
   * Gets the relative path for a node.
   */
  private function getRelativePath(NodeInterface $node): string {
    $nodePath = '/node/' . $node->id();
    $alias = $this->pathAliasManager->getAliasByPath($nodePath);

    return $alias ?: $nodePath;
  }

  /**
   * Gets the cover image as an uploaded blob reference.
   */
  private function getCoverImage(NodeInterface $node): ?array {
    // Look for common image field names.
    $imageFieldNames = ['field_image', 'field_featured_image', 'field_hero_image', 'field_thumbnail'];

    foreach ($imageFieldNames as $fieldName) {
      if (!$node->hasField($fieldName)) {
        continue;
      }

      $imageField = $node->get($fieldName);
      if ($imageField->isEmpty()) {
        continue;
      }

      $fileEntity = $imageField->entity;
      if (!$fileEntity) {
        continue;
      }

      $uri = $fileEntity->getFileUri();
      $realPath = $this->fileSystem->realpath($uri);
      if (!$realPath || !file_exists($realPath)) {
        continue;
      }

      $mimeType = $fileEntity->getMimeType();

      try {
        $blobResponse = $this->apiClient->uploadBlob($realPath, $mimeType);
        return $blobResponse['blob'] ?? NULL;
      }
      catch (\Exception) {
        return NULL;
      }
    }

    return NULL;
  }

  /**
   * Gets plain text content from the node body.
   */
  private function getTextContent(NodeInterface $node): string {
    if (!$node->hasField('body')) {
      return '';
    }

    $body = $node->get('body');
    if ($body->isEmpty()) {
      return '';
    }

    // Render the body through Drupal's text filters.
    $value = $body->processed ?? $body->value ?? '';

    return $this->sanitizeText($value);
  }

  /**
   * Gets parsed rich content via a content parser.
   *
   * Other modules can implement hook_atmosphere_content_parser() to provide
   * a ContentParserInterface implementation.
   */
  private function getContent(NodeInterface $node): ?array {
    if (!$node->hasField('body')) {
      return NULL;
    }

    $body = $node->get('body');
    if ($body->isEmpty()) {
      return NULL;
    }

    $value = $body->value ?? '';

    // Allow modules to provide a content parser.
    $parsers = $this->moduleHandler->invokeAll('atmosphere_content_parser');
    foreach ($parsers as $parser) {
      if ($parser instanceof ContentParserInterface) {
        $result = $parser->parse($value, $node);
        if (!empty($result)) {
          return $result;
        }
      }
    }

    return NULL;
  }

}
