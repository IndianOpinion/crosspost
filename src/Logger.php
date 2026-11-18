<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost;

use IndianOpinion\Crosspost\Store\LogEntry;
use IndianOpinion\Crosspost\Store\Store;
use IndianOpinion\Crosspost\Support\Clock;
use IndianOpinion\Crosspost\Support\Scrubber;

/**
 * Writes log rows. Every message passes the scrubber first, with every
 * stored secret known to it.
 */
final class Logger
{
    /** @var \Closure(): list<string> */
    private \Closure $secrets;

    /**
     * @param callable(): list<string> $secrets
     */
    public function __construct(private Store $store, private Clock $clock, callable $secrets)
    {
        $this->secrets = \Closure::fromCallable($secrets);
    }

    public function scrubber(): Scrubber
    {
        return new Scrubber(($this->secrets)());
    }

    public function scrub(string $message): string
    {
        return $this->scrubber()->scrub($message);
    }

    public function event(?int $jobId, int $postId, string $network, string $event, string $message = '', int $httpStatus = 0, int $durationMs = 0): void
    {
        $clean = $this->scrub($message);
        $this->store->addLog(new LogEntry($jobId, $network, $event, $httpStatus, $clean, $durationMs, $this->clock->now(), 0, $postId));
        if (in_array($event, ['failed', 'auth', 'uncertain', 'unknown', 'schema'], true) && defined('WP_DEBUG_LOG') && constant('WP_DEBUG_LOG')) {
            // Scrubbed above; nothing else is written.
            error_log(sprintf('[crosspost] %s %s job=%s: %s', $network, $event, $jobId === null ? '-' : (string) $jobId, $clean));
        }
    }
}
