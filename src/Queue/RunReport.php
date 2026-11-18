<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Queue;

/**
 * What one processing run did.
 */
final class RunReport
{
    public bool $locked = false;
    public bool $stopped = false;
    public string $stopReason = '';
    public int $sent = 0;
    public int $posted = 0;
    public int $reconciled = 0;
    public int $held = 0;
    public int $failed = 0;
    public int $skipped = 0;
    public int $nextRun = 0;

    /** @var list<string> */
    public array $notes = [];

    public function stop(string $reason): void
    {
        $this->stopped = true;
        $this->stopReason = $reason;
    }
}
