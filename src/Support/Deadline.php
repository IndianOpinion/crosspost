<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Support;

/**
 * A point in time that bounds a wait. Readiness polls and runs use it.
 */
final class Deadline
{
    public function __construct(private Clock $clock, private int $until)
    {
    }

    public static function in(Clock $clock, int $seconds): self
    {
        return new self($clock, $clock->now() + max(0, $seconds));
    }

    public function remaining(): int
    {
        return max(0, $this->until - $this->clock->now());
    }

    public function expired(): bool
    {
        return $this->remaining() <= 0;
    }

    /**
     * The earlier of this deadline and one $seconds from now.
     */
    public function capped(int $seconds): self
    {
        return new self($this->clock, min($this->until, $this->clock->now() + max(0, $seconds)));
    }

    public function clock(): Clock
    {
        return $this->clock;
    }
}
