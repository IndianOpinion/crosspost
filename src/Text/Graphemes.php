<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Text;

/**
 * Grapheme cluster helpers. Uses intl when present, PCRE \X otherwise.
 */
final class Graphemes
{
    private static ?bool $forceFallback = null;

    /**
     * Tests use this to exercise the fallback.
     */
    public static function forceFallback(?bool $force): void
    {
        self::$forceFallback = $force;
    }

    public static function usingIntl(): bool
    {
        return self::$forceFallback !== true && function_exists('grapheme_strlen');
    }

    /**
     * Viramas that join consonants into one cluster (Unicode 15.1 rule
     * GB9c): Devanagari, Bengali, Gujarati, Oriya, Telugu, Malayalam.
     */
    private const LINKERS = "\u{094D}\u{09CD}\u{0ACD}\u{0B4D}\u{0C4D}\u{0D4D}";

    /**
     * @return list<string>
     */
    public static function split(string $text): array
    {
        if ($text === '') {
            return [];
        }
        if (self::usingIntl() && class_exists(\IntlBreakIterator::class)) {
            $it = \IntlBreakIterator::createCharacterInstance('en');
            if ($it !== null) {
                $it->setText($text);
                $out = [];
                $start = 0;
                foreach ($it as $boundary) {
                    if ($boundary > $start) {
                        $out[] = substr($text, $start, $boundary - $start);
                        $start = $boundary;
                    }
                }
                return $out;
            }
        }
        if (preg_match_all('/\X/u', $text, $m) === false) {
            return [];
        }
        // Older PCRE splits Indic conjuncts; join a cluster that ends in a
        // virama (optionally with a joiner) to the consonant after it.
        $out = [];
        foreach ($m[0] as $cluster) {
            $previous = array_pop($out);
            if ($previous !== null && preg_match('/[' . self::LINKERS . ']\x{200D}?$/u', $previous) === 1 && preg_match('/^\p{Lo}/u', $cluster) === 1) {
                $out[] = $previous . $cluster;
                continue;
            }
            if ($previous !== null) {
                $out[] = $previous;
            }
            $out[] = $cluster;
        }
        return $out;
    }

    public static function count(string $text): int
    {
        if ($text === '') {
            return 0;
        }
        return count(self::split($text));
    }

    /**
     * The first $max grapheme clusters of $text.
     */
    public static function head(string $text, int $max): string
    {
        if ($max <= 0) {
            return '';
        }
        return implode('', array_slice(self::split($text), 0, $max));
    }
}
