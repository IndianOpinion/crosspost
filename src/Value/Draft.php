<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Value;

/**
 * Everything a publish call needs: the frozen text and the stable
 * idempotency material of the job.
 */
final class Draft
{
    /**
     * @param array<string, string|int> $meta adapter state carried between attempts (Threads container id)
     */
    public function __construct(
        public readonly int $jobId,
        public readonly int $postId,
        public readonly string $text,
        public readonly string $language,
        public readonly string $idempotencyKey,
        public readonly ?LinkCard $card = null,
        public readonly string $visibility = '',
        public readonly bool $textOnlyOnImageFailure = true,
        public readonly array $meta = [],
        public readonly int $firstSentAt = 0,
    ) {
    }
}
