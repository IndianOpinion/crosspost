<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Http;

/**
 * One HTTP exchange, reduced to what the adapters need.
 */
final class HttpResponse
{
    /** No transport error. */
    public const OK = '';
    /** The request never reached the server: DNS, refused, TLS, connect timeout. */
    public const CONNECT = 'connect';
    /** The request may have reached the server: read timeout, reset after send. */
    public const READ = 'read';
    /** The kill switch stopped the request before it left the site. */
    public const BLOCKED = 'blocked';

    /**
     * @param array<string, string> $headers lower-case names
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
        public readonly string $errorKind = self::OK,
        public readonly string $errorMessage = '',
        public readonly int $durationMs = 0,
    ) {
    }

    public function failed(): bool
    {
        return $this->errorKind !== self::OK;
    }

    public function success(): bool
    {
        return !$this->failed() && $this->status >= 200 && $this->status < 300;
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    /**
     * @return array<mixed>
     */
    public function json(): array
    {
        if ($this->body === '') {
            return [];
        }
        try {
            $data = json_decode($this->body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        return is_array($data) ? $data : [];
    }
}
