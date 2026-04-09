<?php

declare(strict_types=1);

namespace Drupal\atmosphere\Form;

use Drupal\atmosphere\OAuth\Client;
use Drupal\atmosphere\Service\ConnectionManager;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * ATmosphere settings form.
 *
 * Displays connection status and configuration options. In disconnected
 * state, provides the handle input to initiate OAuth. In connected state,
 * shows connection info and publishing settings.
 */
class SettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $configFactory,
    TypedConfigManagerInterface $typedConfigManager,
    private readonly Client $oauthClient,
    private readonly ConnectionManager $connectionManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CsrfTokenGenerator $csrfTokenGenerator,
  ) {
    parent::__construct($configFactory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('atmosphere.oauth_client'),
      $container->get('atmosphere.connection_manager'),
      $container->get('entity_type.manager'),
      $container->get('csrf_token'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'atmosphere_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['atmosphere.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    // Show errors passed via query parameter from loopback OAuth callback.
    $atmosphereError = $this->getRequest()->query->get('atmosphere_error');
    if ($atmosphereError) {
      $this->messenger()->addError($this->t('Authorization failed: @error', [
        '@error' => $atmosphereError,
      ]));
    }

    $config = $this->config('atmosphere.settings');

    if ($this->connectionManager->isConnected()) {
      $form = $this->buildConnectedForm($form, $config);
    }
    else {
      $form = $this->buildDisconnectedForm($form);
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * Builds the form for the connected state.
   */
  private function buildConnectedForm(array $form, $config): array {
    // Connection info.
    $form['connection'] = [
      '#type' => 'details',
      '#title' => $this->t('Connection'),
      '#open' => true,
    ];

    $form['connection']['info'] = [
      '#type' => 'item',
      '#markup' => '<p><strong>' . $this->t('Handle:') . '</strong> @' . $this->connectionManager->getHandle() . '</p>'
        . '<p><strong>' . $this->t('DID:') . '</strong> <code>' . $this->connectionManager->getDid() . '</code></p>'
        . '<p><strong>' . $this->t('PDS:') . '</strong> ' . $this->connectionManager->getPdsEndpoint() . '</p>',
    ];

    $form['connection']['disconnect'] = [
      '#type' => 'link',
      '#title' => $this->t('Disconnect'),
      '#url' => Url::fromRoute('atmosphere.disconnect'),
      '#attributes' => [
        'class' => ['button', 'button--danger'],
      ],
    ];

    // Publishing settings.
    $form['publishing'] = [
      '#type' => 'details',
      '#title' => $this->t('Publishing'),
      '#open' => true,
    ];

    $form['publishing']['auto_publish'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto-publish new content to AT Protocol'),
      '#description' => $this->t('Automatically create Bluesky and standard.site records when content is published.'),
      '#default_value' => $config->get('auto_publish'),
    ];

    // Content type selection.
    $nodeTypes = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
    $options = [];
    foreach ($nodeTypes as $type) {
      $options[$type->id()] = $type->label();
    }

    $form['publishing']['syncable_node_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Content types to sync'),
      '#description' => $this->t('Select which content types should be published to AT Protocol.'),
      '#options' => $options,
      '#default_value' => $config->get('syncable_node_types') ?? [],
    ];

    // Backfill.
    $form['backfill'] = [
      '#type' => 'details',
      '#title' => $this->t('Backfill'),
      '#open' => false,
    ];

    $form['backfill']['start_backfill'] = [
      '#type' => 'button',
      '#value' => $this->t('Start Backfill'),
      '#attributes' => [
        'id' => 'atmosphere-backfill-start',
        'class' => ['button'],
      ],
    ];

    $form['backfill']['progress'] = [
      '#markup' => '<div id="atmosphere-backfill-progress"></div>',
    ];

    $form['#attached']['library'][] = 'atmosphere/admin';
    $form['#attached']['drupalSettings']['atmosphere'] = [
      'csrfToken' => $this->csrfTokenGenerator->get('session'),
    ];

    return $form;
  }

  /**
   * Builds the form for the disconnected state.
   */
  private function buildDisconnectedForm(array $form): array {
    $form['connect'] = [
      '#type' => 'details',
      '#title' => $this->t('Connect to AT Protocol'),
      '#open' => true,
    ];

    $form['connect']['handle'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Bluesky Handle'),
      '#description' => $this->t('Enter your AT Protocol handle (e.g., yourname.bsky.social or your-domain.com).'),
      '#required' => false,
      '#placeholder' => 'yourname.bsky.social',
    ];

    $form['connect']['info'] = [
      '#markup' => '<p>' . $this->t('Connecting will redirect you to your AT Protocol provider to authorize this site.') . '</p>',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($this->connectionManager->isConnected()) {
      // Save publishing settings.
      $syncableTypes = array_values(array_filter($form_state->getValue('syncable_node_types') ?? []));

      $this->config('atmosphere.settings')
        ->set('auto_publish', (bool) $form_state->getValue('auto_publish'))
        ->set('syncable_node_types', $syncableTypes)
        ->save();

      parent::submitForm($form, $form_state);
    }
    else {
      // Initiate OAuth flow.
      $handle = trim($form_state->getValue('handle') ?? '');

      if (empty($handle)) {
        $this->messenger()->addError($this->t('Please enter a handle.'));
        return;
      }

      try {
        $authUrl = $this->oauthClient->authorize($handle);
        $form_state->setResponse(new TrustedRedirectResponse($authUrl));
      }
      catch (\RuntimeException $e) {
        $this->messenger()->addError($this->t('Failed to start authorization: @error', [
          '@error' => $e->getMessage(),
        ]));
      }
    }
  }

}
