<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Support;

/**
 * Source of the current time, in Unix seconds and microseconds.
 */
interface Clock
{
    public function now(): int;

    public function micros(): int;
}
