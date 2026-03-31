<?php

declare(strict_types=1);

namespace Drupal\atmosphere\Controller;

use Drupal\atmosphere\OAuth\Client;
use Drupal\atmosphere\Service\Publisher;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles OAuth callback and disconnect actions.
 */
class OAuthCallbackController extends ControllerBase {

  public function __construct(
    private readonly Client $oauthClient,
    private readonly Publisher $publisher,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('atmosphere.oauth_client'),
      $container->get('atmosphere.publisher'),
    );
  }

  /**
   * Handles the OAuth redirect callback.
   */
  public function callback(Request $request): RedirectResponse {
    $code = $request->query->get('code', '');
    $state = $request->query->get('state', '');
    $error = $request->query->get('error', '');

    $settingsUrl = Url::fromRoute('atmosphere.settings')->toString();

    if ($error) {
      $errorDescription = $request->query->get('error_description', $error);
      $this->messenger()->addError($this->t('Authorization failed: @error', [
        '@error' => $errorDescription,
      ]));
      return new RedirectResponse($settingsUrl);
    }

    if (empty($code) || empty($state)) {
      $this->messenger()->addError($this->t('Invalid OAuth callback: missing code or state.'));
      return new RedirectResponse($settingsUrl);
    }

    try {
      $this->oauthClient->handleCallback($code, $state);

      // Sync publication record after successful connection.
      try {
        $this->publisher->syncPublication();
      }
      catch (\Exception $e) {
        $this->getLogger('atmosphere')->warning('Publication sync failed after connect: @message', [
          '@message' => $e->getMessage(),
        ]);
      }

      $this->messenger()->addStatus($this->t('Successfully connected to AT Protocol.'));
    }
    catch (\RuntimeException $e) {
      $this->messenger()->addError($this->t('Connection failed: @error', [
        '@error' => $e->getMessage(),
      ]));
    }

    return new RedirectResponse($settingsUrl);
  }

  /**
   * Disconnects the AT Protocol account.
   */
  public function disconnect(): RedirectResponse {
    $this->oauthClient->disconnect();
    $this->messenger()->addStatus($this->t('Disconnected from AT Protocol.'));

    return new RedirectResponse(
      Url::fromRoute('atmosphere.settings')->toString()
    );
  }

}
