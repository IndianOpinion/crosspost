<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Text;

/**
 * X's weighted character count, written from the published counting rules
 * (configuration version 3):
 *
 * - the text is normalised to NFC;
 * - every link counts as 23, whatever its length;
 * - an emoji sequence counts as 2, whatever its length;
 * - every other code point counts 1 inside the light ranges below and 2
 *   outside them;
 * - the limit for a standard account is 280.
 *
 * The rules may change. The ranges and numbers are kept here, in one place,
 * with fixture tests in tests/Text/XWeightedCountTest.php.
 */
final class XWeightedCount
{
    public const LIMIT = 280;
    public const URL_WEIGHT = 23;
    public const SCALE = 100;
    public const DEFAULT_WEIGHT = 200;
    public const EMOJI_WEIGHT = 200;

    /** @var list<array{0: int, 1: int, 2: int}> start, end, weight */
    public const RANGES = [
        [0x0000, 0x10FF, 100],
        [0x2000, 0x200D, 100],
        [0x2010, 0x201F, 100],
        [0x2032, 0x2037, 100],
    ];

    public static function count(string $text): int
    {
        if (class_exists(\Normalizer::class)) {
            $normal = \Normalizer::normalize($text, \Normalizer::FORM_C);
            if (is_string($normal)) {
                $text = $normal;
            }
        }
        [$rest, $links] = Urls::strip($text);
        $weight = $links * self::URL_WEIGHT * self::SCALE;
        foreach (Graphemes::split($rest) as $grapheme) {
            if (self::isEmoji($grapheme)) {
                $weight += self::EMOJI_WEIGHT;
                continue;
            }
            foreach (mb_str_split($grapheme, 1, 'UTF-8') as $char) {
                $weight += self::weight(mb_ord($char, 'UTF-8'));
            }
        }
        return intdiv($weight + self::SCALE - 1, self::SCALE);
    }

    private static function weight(int|false $cp): int
    {
        if ($cp === false) {
            return self::DEFAULT_WEIGHT;
        }
        foreach (self::RANGES as [$start, $end, $w]) {
            if ($cp >= $start && $cp <= $end) {
                return $w;
            }
        }
        return self::DEFAULT_WEIGHT;
    }

    /**
     * A grapheme is an emoji sequence when it holds a code point from the
     * pictographic blocks (regional indicators included). A table of blocks keeps
     * the result independent of the installed ICU and PCRE versions.
     */
    public static function isEmoji(string $grapheme): bool
    {
        foreach (mb_str_split($grapheme, 1, 'UTF-8') as $char) {
            $cp = mb_ord($char, 'UTF-8');
            if ($cp === false) {
                continue;
            }
            if (
                ($cp >= 0x1F000 && $cp <= 0x1FAFF)
                || ($cp >= 0x2600 && $cp <= 0x27BF)
                || ($cp >= 0x2B00 && $cp <= 0x2BFF)
                || ($cp >= 0x2300 && $cp <= 0x23FF)
            ) {
                return true;
            }
        }
        return false;
    }
}
