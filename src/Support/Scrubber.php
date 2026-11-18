<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Support;

/**
 * Removes secrets from any text before it is stored or shown.
 *
 * 1. Every known secret value is replaced with [redacted].
 * 2. The value after token=, access_token, refresh_token, secret,
 *    authorization, password and similar keys is replaced, in query
 *    strings, JSON and headers alike.
 * 3. Any run of 40 or more token-like characters (letters and digits
 *    mixed) is replaced.
 * 4. The result is cut to 255 bytes on a UTF-8 boundary.
 */
final class Scrubber
{
    public const MAX_BYTES = 255;
    public const REDACTED = '[redacted]';

    /** @var list<string> */
    private array $secrets = [];

    /**
     * @param list<string> $secrets
     */
    public function __construct(array $secrets = [])
    {
        foreach ($secrets as $secret) {
            $this->remember($secret);
        }
    }

    public function remember(string $secret): void
    {
        // Very short values would redact ordinary words; they are not tokens.
        if (strlen($secret) >= 6 && !in_array($secret, $this->secrets, true)) {
            $this->secrets[] = $secret;
            // Longest first, so a secret that contains another is caught whole.
            usort($this->secrets, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        }
    }

    public function scrub(string $text, int $maxBytes = self::MAX_BYTES): string
    {
        foreach ($this->secrets as $secret) {
            $text = str_replace([$secret, rawurlencode($secret)], self::REDACTED, $text);
        }
        $keys = '(?:access_token|refresh_token|id_token|client_secret|app_password|token|secret|password|passwd|authorization|api[_-]?key|accessJwt|refreshJwt|code_verifier)';
        // key=value and key: value (query strings, form bodies, headers).
        $text = preg_replace('/(\b' . $keys . '\b["\']?\s*[=:]\s*["\']?)(?:Bearer\s+|Basic\s+)?[^\s"\'&,;}]+/i', '$1' . self::REDACTED, $text) ?? $text;
        // Bearer and Basic credentials anywhere.
        $text = preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]{8,}/', '$1 ' . self::REDACTED, $text) ?? $text;
        // Long token-like runs.
        $text = preg_replace('/(?=[A-Za-z0-9._~+=-]*[0-9])(?=[A-Za-z0-9._~+=-]*[A-Za-z])[A-Za-z0-9._~+=-]{40,}/', self::REDACTED, $text) ?? $text;
        // Newlines and control characters make log rows hard to read.
        $text = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text) ?? $text;
        return self::cut(trim($text), $maxBytes);
    }

    /**
     * Cut to at most $maxBytes without splitting a UTF-8 sequence.
     */
    public static function cut(string $text, int $maxBytes = self::MAX_BYTES): string
    {
        if (strlen($text) <= $maxBytes) {
            return $text;
        }
        $cut = substr($text, 0, $maxBytes);
        // Walk back over continuation bytes to a character start.
        $i = strlen($cut);
        while ($i > 0 && (ord($text[$i]) & 0xC0) === 0x80) {
            $i--;
        }
        return substr($text, 0, $i);
    }
}
