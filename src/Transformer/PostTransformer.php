<?php

declare(strict_types=1);

namespace Drupal\atmosphere\Transformer;

use Drupal\atmosphere\Service\ApiClient;
use Drupal\atmosphere\Service\ConnectionManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Transforms a Drupal node into an app.bsky.feed.post record.
 */
class PostTransformer extends TransformerBase {

  private const COLLECTION = 'app.bsky.feed.post';

  private const MAX_GRAPHEMES = 300;

  public function __construct(
    private readonly TidGenerator $tidGenerator,
    private readonly FacetExtractor $facetExtractor,
    private readonly ApiClient $apiClient,
    private readonly ConnectionManager $connectionManager,
    private readonly FileSystemInterface $fileSystem,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    LanguageManagerInterface $languageManager,
  ) {
    $this->languageManager = $languageManager;
  }

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

    $tid = $entity->get('atmosphere_bsky_tid')->value ?? '';
    if (empty($tid)) {
      $tid = $this->tidGenerator->generate();
      $entity->set('atmosphere_bsky_tid', $tid);
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

    $text = $this->buildText($entity);

    $record = [
      '$type' => self::COLLECTION,
      'text' => $text,
      'createdAt' => $this->toIso8601($entity->getCreatedTime()),
      'langs' => $this->getLangs(),
    ];

    // Extract facets (links, mentions, hashtags).
    $facets = $this->facetExtractor->extract($text);
    if (!empty($facets)) {
      $record['facets'] = $facets;
    }

    // External embed card.
    $embed = $this->buildEmbed($entity);
    if ($embed !== NULL) {
      $record['embed'] = $embed;
    }

    // Tags.
    $tags = $this->collectTags($entity);
    if (!empty($tags)) {
      $record['tags'] = $tags;
    }

    return $record;
  }

  /**
   * Builds the post text from node title, excerpt, and URL.
   *
   * Combines title, excerpt, and permalink within the 300-grapheme limit,
   * reserving space for the permalink at the end.
   */
  private function buildText(NodeInterface $node): string {
    $title = $this->sanitizeText($node->getTitle());
    $excerpt = $this->getExcerpt($node, 30);
    $url = $node->toUrl('canonical', ['absolute' => TRUE])->toString();

    // Reserve space for the URL and separators using grapheme count.
    $strlen = function_exists('grapheme_strlen') ? 'grapheme_strlen' : 'mb_strlen';
    $urlLength = $strlen($url);
    $available = self::MAX_GRAPHEMES - $urlLength - 2; // "\n\n" before URL.

    if (!empty($excerpt)) {
      $titleAndExcerpt = $title . "\n\n" . $excerpt;
    }
    else {
      $titleAndExcerpt = $title;
    }

    if ($strlen($titleAndExcerpt) > $available) {
      $titleAndExcerpt = $this->truncateText($titleAndExcerpt, $available);
    }

    return $titleAndExcerpt . "\n\n" . $url;
  }

  /**
   * Builds an external embed card for the node.
   */
  private function buildEmbed(NodeInterface $node): ?array {
    $url = $node->toUrl('canonical', ['absolute' => TRUE])->toString();
    $title = $this->sanitizeText($node->getTitle());
    $description = $this->getExcerpt($node, 30);

    $external = [
      'uri' => $url,
      'title' => $title,
      'description' => $description,
    ];

    // Try to upload a thumbnail.
    $thumb = $this->uploadThumbnail($node);
    if ($thumb !== NULL) {
      $external['thumb'] = $thumb;
    }

    return [
      '$type' => 'app.bsky.embed.external',
      'external' => $external,
    ];
  }

  /**
   * Uploads the node's featured image as a blob for the embed card.
   */
  private function uploadThumbnail(NodeInterface $node): ?array {
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

      // Check file size — Bluesky has a 1MB limit.
      $fileSize = filesize($realPath);
      if ($fileSize > 1_000_000) {
        $derivativePath = $this->getSmallDerivative($uri);
        if ($derivativePath !== NULL) {
          $realPath = $derivativePath;
        }
        else {
          continue;
        }
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
   * Attempts to find a smaller derivative of an image.
   */
  private function getSmallDerivative(string $uri): ?string {
    $imageStyleStorage = $this->entityTypeManager->getStorage('image_style');
    $style = $imageStyleStorage->load('large');

    if (!$style) {
      return NULL;
    }

    $derivativeUri = $style->buildUri($uri);
    if (!file_exists($derivativeUri)) {
      $style->createDerivative($uri, $derivativeUri);
    }

    $realPath = $this->fileSystem->realpath($derivativeUri);
    if ($realPath && file_exists($realPath) && filesize($realPath) <= 1_000_000) {
      return $realPath;
    }

    return NULL;
  }

}
