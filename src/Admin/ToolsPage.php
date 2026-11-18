<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Admin;

use IndianOpinion\Crosspost\Network;
use IndianOpinion\Crosspost\Plugin;
use IndianOpinion\Crosspost\Store\Job;

/**
 * Tools > Crosspost: the queue and the log.
 */
final class ToolsPage
{
    public const SLUG = 'crosspost-log';

    public static function register(): void
    {
        add_action('admin_menu', static function (): void {
            add_management_page(
                __('Crosspost queue and log', 'crosspost'),
                __('Crosspost', 'crosspost'),
                'manage_options',
                self::SLUG,
                [self::class, 'render'],
            );
        });
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do this.', 'crosspost'), '', ['response' => 403]);
        }
        $app = Plugin::app();
        $network = isset($_GET['network']) ? sanitize_key(wp_unslash((string) $_GET['network'])) : '';
        $network = Network::isValid($network) ? $network : '';
        $state = isset($_GET['state']) ? sanitize_key(wp_unslash((string) $_GET['state'])) : '';
        $state = in_array($state, Job::STATES, true) ? $state : '';
        $postId = isset($_GET['post']) ? absint(wp_unslash((string) $_GET['post'])) : 0;

        echo '<div class="wrap"><h1>' . esc_html__('Crosspost queue and log', 'crosspost') . '</h1>';
        $flash = Actions::takeFlash();
        if ($flash !== null) {
            echo '<div class="notice notice-' . esc_attr($flash['type']) . '"><p>' . esc_html($flash['message']) . '</p></div>';
        }
        echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '">';
        echo '<select name="network"><option value="">' . esc_html__('All networks', 'crosspost') . '</option>';
        foreach (Network::ALL as $n) {
            echo '<option value="' . esc_attr($n) . '"' . selected($n, $network, false) . '>' . esc_html(Network::label($n)) . '</option>';
        }
        echo '</select> <select name="state"><option value="">' . esc_html__('All states', 'crosspost') . '</option>';
        foreach (Job::STATES as $s) {
            echo '<option value="' . esc_attr($s) . '"' . selected($s, $state, false) . '>' . esc_html($s) . '</option>';
        }
        echo '</select> <input type="number" name="post" placeholder="' . esc_attr__('Post id', 'crosspost') . '" value="' . esc_attr($postId > 0 ? (string) $postId : '') . '"> ';
        submit_button(__('Filter', 'crosspost'), 'secondary', '', false);
        echo '</form>';

        $filter = [];
        if ($network !== '') {
            $filter['network'] = $network;
        }
        if ($state !== '') {
            $filter['state'] = $state;
        }
        if ($postId > 0) {
            $filter['post_id'] = $postId;
        }
        echo '<h2>' . esc_html__('Jobs', 'crosspost') . '</h2><table class="widefat striped"><thead><tr>';
        foreach (['Id', 'Post', 'Network', 'State', 'Reason', 'Attempts', 'Created', 'Next', 'Remote', ''] as $heading) {
            echo '<th>' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($app->store->jobs($filter, 100) as $job) {
            echo '<tr><td>' . esc_html((string) $job->id) . '</td>'
                . '<td><a href="' . esc_url((string) get_edit_post_link($job->postId)) . '">' . esc_html((string) $job->postId) . '</a></td>'
                . '<td>' . esc_html(Network::label($job->network)) . '</td>'
                . '<td>' . esc_html($job->state) . '</td>'
                . '<td>' . esc_html($job->reason) . '</td>'
                . '<td>' . esc_html((string) $job->attempts) . '</td>'
                . '<td>' . esc_html(SettingsPage::time($job->createdAt)) . '</td>'
                . '<td>' . esc_html($job->isQueued() ? SettingsPage::time($job->nextAttemptAt) : '') . '</td>'
                . '<td>' . ($job->remoteUrl !== '' ? '<a href="' . esc_url($job->remoteUrl) . '" rel="noopener noreferrer" target="_blank">' . esc_html__('view', 'crosspost') . '</a>' : '') . '</td><td>';
            if ($job->state === Job::HELD) {
                SettingsPage::button('crosspost_job', __('Send', 'crosspost'), ['job' => (string) $job->id, 'op' => 'send'], 'primary');
            }
            if ($job->state === Job::UNKNOWN || ($job->state === Job::FAILED && $job->reason === Job::R_NEVER_LANDED)) {
                echo '<p class="description">' . esc_html__('Check the account first: this may already be live.', 'crosspost') . '</p>';
                SettingsPage::button('crosspost_job', __('Resend', 'crosspost'), ['job' => (string) $job->id, 'op' => 'send']);
            }
            if ($job->isQueued()) {
                SettingsPage::button('crosspost_job', __('Cancel', 'crosspost'), ['job' => (string) $job->id, 'op' => 'cancel']);
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';

        $logFilter = [];
        if ($network !== '') {
            $logFilter['network'] = $network;
        }
        if ($postId > 0) {
            $logFilter['post_id'] = $postId;
        }
        echo '<h2>' . esc_html__('Log', 'crosspost') . '</h2><table class="widefat striped"><thead><tr>';
        foreach (['Time', 'Network', 'Post', 'Job', 'Event', 'HTTP', 'ms', 'Message'] as $heading) {
            echo '<th>' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($app->store->log($logFilter, 200) as $entry) {
            echo '<tr><td>' . esc_html(SettingsPage::time($entry->createdAt)) . '</td>'
                . '<td>' . esc_html($entry->network === '' ? '' : Network::label($entry->network)) . '</td>'
                . '<td>' . esc_html($entry->postId > 0 ? (string) $entry->postId : '') . '</td>'
                . '<td>' . esc_html($entry->jobId === null ? '' : (string) $entry->jobId) . '</td>'
                . '<td>' . esc_html($entry->event) . '</td>'
                . '<td>' . esc_html($entry->httpStatus > 0 ? (string) $entry->httpStatus : '') . '</td>'
                . '<td>' . esc_html($entry->durationMs > 0 ? (string) $entry->durationMs : '') . '</td>'
                . '<td>' . esc_html($entry->message) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
