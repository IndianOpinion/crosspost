<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Store;

/**
 * One log row: an attempt or a notable event.
 */
final class LogEntry
{
    /** @var list<string> */
    public const EVENTS = ['created', 'attempt', 'posted', 'retry', 'uncertain', 'unknown', 'reconciled', 'failed', 'skipped', 'held', 'released', 'cancelled', 'auth', 'cooldown', 'image_fallback', 'refresh', 'verify', 'confirm', 'kill_switch', 'schema', 'run'];

    public function __construct(
        public readonly ?int $jobId,
        public readonly string $network,
        public readonly string $event,
        public readonly int $httpStatus,
        public readonly string $message,
        public readonly int $durationMs,
        public readonly int $createdAt,
        public readonly int $id = 0,
        public readonly int $postId = 0,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            isset($row['job_id']) && (int) $row['job_id'] > 0 ? (int) $row['job_id'] : null,
            (string) ($row['network'] ?? ''),
            (string) ($row['event'] ?? ''),
            (int) ($row['http_status'] ?? 0),
            (string) ($row['message'] ?? ''),
            (int) ($row['duration_ms'] ?? 0),
            (int) ($row['created_at'] ?? 0),
            (int) ($row['id'] ?? 0),
            (int) ($row['post_id'] ?? 0),
        );
    }
}
