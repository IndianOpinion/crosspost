<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost;

use IndianOpinion\Crosspost\Value\LinkCard;

/**
 * What would be sent for one post on one network. Built the same way for
 * job creation, the post panel, the settings preview and the CLI preview.
 */
final class Composition
{
    /**
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly string $network,
        public readonly string $text,
        public readonly int $count,
        public readonly int $limit,
        public readonly array $warnings,
        public readonly string $error,
        public readonly int $attachmentId,
        public readonly string $alt,
        public readonly string $imageNote,
        public readonly string $language,
        public readonly ?LinkCard $card,
        public readonly string $templateHash,
    ) {
    }

    public function ok(): bool
    {
        return $this->error === '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'network' => $this->network,
            'text' => $this->text,
            'count' => $this->count,
            'limit' => $this->limit,
            'warnings' => $this->warnings,
            'error' => $this->error,
            'image' => $this->attachmentId,
            'alt' => $this->alt,
            'image_note' => $this->imageNote,
            'language' => $this->language,
        ];
    }
}
