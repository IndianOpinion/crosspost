<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Text;

/**
 * Finds links in text. Only http and https links are recognised; bare
 * domains are counted as ordinary text.
 */
final class Urls
{
    public const PATTERN = '#https?://[^\s<>"\x{3000}]+#iu';

    /**
     * Byte offsets and values of every link.
     *
     * @return list<array{start: int, end: int, url: string}>
     */
    public static function find(string $text): array
    {
        if (preg_match_all(self::PATTERN, $text, $m, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }
        $out = [];
        foreach ($m[0] as [$url, $offset]) {
            // Trailing punctuation is not part of the link.
            $trimmed = rtrim($url, '.,;:!?\'")]}');
            if (str_ends_with($url, ')') && substr_count($trimmed, '(') > substr_count($trimmed, ')')) {
                $trimmed .= ')';
            }
            $out[] = ['start' => $offset, 'end' => $offset + strlen($trimmed), 'url' => $trimmed];
        }
        return $out;
    }

    /**
     * Text with each link replaced by a placeholder of the given length in
     * ASCII, so a counter can count the rest.
     *
     * @return array{0: string, 1: int} text without links, number of links
     */
    public static function strip(string $text): array
    {
        $links = self::find($text);
        if ($links === []) {
            return [$text, 0];
        }
        $out = '';
        $pos = 0;
        foreach ($links as $link) {
            $out .= substr($text, $pos, $link['start'] - $pos);
            $pos = $link['end'];
        }
        $out .= substr($text, $pos);
        return [$out, count($links)];
    }

    /**
     * Normalised form for comparison: lower-case scheme and host, no
     * trailing slash, no fragment.
     */
    public static function normalise(string $url): string
    {
        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $url = preg_replace('/#.*$/', '', $url) ?? $url;
        if (preg_match('#^(https?)://([^/?]+)(.*)$#i', $url, $m) === 1) {
            $url = strtolower($m[1]) . '://' . strtolower($m[2]) . $m[3];
        }
        return rtrim($url, '/');
    }
}
