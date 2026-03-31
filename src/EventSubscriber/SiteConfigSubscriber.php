<?php

declare(strict_types=1);

namespace Drupal\atmosphere\EventSubscriber;

use Drupal\atmosphere\Service\ConnectionManager;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Queue\QueueFactory;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Listens for site configuration changes and triggers publication re-sync.
 *
 * When the site name, slogan, or logo changes, enqueues a sync of the
 * site.standard.publication record to keep it current on the PDS.
 */
class SiteConfigSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly ConnectionManager $connectionManager,
    private readonly QueueFactory $queueFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ConfigEvents::SAVE => 'onConfigSave',
    ];
  }

  /**
   * Reacts to config save events for system.site.
   */
  public function onConfigSave(ConfigCrudEvent $event): void {
    $config = $event->getConfig();

    if ($config->getName() !== 'system.site') {
      return;
    }

    if (!$this->connectionManager->isConnected()) {
      return;
    }

    // Check if relevant fields changed.
    $original = $config->getOriginal('name', FALSE);
    $current = $config->get('name');
    $nameChanged = $original !== $current;

    $originalSlogan = $config->getOriginal('slogan', FALSE);
    $currentSlogan = $config->get('slogan');
    $sloganChanged = $originalSlogan !== $currentSlogan;

    if ($nameChanged || $sloganChanged) {
      $this->queueFactory->get('atmosphere_sync_publication')->createItem([
        'trigger' => 'site_config_change',
      ]);
    }
  }

}
