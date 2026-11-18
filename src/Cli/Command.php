<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Cli;

use IndianOpinion\Crosspost\Crosspost;
use IndianOpinion\Crosspost\Exception\CrosspostException;
use IndianOpinion\Crosspost\Network;
use IndianOpinion\Crosspost\Plugin;
use IndianOpinion\Crosspost\Store\Job;

/**
 * Announce new posts on X, Threads, Bluesky and Mastodon.
 *
 * Credentials are only ever reported as present or absent.
 */
final class Command
{
    private ?Crosspost $app;

    public function __construct(?Crosspost $app = null)
    {
        $this->app = $app;
    }

    private function app(): Crosspost
    {
        return $this->app ??= Plugin::app();
    }

    /**
     * Show the kill switch and each network's health.
     *
     * ## EXAMPLES
     *
     *     wp crosspost status
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function status(array $args, array $assoc): void
    {
        $app = $this->app();
        $settings = $app->settings();
        \WP_CLI::line(sprintf('Kill switch: %s%s', $settings->killSwitch() ? 'on' : 'off', \IndianOpinion\Crosspost\Settings::killSwitchFromConstant() ? ' (set by constant)' : ''));
        $missing = $app->missingColumns();
        if ($missing !== []) {
            \WP_CLI::warning('Sending refused. Missing columns: ' . implode(', ', $missing));
        }
        $rows = [];
        foreach ($app->networkStatus() as $network => $row) {
            $rows[] = [
                'network' => $network,
                'on' => $row['enabled'] ? 'yes' : 'no',
                'credentials' => $row['credentials'] ? 'yes' : 'no',
                'verified' => $row['verified'] ? 'yes' : 'no',
                'account' => $row['account'],
                'cap' => $row['used'] . '/' . $row['cap'],
                'remaining' => $row['remaining'],
                'next_release' => self::time((int) $row['next_release']),
                'cooldown' => self::time((int) $row['cooldown_until']),
                'oldest_due' => $row['oldest_due_age'] > 0 ? $row['oldest_due_age'] . 's' : '',
                'first_post_review' => $row['first_post_review'] ? 'pending' : 'done',
                'attention' => $row['attention'],
                'last_error' => $row['last_error'],
            ];
        }
        \WP_CLI\Utils\format_items('table', $rows, array_keys($rows[0]));
    }

    /**
     * Process the queue once. For a system cron.
     *
     * ## OPTIONS
     *
     * [--network=<network>]
     * : Only this network.
     *
     * [--limit=<n>]
     * : At most this many sends.
     *
     * ## EXAMPLES
     *
     *     wp crosspost run
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function run(array $args, array $assoc): void
    {
        $network = isset($assoc['network']) ? $this->network($assoc['network']) : null;
        $limit = isset($assoc['limit']) ? max(1, (int) $assoc['limit']) : null;
        $report = $this->app()->run($network, $limit);
        if ($report->locked) {
            \WP_CLI::line('Another run holds the lock; nothing done.');
            return;
        }
        \WP_CLI::line(sprintf(
            'Sent %d, posted %d, reconciled %d, held %d, failed %d, skipped %d.%s',
            $report->sent,
            $report->posted,
            $report->reconciled,
            $report->held,
            $report->failed,
            $report->skipped,
            $report->stopped ? ' Stopped: ' . $report->stopReason . '.' : '',
        ));
    }

    /**
     * Print what would be sent for a post. Needs no credentials, writes
     * nothing, makes no network call.
     *
     * ## OPTIONS
     *
     * <post-id>
     * : The post.
     *
     * [--network=<network>]
     * : One network; default all four.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function preview(array $args, array $assoc): void
    {
        $postId = (int) ($args[0] ?? 0);
        $networks = isset($assoc['network']) ? [$this->network($assoc['network'])] : Network::ALL;
        foreach ($networks as $network) {
            try {
                $p = $this->app()->preview($postId, $network);
            } catch (CrosspostException $e) {
                \WP_CLI::error($e->getMessage());
                return;
            }
            \WP_CLI::line('== ' . Network::label($network) . ' ==');
            \WP_CLI::line((string) $p['text']);
            \WP_CLI::line(sprintf('Length: %d of %d', (int) $p['count'], (int) $p['limit']));
            if ((string) $p['error'] !== '') {
                \WP_CLI::line('Error: ' . $p['error']);
            }
            foreach ((array) $p['warnings'] as $warning) {
                \WP_CLI::line('Warning: ' . $warning);
            }
            \WP_CLI::line('Image: ' . ((int) $p['image'] > 0 ? (int) $p['image'] . ' alt: ' . $p['alt'] : 'none') . ((string) $p['image_note'] !== '' ? ' (' . $p['image_note'] . ')' : ''));
            \WP_CLI::line('');
        }
    }

    /**
     * Read-only credential check.
     *
     * ## OPTIONS
     *
     * <network>
     * : x, threads, bluesky or mastodon.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function verify(array $args, array $assoc): void
    {
        $network = $this->network($args[0] ?? '');
        try {
            $account = $this->app()->verify($network);
        } catch (CrosspostException $e) {
            \WP_CLI::error($this->app()->logger->scrub($e->getMessage()));
            return;
        }
        \WP_CLI::success(sprintf('%s: %s (account id %s)', Network::label($network), $account->name, $account->id));
    }

    /**
     * List jobs.
     *
     * ## OPTIONS
     *
     * [--state=<state>]
     * : Only this state.
     *
     * [--network=<network>]
     * : Only this network.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function queue(array $args, array $assoc): void
    {
        $filter = [];
        if (isset($assoc['state'])) {
            if (!in_array($assoc['state'], Job::STATES, true)) {
                \WP_CLI::error('Unknown state. One of: ' . implode(', ', Job::STATES));
                return;
            }
            $filter['state'] = $assoc['state'];
        }
        if (isset($assoc['network'])) {
            $filter['network'] = $this->network($assoc['network']);
        }
        $rows = [];
        foreach ($this->app()->store->jobs($filter, 200) as $job) {
            $rows[] = [
                'id' => $job->id,
                'post' => $job->postId,
                'network' => $job->network,
                'state' => $job->state,
                'reason' => $job->reason,
                'attempts' => $job->attempts,
                'created' => self::time($job->createdAt),
                'next' => $job->isQueued() ? self::time($job->nextAttemptAt) : '',
                'remote' => $job->remoteUrl,
            ];
        }
        if ($rows === []) {
            \WP_CLI::line('No jobs.');
            return;
        }
        \WP_CLI\Utils\format_items('table', $rows, array_keys($rows[0]));
    }

    /**
     * Release a held job (first post review), or resend one a person has
     * checked ("never landed" or "unknown").
     *
     * ## OPTIONS
     *
     * <job-id>
     * : The job.
     *
     * [--yes]
     * : Do not ask for confirmation.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function send(array $args, array $assoc): void
    {
        $id = (int) ($args[0] ?? 0);
        $job = $this->app()->store->job($id);
        if ($job === null) {
            \WP_CLI::error('No such job.');
            return;
        }
        \WP_CLI::line($job->text);
        if ($job->state !== Job::HELD) {
            \WP_CLI::warning('This job may already be live. Check the account before resending.');
        }
        \WP_CLI::confirm(sprintf('Send job %d to %s?', $id, Network::label($job->network)), $assoc);
        try {
            $this->app()->send($id, get_current_user_id());
        } catch (CrosspostException $e) {
            \WP_CLI::error($e->getMessage());
            return;
        }
        \WP_CLI::success('Released. It goes out on the next run, subject to the cap.');
    }

    /**
     * Cancel a job that has not been sent.
     *
     * ## OPTIONS
     *
     * <job-id>
     * : The job.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function cancel(array $args, array $assoc): void
    {
        try {
            $this->app()->cancel((int) ($args[0] ?? 0), get_current_user_id());
        } catch (CrosspostException $e) {
            \WP_CLI::error($e->getMessage());
            return;
        }
        \WP_CLI::success('Cancelled.');
    }

    /**
     * Turn the kill switch on or off.
     *
     * ## OPTIONS
     *
     * <state>
     * : on or off.
     * ---
     * options:
     *   - on
     *   - off
     * ---
     *
     * @subcommand kill-switch
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function killSwitch(array $args, array $assoc): void
    {
        $state = $args[0] ?? '';
        if (!in_array($state, ['on', 'off'], true)) {
            \WP_CLI::error('Use "on" or "off".');
            return;
        }
        if (\IndianOpinion\Crosspost\Settings::killSwitchFromConstant()) {
            \WP_CLI::error('The kill switch is set by the CROSSPOST_KILL_SWITCH constant; change wp-config.php.');
            return;
        }
        $this->app()->setKillSwitch($state === 'on');
        \WP_CLI::success('Kill switch ' . $state . '.');
    }

    /**
     * Create jobs for published posts that never had one on a network.
     * Prints what would be queued unless --apply is given.
     *
     * ## OPTIONS
     *
     * --since=<date>
     * : Posts published on or after this date (any format strtotime reads).
     *
     * --network=<network>
     * : The network.
     *
     * [--limit=<n>]
     * : At most this many posts. Default 10.
     *
     * [--dry-run]
     * : Print only (the default).
     *
     * [--apply]
     * : Create the jobs.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function backfill(array $args, array $assoc): void
    {
        $since = strtotime($assoc['since'] ?? '');
        if ($since === false) {
            \WP_CLI::error('--since must be a date.');
            return;
        }
        $network = $this->network($assoc['network'] ?? '');
        $limit = max(1, (int) ($assoc['limit'] ?? 10));
        $apply = isset($assoc['apply']) && !isset($assoc['dry-run']);
        $rows = $this->app()->backfill($since, $network, $limit, $apply);
        if ($rows === []) {
            \WP_CLI::line('Nothing to backfill.');
            return;
        }
        \WP_CLI\Utils\format_items('table', $rows, ['post_id', 'title', 'state', 'reason']);
        if (!$apply) {
            \WP_CLI::line('Dry run. Add --apply to create these jobs; caps and spacing still apply.');
        }
    }

    /**
     * Show the log.
     *
     * ## OPTIONS
     *
     * [--network=<network>]
     * : Only this network.
     *
     * [--post=<id>]
     * : Only this post.
     *
     * [--since=<date>]
     * : Only entries since this date.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function log(array $args, array $assoc): void
    {
        $filter = [];
        if (isset($assoc['network'])) {
            $filter['network'] = $this->network($assoc['network']);
        }
        if (isset($assoc['post'])) {
            $filter['post_id'] = (int) $assoc['post'];
        }
        if (isset($assoc['since'])) {
            $since = strtotime($assoc['since']);
            if ($since === false) {
                \WP_CLI::error('--since must be a date.');
                return;
            }
            $filter['since'] = $since;
        }
        $rows = [];
        foreach ($this->app()->store->log($filter, 200) as $entry) {
            $rows[] = [
                'time' => self::time($entry->createdAt),
                'network' => $entry->network,
                'post' => $entry->postId > 0 ? $entry->postId : '',
                'job' => $entry->jobId ?? '',
                'event' => $entry->event,
                'http' => $entry->httpStatus > 0 ? $entry->httpStatus : '',
                'ms' => $entry->durationMs > 0 ? $entry->durationMs : '',
                'message' => $entry->message,
            ];
        }
        if ($rows === []) {
            \WP_CLI::line('No log entries.');
            return;
        }
        \WP_CLI\Utils\format_items('table', $rows, array_keys($rows[0]));
    }

    private function network(string $network): string
    {
        if (!Network::isValid($network)) {
            \WP_CLI::error('Network must be one of: ' . implode(', ', Network::ALL));
        }
        return $network;
    }

    private static function time(int $timestamp): string
    {
        return $timestamp > 0 ? gmdate('Y-m-d H:i:s', $timestamp) : '';
    }
}
