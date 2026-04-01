<?php

declare(strict_types=1);

namespace Drupal\atmosphere\Transformer;

use Drupal\atmosphere\OAuth\Resolver;
use Drupal\atmosphere\Service\ConnectionManager;

/**
 * Extracts rich-text facets (links, mentions, hashtags) from text.
 *
 * Facets are byte-range annotations used by Bluesky to render rich text.
 * Each facet specifies a byte range in the text and a feature (link, mention,
 * or hashtag) that applies to that range.
 */
class FacetExtractor {

  public function __construct(
    private readonly ConnectionManager $connectionManager,
    private readonly Resolver $resolver,
  ) {}

  /**
   * Extracts all facets (links, mentions, hashtags) from text.
   *
   * @return array
   *   Array of facet objects sorted by byteStart.
   */
  public function extract(string $text): array {
    $facets = array_merge(
      $this->extractLinks($text),
      $this->extractMentions($text),
      $this->extractHashtags($text),
    );

    usort($facets, fn($a, $b) => $a['index']['byteStart'] <=> $b['index']['byteStart']);

    return $facets;
  }

  /**
   * Builds link facets for specific known URLs in text.
   */
  public function forUrls(string $text, array $urls): array {
    $facets = [];

    foreach ($urls as $url) {
      $pos = strpos($text, $url);
      if ($pos !== false) {
        $byteStart = strlen(substr($text, 0, $pos));
        $byteEnd = $byteStart + strlen($url);

        $facets[] = [
          'index' => [
            'byteStart' => $byteStart,
            'byteEnd' => $byteEnd,
          ],
          'features' => [
            [
              '$type' => 'app.bsky.richtext.facet#link',
              'uri' => $url,
            ],
          ],
        ];
      }
    }

    return $facets;
  }

  /**
   * Extracts URL link facets.
   */
  private function extractLinks(string $text): array {
    $facets = [];
    $pattern = '/\bhttps?:\/\/[^\s<>\[\]"\']+/';

    if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
      foreach ($matches[0] as [$url, $byteOffset]) {
        // Clean trailing punctuation that's likely not part of the URL.
        $url = rtrim($url, '.,;:!?)]}');

        $facets[] = [
          'index' => [
            'byteStart' => $byteOffset,
            'byteEnd' => $byteOffset + strlen($url),
          ],
          'features' => [
            [
              '$type' => 'app.bsky.richtext.facet#link',
              'uri' => $url,
            ],
          ],
        ];
      }
    }

    return $facets;
  }

  /**
   * Extracts @mention facets and resolves handles to DIDs.
   */
  private function extractMentions(string $text): array {
    $facets = [];
    $pattern = '/(^|\s)@([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)+)/';

    if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
      foreach ($matches[2] as $i => [$handle, $offset]) {
        $fullMatch = $matches[0][$i][0];
        $fullOffset = $matches[0][$i][1];

        // The actual @handle starts after any leading whitespace.
        $atOffset = $fullOffset + strlen($fullMatch) - strlen($handle) - 1;

        $did = $this->resolveMention($handle);

        $facets[] = [
          'index' => [
            'byteStart' => $atOffset,
            'byteEnd' => $atOffset + strlen('@' . $handle),
          ],
          'features' => [
            [
              '$type' => 'app.bsky.richtext.facet#mention',
              'did' => $did,
            ],
          ],
        ];
      }
    }

    return $facets;
  }

  /**
   * Extracts #hashtag facets.
   */
  private function extractHashtags(string $text): array {
    $facets = [];
    $pattern = '/(^|\s)#([a-zA-Z0-9_]+)/';

    if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
      foreach ($matches[0] as $i => [$fullMatch, $fullOffset]) {
        $tag = $matches[2][$i][0];
        $hashOffset = $fullOffset + strlen($fullMatch) - strlen($tag) - 1;

        $facets[] = [
          'index' => [
            'byteStart' => $hashOffset,
            'byteEnd' => $hashOffset + strlen('#' . $tag),
          ],
          'features' => [
            [
              '$type' => 'app.bsky.richtext.facet#tag',
              'tag' => $tag,
            ],
          ],
        ];
      }
    }

    return $facets;
  }

  /**
   * Resolves a handle to a DID.
   *
   * Checks if the handle matches the connected user first, then
   * attempts DNS resolution, falling back to did:web.
   */
  private function resolveMention(string $handle): string {
    // Check if this is the connected user's handle.
    $connectedHandle = $this->connectionManager->getHandle();
    if (strcasecmp($handle, $connectedHandle) === 0) {
      return $this->connectionManager->getDid();
    }

    try {
      return $this->resolver->handleToDid($handle);
    }
    catch (\RuntimeException) {
      return 'did:web:' . $handle;
    }
  }

}
