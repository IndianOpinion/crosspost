<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Text;

/**
 * Length counting rules, one per network family.
 */
final class Counter
{
    /** X: weighted count, links as 23. */
    public const WEIGHTED = 'weighted';
    /** Bluesky: grapheme clusters, links counted as written. */
    public const GRAPHEMES = 'graphemes';
    /** Mastodon: grapheme clusters, every link counted as a fixed length. */
    public const GRAPHEMES_LINKS = 'graphemes_links';
    /** Threads: Unicode code points. */
    public const CODE_POINTS = 'code_points';

    public static function count(string $rule, string $text, int $linkLength = 23): int
    {
        switch ($rule) {
            case self::WEIGHTED:
                return XWeightedCount::count($text);
            case self::GRAPHEMES:
                return Graphemes::count($text);
            case self::GRAPHEMES_LINKS:
                [$rest, $links] = Urls::strip($text);
                return Graphemes::count($rest) + $links * $linkLength;
            case self::CODE_POINTS:
                return mb_strlen($text, 'UTF-8');
        }
        throw new \InvalidArgumentException(sprintf('Unknown counting rule "%s".', $rule));
    }
}
