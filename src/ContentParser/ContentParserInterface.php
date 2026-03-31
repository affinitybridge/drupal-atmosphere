<?php

declare(strict_types=1);

namespace Drupal\atmosphere\ContentParser;

use Drupal\node\NodeInterface;

/**
 * Interface for pluggable content parsers.
 *
 * Modules can implement this interface and return it from
 * hook_atmosphere_content_parser() to provide custom content formats
 * (e.g., Markdown, rich text) for site.standard.document records.
 */
interface ContentParserInterface {

  /**
   * Parses content into an AT Protocol content record.
   *
   * @param string $content
   *   The raw content string (e.g., HTML body value).
   * @param \Drupal\node\NodeInterface $node
   *   The source node entity.
   *
   * @return array
   *   An array that must include a '$type' key indicating the content format.
   */
  public function parse(string $content, NodeInterface $node): array;

  /**
   * Returns the content type identifier.
   *
   * For example, 'at.markpub.markdown' or a custom NSID.
   */
  public function getType(): string;

}
