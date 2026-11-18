<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost;

use IndianOpinion\Crosspost\Exception\ConfigurationException;

/**
 * The four networks, their labels and the per-network ceilings.
 *
 * Every per-network number that is a property of the network (not a site
 * setting) lives here, so there is one place to change it.
 */
final class Network
{
    public const X = 'x';
    public const THREADS = 'threads';
    public const BLUESKY = 'bluesky';
    public const MASTODON = 'mastodon';

    /** @var list<string> */
    public const ALL = [self::X, self::THREADS, self::BLUESKY, self::MASTODON];

    /**
     * Hard ceiling on the daily cap. The setting can never exceed it. Each
     * value is at or below the network's published posting limit for one
     * account.
     *
     * @var array<string, int>
     */
    public const CEILING = [
        self::X => 50,
        self::THREADS => 50,
        self::BLUESKY => 50,
        self::MASTODON => 50,
    ];

    /** @var array<string, string> */
    private const LABELS = [
        self::X => 'X',
        self::THREADS => 'Threads',
        self::BLUESKY => 'Bluesky',
        self::MASTODON => 'Mastodon',
    ];

    public static function isValid(string $network): bool
    {
        return in_array($network, self::ALL, true);
    }

    public static function assertValid(string $network): string
    {
        if (!self::isValid($network)) {
            throw new ConfigurationException(sprintf('Unknown network "%s".', $network));
        }
        return $network;
    }

    public static function label(string $network): string
    {
        return self::LABELS[$network] ?? $network;
    }

    public static function ceiling(string $network): int
    {
        return self::CEILING[$network] ?? 0;
    }
}
