<?php

declare(strict_types=1);

namespace Drupal\atmosphere\Transformer;

use Drupal\atmosphere\Service\ApiClient;
use Drupal\atmosphere\Service\ConnectionManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;

/**
 * Transforms Drupal site configuration into a site.standard.publication record.
 */
class PublicationTransformer extends TransformerBase {

  private const COLLECTION = 'site.standard.publication';

  public function __construct(
    private readonly TidGenerator $tidGenerator,
    private readonly ApiClient $apiClient,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ConnectionManager $connectionManager,
    private readonly FileSystemInterface $fileSystem,
    private readonly UrlGeneratorInterface $urlGenerator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getCollection(): string {
    return self::COLLECTION;
  }

  /**
   * {@inheritdoc}
   */
  public function getRkey(object $entity): string {
    $config = $this->configFactory->get('atmosphere.settings');
    $tid = $config->get('publication_tid');

    if (empty($tid)) {
      $tid = $this->tidGenerator->generate();
      $this->configFactory->getEditable('atmosphere.settings')
        ->set('publication_tid', $tid)
        ->save();
    }

    return $tid;
  }

  /**
   * {@inheritdoc}
   */
  public function getUri(object $entity): string {
    return $this->buildAtUri(
      $this->connectionManager->getDid(),
      $this->getCollection(),
      $this->getRkey($entity),
    );
  }

  /**
   * Transforms site configuration into a publication record.
   *
   * @param object $entity
   *   Unused — publication is derived from site config, not an entity.
   *   Pass a stdClass or any object to satisfy the interface.
   */
  public function transform(object $entity): array {
    $siteConfig = $this->configFactory->get('system.site');

    $record = [
      '$type' => self::COLLECTION,
      'url' => $this->urlGenerator->generateFromRoute('<front>', [], ['absolute' => TRUE]),
      'name' => $siteConfig->get('name') ?? 'Drupal Site',
    ];

    $slogan = $siteConfig->get('slogan');
    if (!empty($slogan)) {
      $record['description'] = $slogan;
    }

    // Display name: same as site name.
    $record['displayName'] = $record['name'];

    // Avatar: try site logo.
    $avatar = $this->uploadSiteLogo();
    if ($avatar !== NULL) {
      $record['avatar'] = $avatar;
    }

    // Theme colors.
    $theme = $this->extractTheme();
    if ($theme !== NULL) {
      $record['theme'] = $theme;
    }

    return $record;
  }

  /**
   * Uploads the site logo as a blob for the publication avatar.
   */
  private function uploadSiteLogo(): ?array {
    $logoPath = theme_get_setting('logo.path');
    if (empty($logoPath)) {
      return NULL;
    }

    $realPath = $this->fileSystem->realpath($logoPath);
    if (!$realPath || !file_exists($realPath)) {
      // The logo path might already be absolute or a stream URI.
      if (file_exists($logoPath)) {
        $realPath = $logoPath;
      }
      else {
        return NULL;
      }
    }

    $mimeType = mime_content_type($realPath) ?: 'image/png';

    try {
      $blobResponse = $this->apiClient->uploadBlob($realPath, $mimeType);
      return $blobResponse['blob'] ?? NULL;
    }
    catch (\Exception) {
      return NULL;
    }
  }

  /**
   * Extracts theme colors from the active theme.
   */
  private function extractTheme(): ?array {
    $bgColor = theme_get_setting('background_color');
    $textColor = theme_get_setting('text_color');

    if (empty($bgColor) && empty($textColor)) {
      return NULL;
    }

    $theme = [];

    if (!empty($bgColor)) {
      $rgb = self::hexToRgb($bgColor);
      if ($rgb !== NULL) {
        $theme['backgroundColor'] = $rgb;
      }
    }

    if (!empty($textColor)) {
      $rgb = self::hexToRgb($textColor);
      if ($rgb !== NULL) {
        $theme['textColor'] = $rgb;
      }
    }

    return !empty($theme) ? $theme : NULL;
  }

  /**
   * Converts a hex color string to an RGB array.
   */
  public static function hexToRgb(string $hex): ?array {
    $hex = ltrim($hex, '#');

    if (strlen($hex) === 3) {
      $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }

    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
      return NULL;
    }

    return [
      'r' => hexdec(substr($hex, 0, 2)),
      'g' => hexdec(substr($hex, 2, 2)),
      'b' => hexdec(substr($hex, 4, 2)),
    ];
  }

}
