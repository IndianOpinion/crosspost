<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Text;

/**
 * Renders a template and fits it to a network's limit.
 *
 * Shortening order: excerpt, then hashtags (whole ones, from the end),
 * then title. The URL and every literal part of the template are never
 * cut. A cut never splits a grapheme cluster or a link, prefers a word
 * boundary in the last 40 percent of the kept span, and ends with "...".
 */
final class Renderer
{
    public const ELLIPSIS = '...';

    /** @var list<string> */
    public const KNOWN = ['title', 'excerpt', 'url', 'site_name', 'author', 'category', 'tags', 'date'];

    /** @var \Closure(string): int */
    private \Closure $count;

    /**
     * @param callable(string): int $count the network's counting rule
     */
    public function __construct(callable $count, private int $limit, private int $minBody = 40)
    {
        $this->count = \Closure::fromCallable($count);
    }

    /**
     * @param array<string, string> $values placeholder values, plain text
     * @param list<string> $hashtags for {tags}
     * @param bool $autoExcerpt the excerpt came from the content, so a cut prefers a sentence end
     */
    public function render(string $template, array $values, array $hashtags, bool $autoExcerpt = false): RenderResult
    {
        $tokens = self::tokens($template);
        $warnings = [];
        foreach ($tokens as $token) {
            if ($token[0] === 'ph' && $token[1] !== 'tags' && !array_key_exists($token[1], $values)) {
                $warnings[] = sprintf('Unknown placeholder {%s} renders as empty.', $token[1]);
            }
        }

        // The fixed parts alone: literals and the URL.
        $fixed = self::assemble($tokens, ['url' => $values['url'] ?? ''], []);
        $room = $this->limit - ($this->count)($fixed);
        if ($room < $this->minBody) {
            return new RenderResult($fixed, ($this->count)($fixed), $this->limit, $warnings, RenderResult::NO_ROOM);
        }

        $text = self::assemble($tokens, $values, $hashtags);
        if ($this->fits($text)) {
            return new RenderResult($text, ($this->count)($text), $this->limit, $warnings);
        }

        $used = self::used($tokens);

        if (isset($used['excerpt']) && ($values['excerpt'] ?? '') !== '') {
            $values['excerpt'] = $this->cut($tokens, $values, $hashtags, 'excerpt', $autoExcerpt);
            $text = self::assemble($tokens, $values, $hashtags);
        }
        if (!$this->fits($text) && isset($used['tags'])) {
            while ($hashtags !== [] && !$this->fits($text)) {
                array_pop($hashtags);
                $text = self::assemble($tokens, $values, $hashtags);
            }
        }
        if (!$this->fits($text) && isset($used['title']) && ($values['title'] ?? '') !== '') {
            $values['title'] = $this->cut($tokens, $values, $hashtags, 'title', false);
            $text = self::assemble($tokens, $values, $hashtags);
        }

        $count = ($this->count)($text);
        if ($count > $this->limit) {
            return new RenderResult($text, $count, $this->limit, $warnings, RenderResult::TOO_LONG, true);
        }
        return new RenderResult($text, $count, $this->limit, $warnings, '', true);
    }

    private function fits(string $text): bool
    {
        return ($this->count)($text) <= $this->limit;
    }

    /**
     * Longest prefix of the named value that lets the whole text fit.
     *
     * @param list<array{0: string, 1: string}> $tokens
     * @param array<string, string> $values
     * @param list<string> $hashtags
     */
    private function cut(array $tokens, array $values, array $hashtags, string $name, bool $sentence): string
    {
        $original = $values[$name];
        $graphemes = Graphemes::split($original);
        $spans = self::linkSpans($graphemes);

        $try = function (int $k) use ($tokens, $values, $hashtags, $name, $graphemes, $spans, $sentence): ?string {
            $candidate = self::prefix($graphemes, $k, $spans, $sentence);
            $values[$name] = $candidate;
            return $this->fits(self::assemble($tokens, $values, $hashtags)) ? $candidate : null;
        };

        $lo = 0;
        $hi = count($graphemes) - 1;
        $best = '';
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            $candidate = $try($mid);
            if ($candidate !== null) {
                $best = $candidate;
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }
        return $best;
    }

    /**
     * The first $k graphemes, moved back to a link start, a sentence end or
     * a word boundary, with an ellipsis.
     *
     * @param list<string> $graphemes
     * @param list<array{0: int, 1: int}> $spans link spans in grapheme indexes
     */
    private static function prefix(array $graphemes, int $k, array $spans, bool $sentence): string
    {
        if ($k <= 0) {
            return '';
        }
        foreach ($spans as [$start, $end]) {
            if ($k > $start && $k < $end) {
                $k = $start;
            }
        }
        if ($k <= 0) {
            return '';
        }
        $floor = (int) ceil($k * 0.6);
        if ($sentence) {
            for ($j = $k - 1; $j >= 1; $j--) {
                if (preg_match('/^[.!?\x{0964}\x{3002}]$/u', $graphemes[$j - 1]) === 1 && trim($graphemes[$j]) === '') {
                    return rtrim(implode('', array_slice($graphemes, 0, $j)));
                }
            }
        }
        $cut = $k;
        if ($k < count($graphemes) && trim($graphemes[$k]) !== '') {
            for ($j = $k - 1; $j >= $floor; $j--) {
                if (trim($graphemes[$j]) === '') {
                    $cut = $j;
                    break;
                }
            }
        }
        $text = rtrim(implode('', array_slice($graphemes, 0, $cut)));
        $text = rtrim($text, " ,;:-\u{2013}");
        if ($text === '') {
            return '';
        }
        return $text . self::ELLIPSIS;
    }

    /**
     * @param list<string> $graphemes
     * @return list<array{0: int, 1: int}>
     */
    private static function linkSpans(array $graphemes): array
    {
        $text = implode('', $graphemes);
        $links = Urls::find($text);
        if ($links === []) {
            return [];
        }
        // Map byte offsets to grapheme indexes.
        $byteToIndex = [];
        $offset = 0;
        foreach ($graphemes as $i => $g) {
            $byteToIndex[$offset] = $i;
            $offset += strlen($g);
        }
        $byteToIndex[$offset] = count($graphemes);
        $spans = [];
        foreach ($links as $link) {
            $start = $byteToIndex[$link['start']] ?? null;
            $end = $byteToIndex[$link['end']] ?? null;
            if ($start !== null && $end !== null) {
                $spans[] = [$start, $end];
            }
        }
        return $spans;
    }

    /**
     * @return list<array{0: string, 1: string}> pairs of ('lit', text) or ('ph', name)
     */
    public static function tokens(string $template): array
    {
        $parts = preg_split('/(\{[a-z][a-z0-9_]*\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $tokens = [];
        foreach ($parts === false ? [] : $parts as $part) {
            if (preg_match('/^\{([a-z][a-z0-9_]*)\}$/', $part, $m) === 1) {
                $tokens[] = ['ph', $m[1]];
            } else {
                $tokens[] = ['lit', $part];
            }
        }
        return $tokens;
    }

    /**
     * @param list<array{0: string, 1: string}> $tokens
     * @return array<string, true>
     */
    private static function used(array $tokens): array
    {
        $out = [];
        foreach ($tokens as [$type, $value]) {
            if ($type === 'ph') {
                $out[$value] = true;
            }
        }
        return $out;
    }

    /**
     * @param list<array{0: string, 1: string}> $tokens
     * @param array<string, string> $values
     * @param list<string> $hashtags
     */
    public static function assemble(array $tokens, array $values, array $hashtags): string
    {
        $out = '';
        foreach ($tokens as [$type, $value]) {
            if ($type === 'lit') {
                $out .= $value;
            } elseif ($value === 'tags') {
                $out .= implode(' ', $hashtags);
            } else {
                $out .= $values[$value] ?? '';
            }
        }
        return self::tidy($out);
    }

    /**
     * Template newlines are kept; three or more collapse to two. Trailing
     * spaces on a line and doubled spaces left by empty values go.
     */
    public static function tidy(string $text): string
    {
        $text = str_replace("\r", '', $text);
        $text = preg_replace('/[ \t]+\n/', "\n", $text) ?? $text;
        $text = preg_replace('/\n[ \t]+/', "\n", $text) ?? $text;
        $text = preg_replace('/ {2,}/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        return trim($text);
    }
}
