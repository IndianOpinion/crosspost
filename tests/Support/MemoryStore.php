<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Tests\Support;

use IndianOpinion\Crosspost\Store\AccountRecord;
use IndianOpinion\Crosspost\Store\Job;
use IndianOpinion\Crosspost\Store\LogEntry;
use IndianOpinion\Crosspost\Store\Store;

/**
 * In-memory store with the same contract as the database one, including
 * the unique (post, network) key and compare-and-swap updates.
 */
final class MemoryStore implements Store
{
    /** @var array<int, array<string, string|int>> */
    public array $jobs = [];
    /** @var list<LogEntry> */
    public array $log = [];
    /** @var array<string, AccountRecord> */
    public array $accounts = [];
    /** @var array<string, string> */
    public array $locks = [];
    private int $nextId = 1;

    public function insertJob(Job $job): ?int
    {
        foreach ($this->jobs as $row) {
            if ($row['post_id'] === $job->postId && $row['network'] === $job->network) {
                return null;
            }
        }
        $id = $this->nextId++;
        $job->id = $id;
        $this->jobs[$id] = ['id' => $id] + $job->toRow();
        return $id;
    }

    public function job(int $id): ?Job
    {
        return isset($this->jobs[$id]) ? Job::fromRow($this->jobs[$id]) : null;
    }

    public function jobFor(int $postId, string $network): ?Job
    {
        foreach ($this->jobs as $row) {
            if ($row['post_id'] === $postId && $row['network'] === $network) {
                return Job::fromRow($row);
            }
        }
        return null;
    }

    public function jobsForPost(int $postId): array
    {
        return $this->jobs(['post_id' => $postId], 10);
    }

    public function updateJob(Job $job, ?array $expectStates = null): bool
    {
        if (!isset($this->jobs[$job->id])) {
            return false;
        }
        if ($expectStates !== null && !in_array($this->jobs[$job->id]['state'], $expectStates, true)) {
            return false;
        }
        $this->jobs[$job->id] = ['id' => $job->id] + $job->toRow();
        return true;
    }

    public function jobs(array $filter = [], int $limit = 100, int $offset = 0): array
    {
        $out = [];
        foreach (array_reverse($this->jobs, true) as $row) {
            if (isset($filter['state']) && !in_array($row['state'], (array) $filter['state'], true)) {
                continue;
            }
            if (isset($filter['network']) && $row['network'] !== $filter['network']) {
                continue;
            }
            if (isset($filter['post_id']) && $row['post_id'] !== $filter['post_id']) {
                continue;
            }
            if (isset($filter['since']) && $row['created_at'] < $filter['since']) {
                continue;
            }
            $out[] = Job::fromRow($row);
        }
        usort($out, static fn (Job $a, Job $b): int => [$b->createdAt, $b->id] <=> [$a->createdAt, $a->id]);
        return array_slice($out, $offset, $limit);
    }

    public function dueJobs(string $network, int $now, int $limit): array
    {
        $out = [];
        foreach ($this->jobs as $row) {
            if ($row['network'] === $network && in_array($row['state'], Job::QUEUED, true) && $row['next_attempt_at'] <= $now) {
                $out[] = Job::fromRow($row);
            }
        }
        usort($out, static fn (Job $a, Job $b): int => [$a->createdAt, $a->id] <=> [$b->createdAt, $b->id]);
        return array_slice($out, 0, $limit);
    }

    public function nextWake(): ?int
    {
        $min = null;
        foreach ($this->jobs as $row) {
            if (in_array($row['state'], [Job::PENDING, Job::HELD, Job::UNCERTAIN], true)) {
                $min = $min === null ? (int) $row['next_attempt_at'] : min($min, (int) $row['next_attempt_at']);
            }
        }
        return $min;
    }

    /**
     * @return list<array<string, string|int>>
     */
    private function counted(string $network, int $since): array
    {
        return array_values(array_filter($this->jobs, static fn (array $r): bool => $r['network'] === $network && $r['sent_at'] >= $since && $r['sent_at'] > 0 && in_array($r['state'], Job::COUNTED, true)));
    }

    public function countSent(string $network, int $since): int
    {
        return count($this->counted($network, $since));
    }

    public function oldestSent(string $network, int $since): ?int
    {
        $times = array_map(static fn (array $r): int => (int) $r['sent_at'], $this->counted($network, $since));
        return $times === [] ? null : min($times);
    }

    public function lastSent(string $network): ?int
    {
        $times = array_map(static fn (array $r): int => (int) $r['sent_at'], $this->counted($network, 1));
        return $times === [] ? null : max($times);
    }

    public function pruneJobs(int $before, int $batch): int
    {
        $n = 0;
        foreach ($this->jobs as $id => $row) {
            if ($n < $batch && $row['updated_at'] < $before && !in_array($row['state'], [Job::PENDING, Job::HELD, Job::SENDING, Job::UNCERTAIN], true)) {
                unset($this->jobs[$id]);
                $n++;
            }
        }
        return $n;
    }

    public function addLog(LogEntry $entry): void
    {
        $this->log[] = new LogEntry($entry->jobId, $entry->network, $entry->event, $entry->httpStatus, $entry->message, $entry->durationMs, $entry->createdAt, count($this->log) + 1, $entry->postId);
    }

    public function log(array $filter = [], int $limit = 100): array
    {
        $out = [];
        foreach (array_reverse($this->log) as $entry) {
            if (isset($filter['network']) && $entry->network !== $filter['network']) {
                continue;
            }
            if (isset($filter['event']) && $entry->event !== $filter['event']) {
                continue;
            }
            if (isset($filter['job_id']) && $entry->jobId !== $filter['job_id']) {
                continue;
            }
            if (isset($filter['post_id']) && $entry->postId !== $filter['post_id']) {
                continue;
            }
            if (isset($filter['since']) && $entry->createdAt < $filter['since']) {
                continue;
            }
            $out[] = $entry;
        }
        return array_slice($out, 0, $limit);
    }

    public function pruneLog(int $before, int $batch): int
    {
        $n = 0;
        $this->log = array_values(array_filter($this->log, static function (LogEntry $e) use ($before, $batch, &$n): bool {
            if ($n < $batch && $e->createdAt < $before) {
                $n++;
                return false;
            }
            return true;
        }));
        return $n;
    }

    public function account(string $network, string $accountId): ?AccountRecord
    {
        $a = $this->accounts[$network . '|' . $accountId] ?? null;
        return $a === null ? null : clone $a;
    }

    public function saveAccount(AccountRecord $account): void
    {
        $this->accounts[$account->network . '|' . $account->accountId] = clone $account;
    }

    public function acquireLock(string $name, int $now, int $ttl): ?string
    {
        $current = $this->locks[$name] ?? null;
        if ($current !== null && (int) strtok($current, ':') > $now) {
            return null;
        }
        $token = bin2hex(random_bytes(4));
        $this->locks[$name] = ($now + $ttl) . ':' . $token;
        return $token;
    }

    public function releaseLock(string $name, string $token): void
    {
        if (isset($this->locks[$name]) && str_ends_with($this->locks[$name], ':' . $token)) {
            unset($this->locks[$name]);
        }
    }

    /**
     * @return list<string>
     */
    public function events(?int $jobId = null): array
    {
        $out = [];
        foreach ($this->log as $entry) {
            if ($jobId === null || $entry->jobId === $jobId) {
                $out[] = $entry->event;
            }
        }
        return $out;
    }
}
