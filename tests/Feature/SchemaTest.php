<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Tests\Feature;

use IndianOpinion\Crosspost\Cli\Command;
use IndianOpinion\Crosspost\Network;
use IndianOpinion\Crosspost\Store\Job;
use IndianOpinion\Crosspost\Store\Schema;
use IndianOpinion\Crosspost\Tests\Stubs\State;
use IndianOpinion\Crosspost\Tests\TestCase;

/**
 * A database whose dbDelta silently skips a column, and whose ALTER TABLE
 * can be told to fail.
 */
final class SkippingWpdb extends \wpdb
{
    /** @var array<string, list<string>> */
    public array $columns = [];
    public bool $alterWorks = true;

    public function dbDelta(string $query): void
    {
        parent::dbDelta($query);
        if (preg_match('/CREATE TABLE (\S+) \((.*)\)/s', $query, $m) === 1) {
            preg_match_all('/^(\w+) /m', $m[2], $cols);
            $names = array_values(array_filter($cols[1], static fn (string $c): bool => !in_array($c, ['PRIMARY', 'UNIQUE', 'KEY'], true)));
            // The silent skip this plugin guards against.
            $this->columns[$m[1]] = array_values(array_diff($names, ['first_sent_at']));
        }
    }

    public function get_col(string $query = '', int $x = 0): array
    {
        parent::get_col($query);
        if (preg_match('/SHOW COLUMNS FROM `(\w+)`/', $query, $m) === 1) {
            return $this->columns[$m[1]] ?? [];
        }
        return [];
    }

    public function get_var(string $query = ''): mixed
    {
        parent::get_var($query);
        if (preg_match("/SHOW TABLES LIKE '(\w+)'/", str_replace('\\', '', $query), $m) === 1) {
            return isset($this->columns[$m[1]]) ? $m[1] : null;
        }
        return null;
    }

    public function query(string $query): int|bool
    {
        parent::query($query);
        if ($this->alterWorks && preg_match('/ALTER TABLE `(\w+)` ADD COLUMN `(\w+)`/', $query, $m) === 1) {
            $this->columns[$m[1]][] = $m[2];
        }
        return 1;
    }
}

/**
 * Section 6.4 and acceptance test 74; section 6.5 and acceptance test 73.
 */
final class SchemaTest extends TestCase
{
    public function testMissingColumnIsAddedDirectly(): void
    {
        global $wpdb;
        $wpdb = $db = new SkippingWpdb();
        $missing = (new Schema($db))->maybeUpgrade(true);
        $wpdb = null;
        self::assertSame([], $missing);
        self::assertContains('first_sent_at', $db->columns['wp_crosspost_jobs']);
        self::assertNotEmpty(array_filter($db->queries, static fn (string $q): bool => str_contains($q, 'ALTER TABLE `wp_crosspost_jobs` ADD COLUMN `first_sent_at`')));
        self::assertSame(Schema::VERSION, get_option(Schema::VERSION_OPTION));
    }

    public function testStillMissingRefusesToSendAndNamesTheColumn(): void
    {
        global $wpdb;
        $wpdb = $db = new SkippingWpdb();
        $db->alterWorks = false;
        $missing = (new Schema($db))->maybeUpgrade(true);
        $wpdb = null;
        self::assertSame(['crosspost_jobs.first_sent_at'], $missing);
        self::assertFalse(get_option(Schema::VERSION_OPTION), 'Upgrade retried on the next load.');

        $this->missing = $missing;
        $this->app->resetSchemaCache();
        $this->connect(Network::MASTODON);
        $post = $this->post();
        $this->publish($post);
        $this->clock->advance(61);
        $report = $this->app->run();
        self::assertSame(Job::R_SCHEMA, $report->stopReason);
        self::assertSame([], State::$requests);
        self::assertStringContainsString('crosspost_jobs.first_sent_at', $this->app->notifier->notices()['schema']['message']);

        \WP_CLI::$out = [];
        (new Command($this->app))->status([], []);
        self::assertStringContainsString('crosspost_jobs.first_sent_at', implode("\n", \WP_CLI::$out));
    }

    public function testUninstallRemovesLocalDataOnlyAndMakesNoRequest(): void
    {
        global $wpdb;
        $wpdb = new \wpdb();
        $this->connect(Network::MASTODON);
        $post = $this->post();
        update_post_meta($post->ID, '_crosspost_opt_out', '1');
        update_post_meta($post->ID, '_crosspost_image', 5);
        update_post_meta($post->ID, 'unrelated', 'keep');
        $this->app->syncEvents();
        self::assertNotEmpty(State::$events);
        update_option('unrelated_option', 'keep');

        \IndianOpinion\Crosspost\Uninstaller::site();

        self::assertSame([], State::$requests);
        self::assertSame([], State::$events);
        foreach (array_keys(State::$options) as $name) {
            self::assertStringStartsNotWith('crosspost_', $name);
        }
        self::assertSame('keep', get_option('unrelated_option'));
        self::assertSame(['unrelated' => 'keep'], State::$meta[$post->ID]);
        self::assertFalse(get_transient('crosspost_mastodon_instance'));
        foreach (['wp_crosspost_jobs', 'wp_crosspost_log', 'wp_crosspost_accounts'] as $table) {
            self::assertContains('DROP TABLE IF EXISTS `' . $table . '`', $wpdb->queries);
        }
        $wpdb = null;
    }

    public function testDeactivationOnlyClearsEvents(): void
    {
        $this->connect(Network::MASTODON);
        $this->app->syncEvents();
        $this->publish($this->post());
        $options = State::$options;
        \IndianOpinion\Crosspost\Plugin::deactivate();
        self::assertSame([], State::$events);
        self::assertSame($options, State::$options);
        self::assertNotSame([], $this->store->jobs);
    }
}
