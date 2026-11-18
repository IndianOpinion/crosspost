<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Tests\Unit;

use IndianOpinion\Crosspost\Adapter\BlueskyAdapter;
use IndianOpinion\Crosspost\Support\Tid;
use PHPUnit\Framework\TestCase;

/**
 * Cases 32, 56 and 58, acceptance tests 49 and 51.
 */
final class BlueskyFacetsTest extends TestCase
{
    public function testLinkFacetByteOffsetsAfterDevanagariAndEmoji(): void
    {
        $text = "दिल्ली में बारिश 🌧️👨‍👩‍👧\n\nhttps://news.example/a?b=1";
        $facets = BlueskyAdapter::facets($text);
        self::assertCount(1, $facets);
        $start = $facets[0]['index']['byteStart'];
        $end = $facets[0]['index']['byteEnd'];
        self::assertSame(strpos($text, 'https://'), $start);
        self::assertSame(strlen($text), $end);
        self::assertSame('https://news.example/a?b=1', substr($text, $start, $end - $start));
        self::assertSame('app.bsky.richtext.facet#link', $facets[0]['features'][0]['$type']);
        // A character count would be wrong here.
        self::assertNotSame(mb_strpos($text, 'https://'), $start);
    }

    public function testHashtagsGetTagFacets(): void
    {
        $text = 'Rain #Kerala #मानसून and #2026, see #Weather.';
        $facets = BlueskyAdapter::facets($text);
        $tags = array_map(static fn (array $f): string => $f['features'][0]['tag'], $facets);
        self::assertSame(['Kerala', 'मानसून', 'Weather'], $tags);
        foreach ($facets as $facet) {
            $slice = substr($text, $facet['index']['byteStart'], $facet['index']['byteEnd'] - $facet['index']['byteStart']);
            self::assertSame('#' . $facet['features'][0]['tag'], $slice);
            self::assertSame('app.bsky.richtext.facet#tag', $facet['features'][0]['$type']);
        }
    }

    public function testTidIsThirteenSortableCharacters(): void
    {
        $a = Tid::generate(1794787200000000, 1);
        $b = Tid::generate(1794787200000001, 1);
        self::assertTrue(Tid::isValid($a));
        self::assertSame(13, strlen($a));
        self::assertLessThan(0, strcmp($a, $b));
    }
}
