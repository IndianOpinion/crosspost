<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Store;

/**
 * Creates and upgrades the tables, then checks the real columns.
 *
 * dbDelta has been seen to skip column additions without an error. After
 * it runs, the real columns are listed and any missing one is added with a
 * direct ALTER TABLE. If one is still missing, sending is refused.
 */
final class Schema
{
    public const VERSION = '1';
    public const VERSION_OPTION = 'crosspost_schema_version';
    public const MISSING_OPTION = 'crosspost_schema_missing';

    /**
     * Column definitions per table suffix, in table order.
     *
     * @var array<string, array<string, string>>
     */
    public const COLUMNS = [
        'crosspost_jobs' => [
            'id' => 'bigint(20) unsigned NOT NULL AUTO_INCREMENT',
            'post_id' => 'bigint(20) unsigned NOT NULL',
            'network' => 'varchar(20) NOT NULL',
            'account_id' => "varchar(191) NOT NULL DEFAULT ''",
            'state' => 'varchar(20) NOT NULL',
            'reason' => "varchar(191) NOT NULL DEFAULT ''",
            'text' => 'longtext NOT NULL',
            'text_hash' => "char(64) NOT NULL DEFAULT ''",
            'attachment_id' => 'bigint(20) unsigned NOT NULL DEFAULT 0',
            'template_hash' => "char(64) NOT NULL DEFAULT ''",
            'idempotency_key' => "varchar(64) NOT NULL DEFAULT ''",
            'remote_id' => "varchar(255) NOT NULL DEFAULT ''",
            'remote_url' => "varchar(255) NOT NULL DEFAULT ''",
            'attempts' => 'int(11) unsigned NOT NULL DEFAULT 0',
            'meta' => 'text NOT NULL',
            'released_by' => 'bigint(20) unsigned NOT NULL DEFAULT 0',
            'next_attempt_at' => 'bigint(20) NOT NULL DEFAULT 0',
            'sent_at' => 'bigint(20) NOT NULL DEFAULT 0',
            'first_sent_at' => 'bigint(20) NOT NULL DEFAULT 0',
            'created_at' => 'bigint(20) NOT NULL DEFAULT 0',
            'updated_at' => 'bigint(20) NOT NULL DEFAULT 0',
            'posted_at' => 'bigint(20) NOT NULL DEFAULT 0',
        ],
        'crosspost_log' => [
            'id' => 'bigint(20) unsigned NOT NULL AUTO_INCREMENT',
            'job_id' => 'bigint(20) unsigned NULL DEFAULT NULL',
            'post_id' => 'bigint(20) unsigned NOT NULL DEFAULT 0',
            'network' => "varchar(20) NOT NULL DEFAULT ''",
            'event' => 'varchar(20) NOT NULL',
            'http_status' => 'smallint(5) unsigned NOT NULL DEFAULT 0',
            'message' => "varchar(255) NOT NULL DEFAULT ''",
            'duration_ms' => 'int(11) unsigned NOT NULL DEFAULT 0',
            'created_at' => 'bigint(20) NOT NULL DEFAULT 0',
        ],
        'crosspost_accounts' => [
            'id' => 'bigint(20) unsigned NOT NULL AUTO_INCREMENT',
            'network' => 'varchar(20) NOT NULL',
            'account_id' => 'varchar(191) NOT NULL',
            'display_name' => "varchar(191) NOT NULL DEFAULT ''",
            'status' => "varchar(20) NOT NULL DEFAULT 'ok'",
            'reason' => "varchar(191) NOT NULL DEFAULT ''",
            'cooldown_until' => 'bigint(20) NOT NULL DEFAULT 0',
            'notified_at' => 'bigint(20) NOT NULL DEFAULT 0',
            'connected_at' => 'bigint(20) NOT NULL DEFAULT 0',
            'last_posted_at' => 'bigint(20) NOT NULL DEFAULT 0',
        ],
    ];

    /** @var array<string, list<string>> */
    public const KEYS = [
        'crosspost_jobs' => [
            'PRIMARY KEY  (id)',
            'UNIQUE KEY post_network (post_id,network)',
            'KEY due (network,state,next_attempt_at)',
            'KEY sent (network,sent_at)',
            'KEY posted (network,posted_at)',
            'KEY updated (updated_at)',
        ],
        'crosspost_log' => [
            'PRIMARY KEY  (id)',
            'KEY job (job_id)',
            'KEY network_time (network,created_at)',
            'KEY created (created_at)',
        ],
        'crosspost_accounts' => [
            'PRIMARY KEY  (id)',
            'UNIQUE KEY network_account (network,account_id)',
        ],
    ];

    public function __construct(private \wpdb $db)
    {
    }

    public function table(string $suffix): string
    {
        return $this->db->prefix . $suffix;
    }

    /**
     * Run dbDelta when the stored version differs, then verify columns.
     *
     * @return list<string> missing columns as "table.column"
     */
    public function maybeUpgrade(bool $force = false): array
    {
        if (!$force && get_option(self::VERSION_OPTION) === self::VERSION) {
            $missing = get_option(self::MISSING_OPTION, []);
            return is_array($missing) ? array_values(array_map('strval', $missing)) : [];
        }
        $this->install();
        $missing = $this->repair();
        update_option(self::MISSING_OPTION, $missing, false);
        if ($missing === []) {
            update_option(self::VERSION_OPTION, self::VERSION, false);
        }
        return $missing;
    }

    public function install(): void
    {
        if (!function_exists('dbDelta')) {
            $upgrade = ABSPATH . 'wp-admin/includes/upgrade.php';
            if (is_readable($upgrade)) {
                require_once $upgrade;
            }
        }
        if (!function_exists('dbDelta')) {
            return;
        }
        $charset = $this->db->get_charset_collate();
        foreach (self::COLUMNS as $suffix => $columns) {
            $lines = [];
            foreach ($columns as $name => $definition) {
                $lines[] = $name . ' ' . $definition;
            }
            foreach (self::KEYS[$suffix] as $key) {
                $lines[] = $key;
            }
            dbDelta('CREATE TABLE ' . $this->table($suffix) . " (\n" . implode(",\n", $lines) . "\n) " . $charset . ';');
        }
    }

    /**
     * List real columns; add missing ones directly; list again.
     *
     * @return list<string>
     */
    public function repair(): array
    {
        $missing = $this->missing();
        if ($missing === []) {
            return [];
        }
        foreach ($missing as $item) {
            [$suffix, $column] = explode('.', $item, 2);
            $definition = self::COLUMNS[$suffix][$column] ?? null;
            if ($definition === null || !$this->tableExists($suffix)) {
                continue;
            }
            // Identifiers come from the constant above, never from input.
            $this->db->query('ALTER TABLE `' . $this->table($suffix) . '` ADD COLUMN `' . $column . '` ' . str_replace(' AUTO_INCREMENT', '', $definition));
        }
        return $this->missing();
    }

    /**
     * @return list<string> "table.column" for every expected column the database lacks
     */
    public function missing(): array
    {
        $missing = [];
        foreach (self::COLUMNS as $suffix => $columns) {
            $real = $this->db->get_col('SHOW COLUMNS FROM `' . $this->table($suffix) . '`', 0);
            $real = is_array($real) ? array_map('strval', $real) : [];
            foreach (array_keys($columns) as $column) {
                if (!in_array($column, $real, true)) {
                    $missing[] = $suffix . '.' . $column;
                }
            }
        }
        return $missing;
    }

    private function tableExists(string $suffix): bool
    {
        $table = $this->table($suffix);
        $found = $this->db->get_var($this->db->prepare('SHOW TABLES LIKE %s', $this->db->esc_like($table)));
        return $found === $table;
    }

    public function drop(): void
    {
        foreach (array_keys(self::COLUMNS) as $suffix) {
            $this->db->query('DROP TABLE IF EXISTS `' . $this->table($suffix) . '`');
        }
    }
}
