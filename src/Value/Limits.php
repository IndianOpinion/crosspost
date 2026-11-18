<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Value;

/**
 * What a network accepts. Declared by each adapter.
 */
final class Limits
{
    public const TRANSPORT_UPLOAD = 'upload';
    public const TRANSPORT_PUBLIC_URL = 'public_url';
    public const TRANSPORT_NONE = 'none';

    /** The network accepts a client idempotency key on create. */
    public const RECONCILE_IDEMPOTENT = 'idempotent';
    /** The client chooses the record key, which can be read back. */
    public const RECONCILE_RECORD_KEY = 'record_key';
    /** Only a listing of recent posts can tell whether a post landed. */
    public const RECONCILE_LISTING = 'listing';
    /** No automatic check: an uncertain post waits for a person. */
    public const RECONCILE_NONE = 'none';

    /**
     * @param list<string> $mimeTypes
     */
    public function __construct(
        public readonly int $textLimit,
        public readonly string $countingRule,
        public readonly string $imageTransport,
        public readonly int $maxBytes,
        public readonly int $maxEdge,
        public readonly int $preferredEdge,
        public readonly array $mimeTypes,
        public readonly int $altLimit,
        public readonly string $reconcile,
    ) {
    }

    public function acceptsImages(): bool
    {
        return $this->imageTransport !== self::TRANSPORT_NONE;
    }

    public function idempotent(): bool
    {
        return $this->reconcile === self::RECONCILE_IDEMPOTENT
            || $this->reconcile === self::RECONCILE_RECORD_KEY;
    }

    public function withTextLimit(int $limit): self
    {
        return new self(
            $limit,
            $this->countingRule,
            $this->imageTransport,
            $this->maxBytes,
            $this->maxEdge,
            $this->preferredEdge,
            $this->mimeTypes,
            $this->altLimit,
            $this->reconcile,
        );
    }
}
