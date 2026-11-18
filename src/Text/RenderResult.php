<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Text;

/**
 * The text a template produced for one network, and how it was counted.
 */
final class RenderResult
{
    public const NO_ROOM = 'template leaves no room';
    public const TOO_LONG = 'text too long';

    /**
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly string $text,
        public readonly int $count,
        public readonly int $limit,
        public readonly array $warnings,
        public readonly string $error = '',
        public readonly bool $shortened = false,
    ) {
    }

    public function ok(): bool
    {
        return $this->error === '';
    }
}
