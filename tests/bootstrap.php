<?php

/**
 * @file
 * PHPUnit bootstrap for ATmosphere module unit tests.
 *
 * Sets up autoloading so unit tests can run without a full Drupal installation.
 * Tests mock all Drupal dependencies — only pure PHP logic is tested directly.
 */

declare(strict_types=1);

$autoloader = __DIR__ . '/../vendor/autoload.php';

if (!file_exists($autoloader)) {
  fwrite(STDERR, "Run 'composer install' in the module directory first.\n");
  exit(1);
}

require_once $autoloader;
