<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Text;

/**
 * Turns post fields into plain text for placeholders.
 */
final class PlainText
{
    /**
     * Strip tags and shortcodes, decode entities, drop control characters
     * and collapse whitespace to single spaces.
     */
    public static function line(string $value): string
    {
        $value = self::common($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';
        return trim($value);
    }

    /**
     * Like line(), but keeps paragraph breaks as single newlines.
     */
    public static function block(string $value): string
    {
        $value = preg_replace('#<(br|/p|/div|/li|/h[1-6])\b[^>]*>#i', "\n", $value) ?? $value;
        $value = self::common($value);
        $value = preg_replace('/[^\S\n]+/u', ' ', $value) ?? '';
        $value = preg_replace('/ *\n */', "\n", $value) ?? '';
        $value = preg_replace('/\n{2,}/', "\n", $value) ?? '';
        return trim($value);
    }

    private static function common(string $value): string
    {
        if (function_exists('strip_shortcodes')) {
            $value = strip_shortcodes($value);
        }
        // Remove any shortcode-shaped token left behind (unregistered shortcodes).
        $value = preg_replace('/\[\/?[a-zA-Z][\w-]*(\s[^\]]*)?\]/u', '', $value) ?? $value;
        // Drop script and style bodies, then every tag.
        $value = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $value) ?? $value;
        $value = strip_tags($value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Non-breaking and other odd spaces become plain spaces.
        $value = preg_replace('/[\x{00A0}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]/u', ' ', $value) ?? $value;
        // Control characters except newline and tab.
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;
        $value = str_replace("\r", '', $value);
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }
        return $value;
    }
}
