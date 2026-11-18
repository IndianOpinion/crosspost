<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Value;

/**
 * The network's handle on a prepared image, or the reason it failed.
 */
final class MediaRef
{
    /**
     * @param array<string, mixed> $payload network-specific reference (Bluesky blob)
     */
    private function __construct(
        public readonly string $id,
        public readonly array $payload,
        public readonly string $url,
        public readonly string $alt,
        public readonly int $width,
        public readonly int $height,
        public readonly ?Outcome $failure,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function ready(string $id, array $payload, string $url, string $alt, int $width, int $height): self
    {
        return new self($id, $payload, $url, $alt, $width, $height, null);
    }

    public static function failed(Outcome $failure): self
    {
        return new self('', [], '', '', 0, 0, $failure);
    }

    public function ok(): bool
    {
        return $this->failure === null;
    }
}
