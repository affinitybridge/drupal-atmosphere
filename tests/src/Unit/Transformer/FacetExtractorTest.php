<?php

declare(strict_types=1);

namespace Drupal\Tests\atmosphere\Unit\Transformer;

use Drupal\atmosphere\OAuth\Resolver;
use Drupal\atmosphere\Service\ConnectionManager;
use Drupal\atmosphere\Transformer\FacetExtractor;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\atmosphere\Transformer\FacetExtractor
 */
class FacetExtractorTest extends TestCase {

  private FacetExtractor $extractor;
  private ConnectionManager $connectionManager;

  protected function setUp(): void {
    parent::setUp();

    $this->connectionManager = $this->createMock(ConnectionManager::class);
    $this->connectionManager->method('getHandle')->willReturn('test.bsky.social');
    $this->connectionManager->method('getDid')->willReturn('did:plc:testuser123');

    $resolver = $this->createMock(Resolver::class);
    $resolver->method('handleToDid')
      ->willReturnCallback(function (string $handle): string {
        if ($handle === 'known.user.com') {
          return 'did:plc:knownuser';
        }
        throw new \RuntimeException('Unknown handle');
      });

    $this->extractor = new FacetExtractor($this->connectionManager, $resolver);
  }

  /**
   * @covers ::extract
   */
  public function testExtractLinksFromText(): void {
    $text = 'Check out https://example.com for more info.';

    $facets = $this->extractor->extract($text);

    $this->assertCount(1, $facets);
    $this->assertSame('app.bsky.richtext.facet#link', $facets[0]['features'][0]['$type']);
    $this->assertSame('https://example.com', $facets[0]['features'][0]['uri']);

    // Verify byte offsets.
    $this->assertSame(10, $facets[0]['index']['byteStart']);
    $this->assertSame(29, $facets[0]['index']['byteEnd']);
  }

  /**
   * @covers ::extract
   */
  public function testExtractStripsTrailingPunctuation(): void {
    $text = 'Visit https://example.com/path.';

    $facets = $this->extractor->extract($text);

    $this->assertCount(1, $facets);
    $this->assertSame('https://example.com/path', $facets[0]['features'][0]['uri']);
  }

  /**
   * @covers ::extract
   */
  public function testExtractHashtags(): void {
    $text = 'Hello #drupal and #atproto fans!';

    $facets = $this->extractor->extract($text);

    $hashtagFacets = array_filter($facets, fn($f) =>
      $f['features'][0]['$type'] === 'app.bsky.richtext.facet#tag'
    );

    $this->assertCount(2, $hashtagFacets);

    $tags = array_map(fn($f) => $f['features'][0]['tag'], array_values($hashtagFacets));
    $this->assertContains('drupal', $tags);
    $this->assertContains('atproto', $tags);
  }

  /**
   * @covers ::extract
   */
  public function testExtractMentionsSelfHandle(): void {
    $text = 'Written by @test.bsky.social on Bluesky.';

    $facets = $this->extractor->extract($text);

    $mentionFacets = array_filter($facets, fn($f) =>
      $f['features'][0]['$type'] === 'app.bsky.richtext.facet#mention'
    );

    $this->assertCount(1, $mentionFacets);
    $mention = array_values($mentionFacets)[0];
    $this->assertSame('did:plc:testuser123', $mention['features'][0]['did']);
  }

  /**
   * @covers ::extract
   */
  public function testExtractMentionsResolvesKnownHandle(): void {
    $text = 'Thanks @known.user.com!';

    $facets = $this->extractor->extract($text);

    $mentionFacets = array_filter($facets, fn($f) =>
      $f['features'][0]['$type'] === 'app.bsky.richtext.facet#mention'
    );

    $this->assertCount(1, $mentionFacets);
    $mention = array_values($mentionFacets)[0];
    $this->assertSame('did:plc:knownuser', $mention['features'][0]['did']);
  }

  /**
   * @covers ::extract
   */
  public function testExtractMentionsFallsBackToDidWeb(): void {
    $text = 'Ask @unknown.handle.xyz about it.';

    $facets = $this->extractor->extract($text);

    $mentionFacets = array_filter($facets, fn($f) =>
      $f['features'][0]['$type'] === 'app.bsky.richtext.facet#mention'
    );

    $this->assertCount(1, $mentionFacets);
    $mention = array_values($mentionFacets)[0];
    $this->assertSame('did:web:unknown.handle.xyz', $mention['features'][0]['did']);
  }

  /**
   * @covers ::extract
   */
  public function testByteOffsetsCorrectWithMultibyteText(): void {
    // "café" is 5 bytes in UTF-8 (c=1, a=1, f=1, é=2).
    $text = 'café https://example.com end';

    $facets = $this->extractor->extract($text);

    $linkFacets = array_filter($facets, fn($f) =>
      $f['features'][0]['$type'] === 'app.bsky.richtext.facet#link'
    );

    $this->assertCount(1, $linkFacets);
    $link = array_values($linkFacets)[0];

    // "café " = 6 bytes (c=1, a=1, f=1, é=2, space=1).
    $this->assertSame(6, $link['index']['byteStart']);
    $this->assertSame(25, $link['index']['byteEnd']);

    // Verify the byte slice matches the URL.
    $slice = substr($text, $link['index']['byteStart'], $link['index']['byteEnd'] - $link['index']['byteStart']);
    $this->assertSame('https://example.com', $slice);
  }

  /**
   * @covers ::extract
   */
  public function testExtractReturnsEmptyForPlainText(): void {
    $facets = $this->extractor->extract('Just some plain text with no links.');
    $this->assertEmpty($facets);
  }

  /**
   * @covers ::extract
   */
  public function testFacetsAreSortedByByteStart(): void {
    $text = '#first https://example.com @test.bsky.social';

    $facets = $this->extractor->extract($text);

    $starts = array_map(fn($f) => $f['index']['byteStart'], $facets);
    $sorted = $starts;
    sort($sorted);

    $this->assertSame($sorted, $starts, 'Facets should be sorted by byteStart.');
  }

  /**
   * @covers ::forUrls
   */
  public function testForUrlsBuildsLinkFacets(): void {
    $text = 'See https://example.com/article for details.';
    $facets = $this->extractor->forUrls($text, ['https://example.com/article']);

    $this->assertCount(1, $facets);
    $this->assertSame('app.bsky.richtext.facet#link', $facets[0]['features'][0]['$type']);
    $this->assertSame('https://example.com/article', $facets[0]['features'][0]['uri']);
  }

}
