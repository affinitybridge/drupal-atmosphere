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
 * Updates a node's AT Protocol records.
 *
 * @QueueWorker(
 *   id = "atmosphere_update",
 *   title = @Translation("ATmosphere: Update AT Protocol records"),
 *   cron = {"time" = 30}
 * )
 */
class AtmosphereUpdate extends QueueWorkerBase implements ContainerFactoryPluginInterface {

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
    $nid = $data['nid'] ?? NULL;
    if (!$nid) {
      return;
    }

    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface || !$node->isPublished()) {
      return;
    }

    try {
      $this->publisher->update($node);
      $this->logger->info('Updated AT Protocol records for node @nid.', ['@nid' => $nid]);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to update node @nid: @message', [
        '@nid' => $nid,
        '@message' => $e->getMessage(),
      ]);
      throw new RequeueException('Update failed, will retry.');
    }
  }

}
