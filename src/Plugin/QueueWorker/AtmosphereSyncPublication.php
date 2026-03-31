<?php

declare(strict_types=1);

namespace Drupal\atmosphere\Plugin\QueueWorker;

use Drupal\atmosphere\Service\Publisher;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\RequeueException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Syncs the site.standard.publication record.
 *
 * @QueueWorker(
 *   id = "atmosphere_sync_publication",
 *   title = @Translation("ATmosphere: Sync publication record"),
 *   cron = {"time" = 15}
 * )
 */
class AtmosphereSyncPublication extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly Publisher $publisher,
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
      $container->get('logger.factory')->get('atmosphere'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    try {
      $this->publisher->syncPublication();
      $this->logger->info('Synced site.standard.publication record.');
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to sync publication: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw new RequeueException('Publication sync failed, will retry.');
    }
  }

}
