<?php

declare(strict_types=1);

namespace Drupal\atmosphere\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Serves the OAuth client metadata JSON.
 *
 * This endpoint URL serves as the client_id per AT Protocol OAuth spec.
 * It must be publicly accessible without authentication.
 */
class ClientMetadataController extends ControllerBase {

  public function __construct(
    private readonly UrlGeneratorInterface $urlGenerator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('url_generator'),
    );
  }

  /**
   * Returns the OAuth client metadata as JSON.
   */
  public function metadata(): JsonResponse {
    $siteName = $this->config('system.site')->get('name') ?? 'Drupal';

    $metadata = [
      'client_id' => $this->urlGenerator->generateFromRoute(
        'atmosphere.client_metadata',
        [],
        ['absolute' => TRUE],
      ),
      'client_name' => $siteName . ' (ATmosphere)',
      'client_uri' => $this->urlGenerator->generateFromRoute('<front>', [], ['absolute' => TRUE]),
      'redirect_uris' => [
        $this->urlGenerator->generateFromRoute(
          'atmosphere.oauth_callback',
          [],
          ['absolute' => TRUE],
        ),
      ],
      'grant_types' => ['authorization_code', 'refresh_token'],
      'response_types' => ['code'],
      'token_endpoint_auth_method' => 'none',
      'scope' => 'atproto transition:generic',
      'dpop_bound_access_tokens' => TRUE,
      'application_type' => 'web',
    ];

    return new JsonResponse($metadata);
  }

}
