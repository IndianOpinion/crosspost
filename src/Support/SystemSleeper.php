<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Support;

final class SystemSleeper implements Sleeper
{
    public function sleep(int $seconds): void
    {
        if ($seconds > 0) {
            sleep($seconds);
        }
    }
}
