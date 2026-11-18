<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Text;

/**
 * Turns tag names into hashtags.
 */
final class Hashtags
{
    /**
     * Spaces and punctuation removed; letters, marks and digits in any
     * script kept. A tag that becomes empty or all digits is dropped.
     *
     * @param list<string> $names
     * @return list<string>
     */
    public static function fromNames(array $names): array
    {
        $out = [];
        foreach ($names as $name) {
            $tag = self::fromName($name);
            if ($tag !== '' && !in_array($tag, $out, true)) {
                $out[] = $tag;
            }
        }
        return $out;
    }

    public static function fromName(string $name): string
    {
        $name = PlainText::line($name);
        $body = preg_replace('/[^\p{L}\p{M}\p{N}_]+/u', '', $name) ?? '';
        if ($body === '' || preg_match('/^[\p{N}_]+$/u', $body) === 1) {
            return '';
        }
        return '#' . $body;
    }
}
