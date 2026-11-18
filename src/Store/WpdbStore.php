<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Store;

/**
 * The production store, on $wpdb. Every value goes through prepare();
 * identifiers come from constants.
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
        $columns = array_keys($row);
        $placeholders = [];
        foreach ($row as $value) {
            $placeholders[] = is_int($value) ? '%d' : '%s';
        }
        // INSERT IGNORE: the unique key on (post_id, network) refuses a
        // second job without raising a database error.
        $sql = 'INSERT IGNORE INTO `' . $this->jobs . '` (`' . implode('`,`', $columns) . '`) VALUES (' . implode(',', $placeholders) . ')';
        // query() returns the number of rows affected for INSERT.
        $result = $this->db->query($this->db->prepare($sql, array_values($row)));
        if ($result !== 1) {
            return null;
        }
        $id = (int) $this->db->insert_id;
        $job->id = $id;
        return $id;
    }

    public function job(int $id): ?Job
    {
        $row = $this->db->get_row($this->db->prepare('SELECT * FROM `' . $this->jobs . '` WHERE id = %d', $id), ARRAY_A);
        return is_array($row) ? Job::fromRow($row) : null;
    }

    public function jobFor(int $postId, string $network): ?Job
    {
        $row = $this->db->get_row($this->db->prepare('SELECT * FROM `' . $this->jobs . '` WHERE post_id = %d AND network = %s', $postId, $network), ARRAY_A);
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
        $values = [];
        foreach ($row as $column => $value) {
            $sets[] = '`' . $column . '` = ' . (is_int($value) ? '%d' : '%s');
            $values[] = $value;
        }
        $sql = 'UPDATE `' . $this->jobs . '` SET ' . implode(', ', $sets) . ' WHERE id = %d';
        $values[] = $job->id;
        if ($expectStates !== null && $expectStates !== []) {
            $sql .= ' AND state IN (' . implode(',', array_fill(0, count($expectStates), '%s')) . ')';
            foreach ($expectStates as $state) {
                $values[] = $state;
            }
        }
        $result = $this->db->query($this->db->prepare($sql, $values));
        if ($result === false) {
            return false;
        }
        // With a state condition, zero affected rows means the state moved on.
        return $expectStates === null || $result === 1;
    }

    public function jobs(array $filter = [], int $limit = 100, int $offset = 0): array
    {
        $where = ['1=1'];
        $values = [];
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
        $sql = 'SELECT * FROM `' . $this->jobs . '` WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d';
        return $this->rows($this->db->prepare($sql, $values));
    }

    public function dueJobs(string $network, int $now, int $limit): array
    {
        $sql = 'SELECT * FROM `' . $this->jobs . '` WHERE network = %s AND state IN (%s, %s) AND next_attempt_at <= %d ORDER BY created_at ASC, id ASC LIMIT %d';
        return $this->rows($this->db->prepare($sql, $network, Job::PENDING, Job::HELD, $now, max(1, $limit)));
    }

    public function nextWake(): ?int
    {
        $value = $this->db->get_var($this->db->prepare(
            'SELECT MIN(next_attempt_at) FROM `' . $this->jobs . '` WHERE state IN (%s, %s, %s)',
            Job::PENDING,
            Job::HELD,
            Job::UNCERTAIN,
        ));
        return $value === null ? null : (int) $value;
    }

    public function countSent(string $network, int $since): int
    {
        return (int) $this->db->get_var($this->db->prepare(
            'SELECT COUNT(*) FROM `' . $this->jobs . '` WHERE network = %s AND sent_at >= %d AND state IN (%s, %s, %s, %s)',
            $network,
            $since,
            ...Job::COUNTED,
        ));
    }

    public function oldestSent(string $network, int $since): ?int
    {
        $value = $this->db->get_var($this->db->prepare(
            'SELECT MIN(sent_at) FROM `' . $this->jobs . '` WHERE network = %s AND sent_at >= %d AND state IN (%s, %s, %s, %s)',
            $network,
            $since,
            ...Job::COUNTED,
        ));
        return $value === null ? null : (int) $value;
    }

    public function lastSent(string $network): ?int
    {
        $value = $this->db->get_var($this->db->prepare(
            'SELECT MAX(sent_at) FROM `' . $this->jobs . '` WHERE network = %s AND sent_at > 0 AND state IN (%s, %s, %s, %s)',
            $network,
            ...Job::COUNTED,
        ));
        return $value === null ? null : (int) $value;
    }

    public function pruneJobs(int $before, int $batch): int
    {
        $result = $this->db->query($this->db->prepare(
            'DELETE FROM `' . $this->jobs . '` WHERE updated_at < %d AND state NOT IN (%s, %s, %s, %s) LIMIT %d',
            $before,
            Job::PENDING,
            Job::HELD,
            Job::SENDING,
            Job::UNCERTAIN,
            max(1, $batch),
        ));
        return is_int($result) ? $result : 0;
    }

    public function addLog(LogEntry $entry): void
    {
        $this->db->query($this->db->prepare(
            'INSERT INTO `' . $this->logTable . '` (job_id, post_id, network, event, http_status, message, duration_ms, created_at) VALUES (' . ($entry->jobId === null ? 'NULL' : '%d') . ', %d, %s, %s, %d, %s, %d, %d)',
            ...array_values(array_filter(
                [$entry->jobId, $entry->postId, $entry->network, $entry->event, $entry->httpStatus, $entry->message, $entry->durationMs, $entry->createdAt],
                static fn ($v): bool => $v !== null,
            )),
        ));
    }

    public function log(array $filter = [], int $limit = 100): array
    {
        $where = ['1=1'];
        $values = [];
        foreach (['network' => '%s', 'event' => '%s', 'job_id' => '%d', 'post_id' => '%d'] as $column => $format) {
            if (isset($filter[$column])) {
                $where[] = $column . ' = ' . $format;
                $values[] = $filter[$column];
            }
        }
        if (isset($filter['since'])) {
            $where[] = 'created_at >= %d';
            $values[] = $filter['since'];
        }
        $values[] = max(1, $limit);
        $rows = $this->db->get_results($this->db->prepare(
            'SELECT * FROM `' . $this->logTable . '` WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT %d',
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
        $result = $this->db->query($this->db->prepare(
            'DELETE FROM `' . $this->logTable . '` WHERE created_at < %d ORDER BY id ASC LIMIT %d',
            $before,
            max(1, $batch),
        ));
        return is_int($result) ? $result : 0;
    }

    public function account(string $network, string $accountId): ?AccountRecord
    {
        $row = $this->db->get_row($this->db->prepare(
            'SELECT * FROM `' . $this->accounts . '` WHERE network = %s AND account_id = %s',
            $network,
            $accountId,
        ), ARRAY_A);
        return is_array($row) ? AccountRecord::fromRow($row) : null;
    }

    public function saveAccount(AccountRecord $account): void
    {
        $row = $account->toRow();
        $columns = array_keys($row);
        $updates = [];
        foreach ($columns as $column) {
            if ($column !== 'network' && $column !== 'account_id') {
                $updates[] = '`' . $column . '` = VALUES(`' . $column . '`)';
            }
        }
        $placeholders = [];
        foreach ($row as $value) {
            $placeholders[] = is_int($value) ? '%d' : '%s';
        }
        $this->db->query($this->db->prepare(
            'INSERT INTO `' . $this->accounts . '` (`' . implode('`,`', $columns) . '`) VALUES (' . implode(',', $placeholders) . ') ON DUPLICATE KEY UPDATE ' . implode(', ', $updates),
            array_values($row),
        ));
    }

    public function acquireLock(string $name, int $now, int $ttl): ?string
    {
        $token = bin2hex(random_bytes(8));
        $value = ($now + $ttl) . ':' . $token;
        $options = $this->db->options;
        // Atomic: only one INSERT IGNORE can create the row.
        $inserted = $this->db->query($this->db->prepare(
            'INSERT IGNORE INTO `' . $options . "` (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $name,
            $value,
        ));
        if ($inserted === 1) {
            return $token;
        }
        $current = $this->db->get_var($this->db->prepare('SELECT option_value FROM `' . $options . '` WHERE option_name = %s', $name));
        $current = is_string($current) ? $current : '';
        $expiry = (int) strtok($current, ':');
        if ($current !== '' && $expiry > $now) {
            return null;
        }
        // Expired: replace it, but only if nobody else did first.
        $replaced = $this->db->query($this->db->prepare(
            'UPDATE `' . $options . '` SET option_value = %s WHERE option_name = %s AND option_value = %s',
            $value,
            $name,
            $current,
        ));
        return $replaced === 1 ? $token : null;
    }

    public function releaseLock(string $name, string $token): void
    {
        $this->db->query($this->db->prepare(
            'DELETE FROM `' . $this->db->options . '` WHERE option_name = %s AND option_value LIKE %s',
            $name,
            '%:' . $this->db->esc_like($token),
        ));
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
