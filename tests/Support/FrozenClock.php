<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Tests\Support;

use IndianOpinion\Crosspost\Support\Clock;
use IndianOpinion\Crosspost\Support\Sleeper;

/**
 * A clock tests move by hand. Sleeping advances it.
 */
final class FrozenClock implements Clock, Sleeper
{
    public int $micro = 0;

    public function __construct(public int $time = 1794787200)
    {
    }

    public function now(): int
    {
        return $this->time;
    }

    public function micros(): int
    {
        return $this->time * 1000000 + ($this->micro++);
    }

    public function advance(int $seconds): void
    {
        $this->time += $seconds;
    }

    public function sleep(int $seconds): void
    {
        $this->time += $seconds;
    }
}
