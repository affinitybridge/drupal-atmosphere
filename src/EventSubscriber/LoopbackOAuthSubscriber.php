<?php

declare(strict_types=1);

namespace Drupal\atmosphere\EventSubscriber;

use Drupal\atmosphere\OAuth\Client;
use Drupal\atmosphere\Service\ConnectionManager;
use Drupal\atmosphere\Service\Publisher;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Site\Settings;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Intercepts the loopback OAuth callback on http://127.0.0.1:{port}.
 *
 * When atmosphere_loopback_port is configured, the AT Protocol auth server
 * redirects the browser to http://127.0.0.1:{port}?code=...&state=...
 * (no path allowed by Bluesky). This subscriber catches that request before
 * Drupal routes it to the front page, processes the token exchange, and
 * redirects back to the real site hostname.
 */
class LoopbackOAuthSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly Client $oauthClient,
    private readonly Publisher $publisher,
    private readonly ConnectionManager $connectionManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // High priority to run before routing.
    return [KernelEvents::REQUEST => ['onRequest', 1000]];
  }

  /**
   * Checks if this is a loopback OAuth callback and handles it.
   */
  public function onRequest(RequestEvent $event): void {
    if (!Settings::get('atmosphere_loopback_port')) {
      return;
    }

    $request = $event->getRequest();

    // Only intercept requests that have OAuth callback parameters.
    if (!$request->query->has('code') && !$request->query->has('error')) {
      return;
    }
    if (!$request->query->has('state')) {
      return;
    }

    // Only intercept on the loopback address.
    $host = $request->getHost();
    if ($host !== '127.0.0.1' && $host !== '[::1]') {
      return;
    }

    $returnUrl = $this->connectionManager->getOAuthTransient('atmosphere_oauth_return_url') ?? '/';

    $error = $request->query->get('error', '');
    if ($error) {
      $errorMsg = $request->query->get('error_description', $error);
      $this->logger->error('Loopback OAuth error: @error', ['@error' => $errorMsg]);
      $event->setResponse(new TrustedRedirectResponse(
        $returnUrl . '?atmosphere_error=' . urlencode($errorMsg)
      ));
      return;
    }

    $code = $request->query->get('code', '');
    $state = $request->query->get('state', '');

    if (empty($code) || empty($state)) {
      $event->setResponse(new TrustedRedirectResponse(
        $returnUrl . '?atmosphere_error=' . urlencode('Missing code or state parameter.')
      ));
      return;
    }

    try {
      $this->oauthClient->handleCallback($code, $state);

      try {
        $this->publisher->syncPublication();
      }
      catch (\Exception $e) {
        $this->logger->warning('Publication sync failed after connect: @message', [
          '@message' => $e->getMessage(),
        ]);
      }
    }
    catch (\RuntimeException $e) {
      $this->logger->error('Loopback OAuth connection failed: @error', [
        '@error' => $e->getMessage(),
      ]);
      $event->setResponse(new TrustedRedirectResponse(
        $returnUrl . '?atmosphere_error=' . urlencode($e->getMessage())
      ));
      return;
    }

    $event->setResponse(new TrustedRedirectResponse($returnUrl));
  }

}
