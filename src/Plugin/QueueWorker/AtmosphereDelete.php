<?php

declare(strict_types=1);

namespace Drupal\atmosphere\Plugin\QueueWorker;

use Drupal\atmosphere\Service\Publisher;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\RequeueException;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Deletes AT Protocol records for a node.
 *
 * @QueueWorker(
 *   id = "atmosphere_delete",
 *   title = @Translation("ATmosphere: Delete AT Protocol records"),
 *   cron = {"time" = 30}
 * )
 */
class AtmosphereDelete extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly Publisher $publisher,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('atmosphere.publisher'),
      $container->get('entity_type.manager'),
      $container->get('logger.factory')->get('atmosphere'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $bskyTid = $data['bsky_tid'] ?? '';
    $docTid = $data['doc_tid'] ?? '';
    $nid = $data['nid'] ?? NULL;

    if (empty($bskyTid) && empty($docTid)) {
      return;
    }

    try {
      // If the node still exists, use the full delete method to clear fields.
      if ($nid) {
        $node = $this->entityTypeManager->getStorage('node')->load($nid);
        if ($node instanceof NodeInterface) {
          $this->publisher->delete($node);
          $this->logger->info('Deleted AT Protocol records for node @nid.', ['@nid' => $nid]);
          return;
        }
      }

      // Node is gone — delete by TIDs directly.
      $this->publisher->deleteByTids($bskyTid, $docTid);
      $this->logger->info('Deleted AT Protocol records by TIDs (bsky: @bsky, doc: @doc).', [
        '@bsky' => $bskyTid,
        '@doc' => $docTid,
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to delete AT Protocol records: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw new RequeueException('Delete failed, will retry.');
    }
  }

}
