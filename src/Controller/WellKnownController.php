<?php

declare(strict_types=1);

namespace Drupal\atmosphere\Controller;

use Drupal\atmosphere\Service\ConnectionManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves well-known AT Protocol endpoints.
 */
class WellKnownController extends ControllerBase {

  public function __construct(
    private readonly ConnectionManager $connectionManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('atmosphere.connection_manager'),
      $container->get('config.factory'),
    );
  }

  /**
   * Serves /.well-known/atproto-did.
   *
   * Returns the connected DID as plain text for domain handle verification.
   */
  public function atprotoDid(): Response {
    $did = $this->connectionManager->getDid();

    if (empty($did)) {
      throw new NotFoundHttpException();
    }

    return new Response($did, 200, [
      'Content-Type' => 'text/plain; charset=utf-8',
      'Cache-Control' => 'public, max-age=3600',
    ]);
  }

  /**
   * Serves /.well-known/site.standard.publication.
   *
   * Returns the publication AT-URI as plain text.
   */
  public function publication(): Response {
    $uri = $this->configFactory->get('atmosphere.settings')->get('publication_uri');

    if (empty($uri)) {
      throw new NotFoundHttpException();
    }

    return new Response($uri, 200, [
      'Content-Type' => 'text/plain; charset=utf-8',
      'Cache-Control' => 'public, max-age=3600',
    ]);
  }

}
