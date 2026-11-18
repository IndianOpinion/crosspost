<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Tests\Support;

/**
 * A thin $wpdb over mysqli for the integration tests. Only the methods the
 * store and schema call. prepare() comes from the stub base class, with
 * real escaping.
 */
final class MysqliWpdb extends \wpdb
{
    public function __construct(private \mysqli $link, string $prefix)
    {
        $this->prefix = $prefix;
        $this->options = $prefix . 'options';
    }

    public static function fromEnv(): ?self
    {
        $host = getenv('CROSSPOST_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return null;
        }
        mysqli_report(MYSQLI_REPORT_OFF);
        $socket = getenv('CROSSPOST_TEST_DB_SOCKET');
        $link = mysqli_init();
        $ok = $link->real_connect(
            $host,
            (string) getenv('CROSSPOST_TEST_DB_USER'),
            (string) getenv('CROSSPOST_TEST_DB_PASSWORD'),
            (string) getenv('CROSSPOST_TEST_DB_NAME'),
            (int) (getenv('CROSSPOST_TEST_DB_PORT') ?: 3306),
            $socket === false || $socket === '' ? null : $socket,
        );
        if (!$ok) {
            return null;
        }
        $link->set_charset('utf8mb4');
        return new self($link, 'cpt_');
    }

    public function escape(string $value): string
    {
        return $this->link->real_escape_string($value);
    }

    public function query(string $query): int|bool
    {
        $this->queries[] = $query;
        $result = $this->link->query($query);
        if ($result === false) {
            return false;
        }
        if ($result instanceof \mysqli_result) {
            $result->free();
            return 0;
        }
        $this->rows_affected = (int) $this->link->affected_rows;
        $this->insert_id = (int) $this->link->insert_id;
        return preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)/i', $query) === 1 ? $this->rows_affected : true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetch(string $query): array
    {
        $this->queries[] = $query;
        $result = $this->link->query($query);
        if (!$result instanceof \mysqli_result) {
            return [];
        }
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
        return array_values($rows);
    }

    public function get_col(string $query = '', int $x = 0): array
    {
        return array_map(static fn (array $row): mixed => array_values($row)[$x] ?? null, $this->fetch($query));
    }

    public function get_var(string $query = ''): mixed
    {
        $rows = $this->fetch($query);
        return $rows === [] ? null : (array_values($rows[0])[0] ?? null);
    }

    public function get_row(string $query = '', string $output = 'OBJECT'): mixed
    {
        return $this->fetch($query)[0] ?? null;
    }

    public function get_results(string $query = '', string $output = 'OBJECT'): array
    {
        return $this->fetch($query);
    }

    public function dbDelta(string $query): void
    {
        // Enough of dbDelta for a first install: create when missing.
        $this->query((string) preg_replace('/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', $query));
    }
}
