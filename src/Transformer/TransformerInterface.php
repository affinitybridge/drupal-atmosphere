<?php

declare(strict_types=1);

namespace Drupal\atmosphere\Transformer;

/**
 * Interface for AT Protocol record transformers.
 */
interface TransformerInterface {

  /**
   * Transforms the source object into an AT Protocol record array.
   */
  public function transform(object $entity): array;

  /**
   * Returns the NSID collection name (e.g., 'site.standard.document').
   */
  public function getCollection(): string;

  /**
   * Returns the record key (TID) for the given entity.
   */
  public function getRkey(object $entity): string;

  /**
   * Builds the full AT-URI for the given entity.
   */
  public function getUri(object $entity): string;

}
