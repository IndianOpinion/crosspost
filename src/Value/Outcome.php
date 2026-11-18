<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Value;

/**
 * The result of one attempt, decided by the adapter.
 */
final class Outcome
{
    /** The network returned the new post's id. */
    public const POSTED = 'posted';
    /** The request provably did not create a post. */
    public const RETRYABLE = 'retryable';
    /** The final create call was sent and its result is unknown. */
    public const UNCERTAIN = 'uncertain';
    /** The network refused the request; it will not change on retry. */
    public const PERMANENT = 'permanent';
    /** The credentials were refused. */
    public const AUTH = 'auth';
    /** Reconciliation proved the post does not exist: safe to resend with the same key. */
    public const ABSENT = 'absent';

    /**
     * @param array<string, string|int> $meta
     */
    private function __construct(
        public readonly string $kind,
        public readonly string $message = '',
        public readonly int $httpStatus = 0,
        public readonly string $remoteId = '',
        public readonly string $remoteUrl = '',
        public readonly int $retryAfter = 0,
        public readonly bool $cooldown = false,
        public readonly bool $blocked = false,
        public readonly array $meta = [],
    ) {
    }

    /**
     * @param array<string, string|int> $meta
     */
    public static function posted(string $remoteId, string $remoteUrl, int $status = 200, array $meta = []): self
    {
        return new self(self::POSTED, '', $status, $remoteId, $remoteUrl, 0, false, false, $meta);
    }

    /**
     * @param array<string, string|int> $meta
     */
    public static function retryable(string $message, int $status = 0, int $retryAfter = 0, bool $cooldown = false, array $meta = []): self
    {
        return new self(self::RETRYABLE, $message, $status, '', '', $retryAfter, $cooldown, false, $meta);
    }

    /**
     * The kill switch stopped the call before it left the site.
     */
    public static function blocked(): self
    {
        return new self(self::RETRYABLE, 'kill switch', 0, '', '', 0, false, true);
    }

    /**
     * @param array<string, string|int> $meta
     */
    public static function uncertain(string $message, int $status = 0, array $meta = []): self
    {
        return new self(self::UNCERTAIN, $message, $status, '', '', 0, false, false, $meta);
    }

    public static function permanent(string $message, int $status = 0): self
    {
        return new self(self::PERMANENT, $message, $status);
    }

    public static function auth(string $message, int $status = 0): self
    {
        return new self(self::AUTH, $message, $status);
    }

    public static function absent(): self
    {
        return new self(self::ABSENT);
    }

    public function is(string $kind): bool
    {
        return $this->kind === $kind;
    }
}
