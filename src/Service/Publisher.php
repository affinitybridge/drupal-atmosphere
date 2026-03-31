<?php

declare(strict_types=1);

namespace Drupal\atmosphere\Service;

use Drupal\atmosphere\Transformer\DocumentTransformer;
use Drupal\atmosphere\Transformer\PostTransformer;
use Drupal\atmosphere\Transformer\PublicationTransformer;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Orchestrates publishing Drupal nodes to AT Protocol.
 *
 * Coordinates transformers and the API client to atomically create,
 * update, or delete both app.bsky.feed.post and site.standard.document
 * records on the user's PDS.
 */
class Publisher {

  /**
   * Static flag to prevent infinite entity save loops.
   *
   * When the publisher stores AT Protocol metadata back to node base fields,
   * the entity save triggers hook_entity_update() again. This flag breaks
   * that cycle.
   */
  public static bool $isSaving = FALSE;

  public function __construct(
    private readonly ApiClient $apiClient,
    private readonly ConnectionManager $connectionManager,
    private readonly DocumentTransformer $documentTransformer,
    private readonly PostTransformer $postTransformer,
    private readonly PublicationTransformer $publicationTransformer,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Publishes a node to AT Protocol (Bluesky post + standard.site document).
   *
   * Creates both records atomically via applyWrites, then does a follow-up
   * putRecord to add the bskyPostRef to the document.
   *
   * @return array
   *   The applyWrites response.
   */
  public function publish(NodeInterface $node): array {
    $bskyRecord = $this->postTransformer->transform($node);
    $docRecord = $this->documentTransformer->transform($node);

    $bskyRkey = $this->postTransformer->getRkey($node);
    $docRkey = $this->documentTransformer->getRkey($node);

    // Atomic write of both records.
    $writes = [
      [
        '$type' => 'com.atproto.repo.applyWrites#create',
        'collection' => $this->postTransformer->getCollection(),
        'rkey' => $bskyRkey,
        'value' => $bskyRecord,
      ],
      [
        '$type' => 'com.atproto.repo.applyWrites#create',
        'collection' => $this->documentTransformer->getCollection(),
        'rkey' => $docRkey,
        'value' => $docRecord,
      ],
    ];

    $result = $this->apiClient->applyWrites($writes);

    // Store result metadata back to the node.
    $this->storeResults($node, $result);

    // Follow-up: update document with bskyPostRef now that we have the CID.
    $bskyUri = $node->get('atmosphere_bsky_uri')->value ?? '';
    $bskyCid = $node->get('atmosphere_bsky_cid')->value ?? '';
    if (!empty($bskyUri) && !empty($bskyCid)) {
      $docRecord['bskyPostRef'] = [
        'uri' => $bskyUri,
        'cid' => $bskyCid,
      ];

      try {
        $this->apiClient->putRecord(
          $this->documentTransformer->getCollection(),
          $docRkey,
          $docRecord,
        );
      }
      catch (\Exception $e) {
        \Drupal::logger('atmosphere')->warning('Failed to update document with bskyPostRef: @message', [
          '@message' => $e->getMessage(),
        ]);
      }
    }

    return $result;
  }

  /**
   * Updates existing AT Protocol records for a node.
   */
  public function update(NodeInterface $node): array {
    $bskyTid = $node->get('atmosphere_bsky_tid')->value ?? '';
    $docTid = $node->get('atmosphere_doc_tid')->value ?? '';

    // If no TIDs exist, publish instead of update.
    if (empty($bskyTid) && empty($docTid)) {
      return $this->publish($node);
    }

    $bskyRecord = $this->postTransformer->transform($node);
    $docRecord = $this->documentTransformer->transform($node);

    $writes = [];

    if (!empty($bskyTid)) {
      $writes[] = [
        '$type' => 'com.atproto.repo.applyWrites#update',
        'collection' => $this->postTransformer->getCollection(),
        'rkey' => $bskyTid,
        'value' => $bskyRecord,
      ];
    }

    if (!empty($docTid)) {
      $writes[] = [
        '$type' => 'com.atproto.repo.applyWrites#update',
        'collection' => $this->documentTransformer->getCollection(),
        'rkey' => $docTid,
        'value' => $docRecord,
      ];
    }

    $result = $this->apiClient->applyWrites($writes);

    $this->storeResults($node, $result);

    return $result;
  }

  /**
   * Deletes AT Protocol records for a node.
   */
  public function delete(NodeInterface $node): array {
    $bskyTid = $node->get('atmosphere_bsky_tid')->value ?? '';
    $docTid = $node->get('atmosphere_doc_tid')->value ?? '';

    $result = $this->deleteByTids($bskyTid, $docTid);

    // Clear metadata from the node.
    self::$isSaving = TRUE;
    try {
      $node->set('atmosphere_bsky_tid', NULL);
      $node->set('atmosphere_bsky_uri', NULL);
      $node->set('atmosphere_bsky_cid', NULL);
      $node->set('atmosphere_doc_tid', NULL);
      $node->set('atmosphere_doc_uri', NULL);
      $node->set('atmosphere_doc_cid', NULL);
      $node->save();
    }
    finally {
      self::$isSaving = FALSE;
    }

    return $result;
  }

  /**
   * Deletes AT Protocol records by their TIDs.
   *
   * Used when the node entity may already be deleted.
   */
  public function deleteByTids(string $bskyTid, string $docTid): array {
    $writes = [];

    if (!empty($bskyTid)) {
      $writes[] = [
        '$type' => 'com.atproto.repo.applyWrites#delete',
        'collection' => 'app.bsky.feed.post',
        'rkey' => $bskyTid,
      ];
    }

    if (!empty($docTid)) {
      $writes[] = [
        '$type' => 'com.atproto.repo.applyWrites#delete',
        'collection' => 'site.standard.document',
        'rkey' => $docTid,
      ];
    }

    if (empty($writes)) {
      return [];
    }

    return $this->apiClient->applyWrites($writes);
  }

  /**
   * Creates or updates the site.standard.publication record.
   */
  public function syncPublication(): array {
    $record = $this->publicationTransformer->transform(new \stdClass());
    $rkey = $this->publicationTransformer->getRkey(new \stdClass());

    $result = $this->apiClient->putRecord(
      $this->publicationTransformer->getCollection(),
      $rkey,
      $record,
    );

    // Store the publication URI in config.
    $uri = $this->publicationTransformer->getUri(new \stdClass());
    $this->configFactory->getEditable('atmosphere.settings')
      ->set('publication_uri', $uri)
      ->save();

    return $result;
  }

  /**
   * Extracts URIs and CIDs from applyWrites results and stores on the node.
   */
  private function storeResults(NodeInterface $node, array $result): void {
    $results = $result['results'] ?? [];
    $did = $this->connectionManager->getDid();

    foreach ($results as $item) {
      $uri = $item['uri'] ?? '';
      $cid = $item['cid'] ?? '';

      if (str_contains($uri, 'app.bsky.feed.post')) {
        $node->set('atmosphere_bsky_uri', $uri);
        $node->set('atmosphere_bsky_cid', $cid);
      }
      elseif (str_contains($uri, 'site.standard.document')) {
        $node->set('atmosphere_doc_uri', $uri);
        $node->set('atmosphere_doc_cid', $cid);
      }
    }

    self::$isSaving = TRUE;
    try {
      $node->save();
    }
    finally {
      self::$isSaving = FALSE;
    }
  }

}
