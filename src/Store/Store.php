<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Store;

/**
 * Persistence for jobs, the log, accounts and the run lock.
 */
interface Store
{
    /**
     * Insert a job. Returns the new id, or null when a job already exists
     * for the same (post, network): the database enforces it.
     */
    public function insertJob(Job $job): ?int;

    public function job(int $id): ?Job;

    public function jobFor(int $postId, string $network): ?Job;

    /** @return list<Job> */
    public function jobsForPost(int $postId): array;

    /**
     * Write every field of the job. When $expectStates is given, the write
     * happens only if the stored state is one of them (compare and swap).
     *
     * @param list<string>|null $expectStates
     */
    public function updateJob(Job $job, ?array $expectStates = null): bool;

    /**
     * @param array{state?: string|list<string>, network?: string, post_id?: int, since?: int} $filter
     * @return list<Job>
     */
    public function jobs(array $filter = [], int $limit = 100, int $offset = 0): array;

    /**
     * Queued jobs (pending or held) whose next attempt time has passed,
     * oldest first.
     *
     * @return list<Job>
     */
    public function dueJobs(string $network, int $now, int $limit): array;

    /** Earliest next attempt time over queued and uncertain jobs, or null. */
    public function nextWake(): ?int;

    /** Jobs in a counted state with sent_at at or after $since. */
    public function countSent(string $network, int $since): int;

    /** Earliest sent_at among counted jobs since $since, or null. */
    public function oldestSent(string $network, int $since): ?int;

    /** Latest sent_at among counted jobs, or null. */
    public function lastSent(string $network): ?int;

    /** Prune jobs last changed before $before, at most $batch rows. */
    public function pruneJobs(int $before, int $batch): int;

    public function addLog(LogEntry $entry): void;

    /**
     * @param array{network?: string, job_id?: int, post_id?: int, since?: int, event?: string} $filter
     * @return list<LogEntry>
     */
    public function log(array $filter = [], int $limit = 100): array;

    public function pruneLog(int $before, int $batch): int;

    public function account(string $network, string $accountId): ?AccountRecord;

    public function saveAccount(AccountRecord $account): void;

    /**
     * Take the named lock with an atomic insert. An expired lock is
     * replaced. Returns a token, or null when the lock is held.
     */
    public function acquireLock(string $name, int $now, int $ttl): ?string;

    public function releaseLock(string $name, string $token): void;
}
