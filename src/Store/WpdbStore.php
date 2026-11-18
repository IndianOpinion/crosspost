<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Store;

/**
 * The production store, on $wpdb. Every value goes through prepare(), and
 * every table and column name through its %i identifier placeholder
 * (WordPress 6.2 and later), so each SQL string is a literal.
 */
final class WpdbStore implements Store
{
    private string $jobs;
    private string $logTable;
    private string $accounts;

    public function __construct(private \wpdb $db)
    {
        $this->jobs = $db->prefix . 'crosspost_jobs';
        $this->logTable = $db->prefix . 'crosspost_log';
        $this->accounts = $db->prefix . 'crosspost_accounts';
    }

    public function insertJob(Job $job): ?int
    {
        $row = $job->toRow();
        $placeholders = [];
        foreach ($row as $value) {
            $placeholders[] = is_int($value) ? '%d' : '%s';
        }
        // INSERT IGNORE: the unique key on (post_id, network) refuses a
        // second job without raising a database error.
        $sql = 'INSERT IGNORE INTO %i (' . implode(',', array_fill(0, count($row), '%i')) . ') VALUES (' . implode(',', $placeholders) . ')';
        // query() returns the number of rows affected for INSERT.
        $result = $this->db->query($this->prepare($sql, array_merge([$this->jobs], array_keys($row), array_values($row))));
        if ($result !== 1) {
            return null;
        }
        $id = (int) $this->db->insert_id;
        $job->id = $id;
        return $id;
    }

    public function job(int $id): ?Job
    {
        $row = $this->db->get_row($this->prepare('SELECT * FROM %i WHERE id = %d', [$this->jobs, $id]), ARRAY_A);
        return is_array($row) ? Job::fromRow($row) : null;
    }

    public function jobFor(int $postId, string $network): ?Job
    {
        $row = $this->db->get_row($this->prepare('SELECT * FROM %i WHERE post_id = %d AND network = %s', [$this->jobs, $postId, $network]), ARRAY_A);
        return is_array($row) ? Job::fromRow($row) : null;
    }

    public function jobsForPost(int $postId): array
    {
        return $this->jobs(['post_id' => $postId], 10);
    }

    public function updateJob(Job $job, ?array $expectStates = null): bool
    {
        $row = $job->toRow();
        $sets = [];
        $values = [$this->jobs];
        foreach ($row as $column => $value) {
            $sets[] = is_int($value) ? '%i = %d' : '%i = %s';
            $values[] = $column;
            $values[] = $value;
        }
        $sql = 'UPDATE %i SET ' . implode(', ', $sets) . ' WHERE id = %d';
        $values[] = $job->id;
        if ($expectStates !== null && $expectStates !== []) {
            $sql .= ' AND state IN (' . implode(',', array_fill(0, count($expectStates), '%s')) . ')';
            foreach ($expectStates as $state) {
                $values[] = $state;
            }
        }
        $result = $this->db->query($this->prepare($sql, $values));
        if ($result === false) {
            return false;
        }
        // With a state condition, zero affected rows means the state moved on.
        return $expectStates === null || $result === 1;
    }

    public function jobs(array $filter = [], int $limit = 100, int $offset = 0): array
    {
        $where = ['1=1'];
        $values = [$this->jobs];
        if (isset($filter['state'])) {
            $states = (array) $filter['state'];
            $where[] = 'state IN (' . implode(',', array_fill(0, count($states), '%s')) . ')';
            foreach ($states as $state) {
                $values[] = $state;
            }
        }
        if (isset($filter['network'])) {
            $where[] = 'network = %s';
            $values[] = $filter['network'];
        }
        if (isset($filter['post_id'])) {
            $where[] = 'post_id = %d';
            $values[] = $filter['post_id'];
        }
        if (isset($filter['since'])) {
            $where[] = 'created_at >= %d';
            $values[] = $filter['since'];
        }
        $values[] = max(1, $limit);
        $values[] = max(0, $offset);
        $sql = 'SELECT * FROM %i WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d';
        return $this->rows($this->prepare($sql, $values));
    }

    public function dueJobs(string $network, int $now, int $limit): array
    {
        $sql = 'SELECT * FROM %i WHERE network = %s AND state IN (%s, %s) AND next_attempt_at <= %d ORDER BY created_at ASC, id ASC LIMIT %d';
        return $this->rows($this->prepare($sql, [$this->jobs, $network, Job::PENDING, Job::HELD, $now, max(1, $limit)]));
    }

    public function nextWake(): ?int
    {
        $value = $this->db->get_var($this->prepare(
            'SELECT MIN(next_attempt_at) FROM %i WHERE state IN (%s, %s, %s)',
            [$this->jobs, Job::PENDING, Job::HELD, Job::UNCERTAIN],
        ));
        return $value === null ? null : (int) $value;
    }

    public function countSent(string $network, int $since): int
    {
        return (int) $this->db->get_var($this->prepare(
            'SELECT COUNT(*) FROM %i WHERE network = %s AND sent_at >= %d AND state IN (%s, %s, %s, %s)',
            [$this->jobs, $network, $since, ...Job::COUNTED],
        ));
    }

    public function oldestSent(string $network, int $since): ?int
    {
        $value = $this->db->get_var($this->prepare(
            'SELECT MIN(sent_at) FROM %i WHERE network = %s AND sent_at >= %d AND state IN (%s, %s, %s, %s)',
            [$this->jobs, $network, $since, ...Job::COUNTED],
        ));
        return $value === null ? null : (int) $value;
    }

    public function lastSent(string $network): ?int
    {
        $value = $this->db->get_var($this->prepare(
            'SELECT MAX(sent_at) FROM %i WHERE network = %s AND sent_at > 0 AND state IN (%s, %s, %s, %s)',
            [$this->jobs, $network, ...Job::COUNTED],
        ));
        return $value === null ? null : (int) $value;
    }

    public function pruneJobs(int $before, int $batch): int
    {
        $result = $this->db->query($this->prepare(
            'DELETE FROM %i WHERE updated_at < %d AND state NOT IN (%s, %s, %s, %s) LIMIT %d',
            [$this->jobs, $before, Job::PENDING, Job::HELD, Job::SENDING, Job::UNCERTAIN, max(1, $batch)],
        ));
        return is_int($result) ? $result : 0;
    }

    public function addLog(LogEntry $entry): void
    {
        $values = [$this->logTable];
        if ($entry->jobId !== null) {
            $values[] = $entry->jobId;
        }
        array_push($values, $entry->postId, $entry->network, $entry->event, $entry->httpStatus, $entry->message, $entry->durationMs, $entry->createdAt);
        $this->db->query($this->prepare(
            'INSERT INTO %i (job_id, post_id, network, event, http_status, message, duration_ms, created_at) VALUES (' . ($entry->jobId === null ? 'NULL' : '%d') . ', %d, %s, %s, %d, %s, %d, %d)',
            $values,
        ));
    }

    public function log(array $filter = [], int $limit = 100): array
    {
        $where = ['1=1'];
        $values = [$this->logTable];
        foreach (['network' => 'network = %s', 'event' => 'event = %s', 'job_id' => 'job_id = %d', 'post_id' => 'post_id = %d'] as $column => $condition) {
            if (isset($filter[$column])) {
                $where[] = $condition;
                $values[] = $filter[$column];
            }
        }
        if (isset($filter['since'])) {
            $where[] = 'created_at >= %d';
            $values[] = $filter['since'];
        }
        $values[] = max(1, $limit);
        $rows = $this->db->get_results($this->prepare(
            'SELECT * FROM %i WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT %d',
            $values,
        ), ARRAY_A);
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row)) {
                $out[] = LogEntry::fromRow($row);
            }
        }
        return $out;
    }

    public function pruneLog(int $before, int $batch): int
    {
        $result = $this->db->query($this->prepare(
            'DELETE FROM %i WHERE created_at < %d ORDER BY id ASC LIMIT %d',
            [$this->logTable, $before, max(1, $batch)],
        ));
        return is_int($result) ? $result : 0;
    }

    public function account(string $network, string $accountId): ?AccountRecord
    {
        $row = $this->db->get_row($this->prepare(
            'SELECT * FROM %i WHERE network = %s AND account_id = %s',
            [$this->accounts, $network, $accountId],
        ), ARRAY_A);
        return is_array($row) ? AccountRecord::fromRow($row) : null;
    }

    public function saveAccount(AccountRecord $account): void
    {
        $row = $account->toRow();
        $columns = array_keys($row);
        $updates = [];
        $updateColumns = [];
        foreach ($columns as $column) {
            if ($column !== 'network' && $column !== 'account_id') {
                $updates[] = '%i = VALUES(%i)';
                $updateColumns[] = $column;
                $updateColumns[] = $column;
            }
        }
        $placeholders = [];
        foreach ($row as $value) {
            $placeholders[] = is_int($value) ? '%d' : '%s';
        }
        $this->db->query($this->prepare(
            'INSERT INTO %i (' . implode(',', array_fill(0, count($columns), '%i')) . ') VALUES (' . implode(',', $placeholders) . ') ON DUPLICATE KEY UPDATE ' . implode(', ', $updates),
            array_merge([$this->accounts], $columns, array_values($row), $updateColumns),
        ));
    }

    public function acquireLock(string $name, int $now, int $ttl): ?string
    {
        $token = bin2hex(random_bytes(8));
        $value = ($now + $ttl) . ':' . $token;
        $options = $this->db->options;
        // Atomic: only one INSERT IGNORE can create the row.
        $inserted = $this->db->query($this->prepare(
            "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            [$options, $name, $value],
        ));
        if ($inserted === 1) {
            return $token;
        }
        $current = $this->db->get_var($this->prepare('SELECT option_value FROM %i WHERE option_name = %s', [$options, $name]));
        $current = is_string($current) ? $current : '';
        $expiry = (int) strtok($current, ':');
        if ($current !== '' && $expiry > $now) {
            return null;
        }
        // Expired: replace it, but only if nobody else did first.
        $replaced = $this->db->query($this->prepare(
            'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s',
            [$options, $value, $name, $current],
        ));
        return $replaced === 1 ? $token : null;
    }

    public function releaseLock(string $name, string $token): void
    {
        $this->db->query($this->prepare(
            'DELETE FROM %i WHERE option_name = %s AND option_value LIKE %s',
            [$this->db->options, $name, '%:' . $this->db->esc_like($token)],
        ));
    }

    /**
     * One prepared statement. The query is a literal: identifiers travel
     * as %i arguments, never by concatenation.
     *
     * @param literal-string $query
     * @param array<int, mixed> $args
     */
    private function prepare(string $query, array $args): string
    {
        $sql = $this->db->prepare($query, $args);
        if (!is_string($sql) || $sql === '') {
            throw new \LogicException('A query could not be prepared.');
        }
        return $sql;
    }

    /**
     * @return list<Job>
     */
    private function rows(string $sql): array
    {
        $rows = $this->db->get_results($sql, ARRAY_A);
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row)) {
                $out[] = Job::fromRow($row);
            }
        }
        return $out;
    }
}
