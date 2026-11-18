<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Admin;

use IndianOpinion\Crosspost\Network;
use IndianOpinion\Crosspost\Plugin;

/**
 * Admin notices: networks needing attention, a late WP-Cron, missing
 * columns. Shown to administrators only.
 */
final class Notices
{
    public const CRON_LATE = 1800;

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $app = Plugin::app();
        $settings = $app->settings();
        if (!$settings->anyEnabled() && $app->notifier->notices() === []) {
            return;
        }
        foreach ($app->notifier->notices() as $key => $notice) {
            self::box($notice['message'], 'warning', $key);
        }
        if ($settings->anyEnabled() && !$settings->killSwitch()) {
            $now = $app->clock->now();
            foreach (Network::ALL as $network) {
                $due = $app->store->dueJobs($network, $now - self::CRON_LATE, 1);
                if ($due !== [] && $due[0]->state === 'pending') {
                    self::box(__('Crosspost: jobs are more than 30 minutes late. WP-Cron may not be running; see the readme for a system cron line.', 'crosspost'), 'warning');
                    break;
                }
            }
        }
    }

    private static function box(string $message, string $type, string $key = ''): void
    {
        echo '<div class="notice notice-' . esc_attr($type) . '"><p>' . esc_html($message) . '</p>';
        if ($key !== '') {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
                . Actions::fields('crosspost_dismiss') // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
                . '<input type="hidden" name="key" value="' . esc_attr($key) . '">'
                . '<p><button class="button-link" type="submit">' . esc_html__('Dismiss', 'crosspost') . '</button></p></form>';
        }
        echo '</div>';
    }
}
