<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Store;

/**
 * One planned announcement of one post on one network.
 */
final class Job
{
    public const PENDING = 'pending';
    public const HELD = 'held';
    public const SENDING = 'sending';
    public const UNCERTAIN = 'uncertain';
    /** May be live; never checked or resent automatically (X). */
    public const UNKNOWN = 'unknown';
    public const POSTED = 'posted';
    public const FAILED = 'failed';
    public const SKIPPED = 'skipped';
    public const CANCELLED = 'cancelled';

    /** @var list<string> */
    public const STATES = [self::PENDING, self::HELD, self::SENDING, self::UNCERTAIN, self::UNKNOWN, self::POSTED, self::FAILED, self::SKIPPED, self::CANCELLED];

    /** States that may already be live: they count against the cap. */
    public const COUNTED = [self::SENDING, self::UNCERTAIN, self::UNKNOWN, self::POSTED];

    /** States waiting to be sent. */
    public const QUEUED = [self::PENDING, self::HELD];

    // Reasons.
    public const R_TOO_OLD = 'too old';
    public const R_OPTED_OUT = 'opted out';
    public const R_NETWORK_OFF = 'network off';
    public const R_PASSWORD = 'password protected';
    public const R_NOT_PUBLISHED = 'not published';
    public const R_POST_GONE = 'post gone';
    public const R_POST_TYPE = 'post type not announced';
    public const R_STALE = 'stale';
    public const R_CAP = 'cap';
    public const R_SPACING = 'spacing';
    public const R_COOLDOWN = 'cooldown';
    public const R_KILL_SWITCH = 'kill switch';
    public const R_AUTH = 'auth';
    public const R_PREVIEW = 'preview needed';
    public const R_FIRST_POST = 'first post review';
    public const R_NO_CREDENTIALS = 'no credentials';
    public const R_RETRIES = 'retries exhausted';
    public const R_NEVER_LANDED = 'never landed';
    public const R_NO_ROOM = 'template leaves no room';
    public const R_TOO_LONG = 'text too long';
    public const R_SCHEMA = 'database columns missing';
    public const R_UNPUBLISHED = 'post unpublished';

    public int $id = 0;
    public int $postId = 0;
    public string $network = '';
    public string $accountId = '';
    public string $state = self::PENDING;
    public string $reason = '';
    public string $text = '';
    public string $textHash = '';
    public int $attachmentId = 0;
    public string $templateHash = '';
    public string $idempotencyKey = '';
    public string $remoteId = '';
    public string $remoteUrl = '';
    public int $attempts = 0;
    /** @var array<string, string|int> */
    public array $meta = [];
    public int $releasedBy = 0;
    public int $nextAttemptAt = 0;
    public int $sentAt = 0;
    public int $firstSentAt = 0;
    public int $createdAt = 0;
    public int $updatedAt = 0;
    public int $postedAt = 0;

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $job = new self();
        $job->id = (int) ($row['id'] ?? 0);
        $job->postId = (int) ($row['post_id'] ?? 0);
        $job->network = (string) ($row['network'] ?? '');
        $job->accountId = (string) ($row['account_id'] ?? '');
        $job->state = (string) ($row['state'] ?? self::PENDING);
        $job->reason = (string) ($row['reason'] ?? '');
        $job->text = (string) ($row['text'] ?? '');
        $job->textHash = (string) ($row['text_hash'] ?? '');
        $job->attachmentId = (int) ($row['attachment_id'] ?? 0);
        $job->templateHash = (string) ($row['template_hash'] ?? '');
        $job->idempotencyKey = (string) ($row['idempotency_key'] ?? '');
        $job->remoteId = (string) ($row['remote_id'] ?? '');
        $job->remoteUrl = (string) ($row['remote_url'] ?? '');
        $job->attempts = (int) ($row['attempts'] ?? 0);
        $meta = $row['meta'] ?? '';
        if (is_string($meta) && $meta !== '') {
            try {
                $decoded = json_decode($meta, true, 8, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $decoded = [];
            }
            if (is_array($decoded)) {
                foreach ($decoded as $key => $value) {
                    if (is_string($key) && (is_string($value) || is_int($value))) {
                        $job->meta[$key] = $value;
                    }
                }
            }
        } elseif (is_array($meta)) {
            /** @var array<string, string|int> $meta */
            $job->meta = $meta;
        }
        $job->releasedBy = (int) ($row['released_by'] ?? 0);
        $job->nextAttemptAt = (int) ($row['next_attempt_at'] ?? 0);
        $job->sentAt = (int) ($row['sent_at'] ?? 0);
        $job->firstSentAt = (int) ($row['first_sent_at'] ?? 0);
        $job->createdAt = (int) ($row['created_at'] ?? 0);
        $job->updatedAt = (int) ($row['updated_at'] ?? 0);
        $job->postedAt = (int) ($row['posted_at'] ?? 0);
        return $job;
    }

    /**
     * @return array<string, string|int>
     */
    public function toRow(): array
    {
        return [
            'post_id' => $this->postId,
            'network' => $this->network,
            'account_id' => $this->accountId,
            'state' => $this->state,
            'reason' => $this->reason,
            'text' => $this->text,
            'text_hash' => $this->textHash,
            'attachment_id' => $this->attachmentId,
            'template_hash' => $this->templateHash,
            'idempotency_key' => $this->idempotencyKey,
            'remote_id' => $this->remoteId,
            'remote_url' => $this->remoteUrl,
            'attempts' => $this->attempts,
            'meta' => $this->meta === [] ? '' : (string) json_encode($this->meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'released_by' => $this->releasedBy,
            'next_attempt_at' => $this->nextAttemptAt,
            'sent_at' => $this->sentAt,
            'first_sent_at' => $this->firstSentAt,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'posted_at' => $this->postedAt,
        ];
    }

    public function isQueued(): bool
    {
        return in_array($this->state, self::QUEUED, true);
    }
}
