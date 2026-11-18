<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost;

/**
 * Preview confirmation per network: who confirmed, when, and the hash of
 * the output-affecting settings they saw. One non-autoloaded option each.
 */
final class Review
{
    public const OPTION_PREFIX = 'crosspost_review_';

    /**
     * @return array{user: int, time: int, hash: string}
     */
    public function get(string $network): array
    {
        $stored = get_option(self::OPTION_PREFIX . $network, []);
        $stored = is_array($stored) ? $stored : [];
        return [
            'user' => (int) ($stored['user'] ?? 0),
            'time' => (int) ($stored['time'] ?? 0),
            'hash' => is_string($stored['hash'] ?? null) ? $stored['hash'] : '',
        ];
    }

    public function confirm(string $network, int $userId, int $time, string $hash): void
    {
        update_option(self::OPTION_PREFIX . $network, ['user' => $userId, 'time' => $time, 'hash' => $hash], false);
    }

    public function isConfirmed(string $network, string $currentHash): bool
    {
        $record = $this->get($network);
        return $record['hash'] !== '' && hash_equals($record['hash'], $currentHash);
    }

    public function forget(string $network): void
    {
        delete_option(self::OPTION_PREFIX . $network);
    }
}
