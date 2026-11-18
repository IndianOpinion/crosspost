<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Support;

/**
 * Waits between readiness polls. Never used between jobs.
 */
interface Sleeper
{
    public function sleep(int $seconds): void;
}
