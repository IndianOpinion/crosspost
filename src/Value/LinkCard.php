<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Value;

/**
 * A link preview built from local data (Bluesky external embed).
 */
final class LinkCard
{
    public function __construct(
        public readonly string $url,
        public readonly string $title,
        public readonly string $description,
    ) {
    }
}
