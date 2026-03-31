<?php

declare(strict_types=1);

namespace Drupal\atmosphere\Transformer;

use Drupal\atmosphere\Service\ConnectionManager;
use Drupal\node\NodeInterface;

/**
 * Base class for AT Protocol record transformers.
 *
 * Provides common utilities for building AT-URIs, extracting tags,
 * generating excerpts, and formatting dates.
 */
abstract class TransformerBase implements TransformerInterface {

  /**
   * Builds an AT-URI from components.
   */
  protected function buildAtUri(string $did, string $collection, string $rkey): string {
    return "at://{$did}/{$collection}/{$rkey}";
  }

  /**
   * Returns the current site language as a BCP-47 array.
   */
  protected function getLangs(): array {
    $langcode = \Drupal::languageManager()->getCurrentLanguage()->getId();
    // BCP-47: use just the primary language subtag.
    return [substr($langcode, 0, 2)];
  }

  /**
   * Converts a UNIX timestamp or datetime string to ISO 8601.
   */
  protected function toIso8601(int|string $datetime): string {
    if (is_numeric($datetime)) {
      $datetime = (int) $datetime;
    }
    else {
      $datetime = strtotime($datetime);
    }

    return gmdate('Y-m-d\TH:i:s.000\Z', $datetime);
  }

  /**
   * Collects tags from taxonomy term reference fields on a node.
   *
   * Returns up to 8 unique tag names.
   */
  protected function collectTags(NodeInterface $node): array {
    $tags = [];

    foreach ($node->getFieldDefinitions() as $fieldName => $definition) {
      if ($definition->getType() !== 'entity_reference') {
        continue;
      }

      $settings = $definition->getSettings();
      if (($settings['target_type'] ?? '') !== 'taxonomy_term') {
        continue;
      }

      $items = $node->get($fieldName);
      foreach ($items as $item) {
        $term = $item->entity;
        if ($term) {
          $name = $term->label();
          if (strtolower($name) !== 'uncategorized') {
            $tags[] = $name;
          }
        }
      }
    }

    return array_slice(array_unique($tags), 0, 8);
  }

  /**
   * Gets a plain-text excerpt from a node.
   *
   * Uses the body summary if available, otherwise truncates the body value.
   */
  protected function getExcerpt(NodeInterface $node, int $wordLimit = 30): string {
    if (!$node->hasField('body')) {
      return '';
    }

    $body = $node->get('body');
    if ($body->isEmpty()) {
      return '';
    }

    $summary = $body->summary ?? '';
    if (!empty($summary)) {
      return $this->sanitizeText($summary);
    }

    $text = $this->sanitizeText($body->value ?? '');
    $words = preg_split('/\s+/', $text, $wordLimit + 1);

    if (count($words) > $wordLimit) {
      $words = array_slice($words, 0, $wordLimit);
      return implode(' ', $words) . '...';
    }

    return implode(' ', $words);
  }

  /**
   * Strips HTML, decodes entities, and normalizes whitespace.
   */
  protected function sanitizeText(string $text): string {
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
  }

  /**
   * Truncates text at a word boundary, respecting a grapheme limit.
   */
  protected function truncateText(string $text, int $limit = 300, string $marker = '...'): string {
    if (mb_strlen($text) <= $limit) {
      return $text;
    }

    $markerLen = mb_strlen($marker);
    $truncated = mb_substr($text, 0, $limit - $markerLen);

    // Cut at last space to avoid breaking words.
    $lastSpace = mb_strrpos($truncated, ' ');
    if ($lastSpace !== FALSE && $lastSpace > ($limit - $markerLen) / 2) {
      $truncated = mb_substr($truncated, 0, $lastSpace);
    }

    return $truncated . $marker;
  }

}
