<?php

declare(strict_types=1);

namespace Drupal\atmosphere\EventSubscriber;

use Drupal\atmosphere\Service\ConnectionManager;
use Drupal\atmosphere\Transformer\DocumentTransformer;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Intercepts node canonical routes with ?atproto to show JSON preview.
 */
class PreviewSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly DocumentTransformer $documentTransformer,
    private readonly ConnectionManager $connectionManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onRequest', 30],
    ];
  }

  /**
   * Checks for ?atproto query parameter and returns JSON preview.
   */
  public function onRequest(RequestEvent $event): void {
    $request = $event->getRequest();

    if (!$request->query->has('atproto')) {
      return;
    }

    if (!$this->currentUser->hasPermission('preview atmosphere records')) {
      return;
    }

    if (!$this->connectionManager->isConnected()) {
      return;
    }

    // Check if this is a node canonical route.
    $routeName = $request->attributes->get('_route');
    if ($routeName !== 'entity.node.canonical') {
      return;
    }

    $node = $request->attributes->get('node');
    if (!$node instanceof NodeInterface) {
      return;
    }

    // Check if this node type is syncable.
    $syncableTypes = $this->configFactory->get('atmosphere.settings')->get('syncable_node_types') ?? [];
    if (!in_array($node->bundle(), $syncableTypes, TRUE)) {
      return;
    }

    $record = $this->documentTransformer->transform($node);

    $event->setResponse(new JsonResponse($record, 200, [
      'Content-Type' => 'application/json',
    ], TRUE));
  }

}
