<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Value;

/**
 * A prepared image: a local file within the network's limits, or a public
 * URL for networks that fetch images themselves.
 */
final class MediaFile
{
    public function __construct(
        public readonly int $attachmentId,
        public readonly string $path,
        public readonly string $mimeType,
        public readonly int $bytes,
        public readonly int $width,
        public readonly int $height,
        public readonly string $alt,
        public readonly bool $temporary,
        public readonly string $publicUrl = '',
    ) {
    }
}
