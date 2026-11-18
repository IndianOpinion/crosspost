<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Text;

/**
 * Normalised text for matching a sent post against a network's listing.
 */
final class Normaliser
{
    /**
     * Accepts plain text or the HTML some networks return. Entities are
     * decoded, links compared by their target, whitespace collapsed.
     */
    public static function normalise(string $text): string
    {
        if (preg_match('/<[a-z][^>]*>/i', $text) === 1) {
            $text = self::fromHtml($text);
        }
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (class_exists(\Normalizer::class)) {
            $normal = \Normalizer::normalize($text, \Normalizer::FORM_C);
            if (is_string($normal)) {
                $text = $normal;
            }
        }
        $text = preg_replace_callback(Urls::PATTERN, static fn (array $m): string => Urls::normalise(rtrim($m[0], '.,;:!?')), $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    public static function hash(string $text): string
    {
        return hash('sha256', self::normalise($text));
    }

    private static function fromHtml(string $html): string
    {
        // Links: hashtags and mentions keep their text, others their target.
        $html = preg_replace_callback(
            '#<a\b([^>]*)>(.*?)</a>#is',
            static function (array $m): string {
                $attrs = $m[1];
                $inner = strip_tags($m[2]);
                if (preg_match('/class="[^"]*\b(hashtag|mention)\b/i', $attrs) === 1) {
                    return $inner;
                }
                if (preg_match('/href="([^"]+)"/i', $attrs, $h) === 1) {
                    return $h[1];
                }
                return $inner;
            },
            $html,
        ) ?? $html;
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</p>\s*<p[^>]*>#i', "\n\n", $html) ?? $html;
        return strip_tags($html);
    }
}
