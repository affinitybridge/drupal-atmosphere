<?php

declare(strict_types=1);

namespace Drupal\atmosphere\Controller;

use Drupal\atmosphere\Service\Publisher;
use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * AJAX-driven batch backfill of existing published content.
 */
class BackfillController extends ControllerBase {

  private const BATCH_SIZE = 10;

  public function __construct(
    private readonly Publisher $publisher,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('atmosphere.publisher'),
    );
  }

  /**
   * Returns a count of unsynced published nodes.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with 'count', 'nids', and 'batch_size' keys.
   */
  public function count(): JsonResponse {
    $syncableTypes = $this->config('atmosphere.settings')->get('syncable_node_types') ?? [];

    if (empty($syncableTypes)) {
      return new JsonResponse(['count' => 0, 'nids' => []]);
    }

    $query = $this->entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(false)
      ->condition('type', $syncableTypes, 'IN')
      ->condition('status', NodeInterface::PUBLISHED)
      ->notExists('atmosphere_doc_uri')
      ->sort('created', 'ASC')
      ->range(0, self::BATCH_SIZE);

    $nids = $query->execute();

    // Get total count.
    $countQuery = $this->entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(false)
      ->condition('type', $syncableTypes, 'IN')
      ->condition('status', NodeInterface::PUBLISHED)
      ->notExists('atmosphere_doc_uri')
      ->count();

    $total = (int) $countQuery->execute();

    return new JsonResponse([
      'count' => $total,
      'nids' => array_values($nids),
      'batch_size' => self::BATCH_SIZE,
    ]);
  }

  /**
   * Processes a batch of nodes for backfill.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request, with a JSON body containing a 'nids' array.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with a 'results' array, each entry having 'nid', 'status', and
   *   optionally 'message'.
   */
  public function batch(Request $request): JsonResponse {
    $content = json_decode($request->getContent(), true);
    $nids = $content['nids'] ?? [];

    if (empty($nids) || !is_array($nids)) {
      return new JsonResponse(['error' => 'No node IDs provided.'], 400);
    }

    $results = [];
    $nodes = $this->entityTypeManager()->getStorage('node')->loadMultiple($nids);

    foreach ($nodes as $node) {
      if (!$node instanceof NodeInterface || !$node->isPublished()) {
        $results[] = [
          'nid' => $node->id(),
          'status' => 'skipped',
          'message' => 'Not published.',
        ];
        continue;
      }

      try {
        $this->publisher->publish($node);
        $results[] = [
          'nid' => $node->id(),
          'status' => 'success',
        ];
      }
      catch (\Exception $e) {
        $results[] = [
          'nid' => $node->id(),
          'status' => 'error',
          'message' => $e->getMessage(),
        ];
      }
    }

    return new JsonResponse(['results' => $results]);
  }

}
