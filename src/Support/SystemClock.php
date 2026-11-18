<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Support;

final class SystemClock implements Clock
{
    public function now(): int
    {
        return time();
    }

    public function micros(): int
    {
        return (int) floor(microtime(true) * 1000000);
    }
}
