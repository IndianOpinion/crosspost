<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Admin;

use IndianOpinion\Crosspost\Crosspost;
use IndianOpinion\Crosspost\Media\ImageSelector;
use IndianOpinion\Crosspost\Network;
use IndianOpinion\Crosspost\Plugin;
use IndianOpinion\Crosspost\Store\Job;

/**
 * The per-post panel: a block editor sidebar panel (plain script, no build
 * step) and a classic meta box. Both show the same preview the queue
 * would send.
 */
final class PostPanel
{
    public const NONCE = 'crosspost_post_panel';

    public static function register(string $pluginFile): void
    {
        add_action('add_meta_boxes', static function (string $postType): void {
            if (!in_array($postType, Plugin::app()->settings()->postTypes(), true)) {
                return;
            }
            add_meta_box('crosspost', __('Crosspost', 'crosspost'), [self::class, 'metaBox'], $postType, 'side', 'default', ['__back_compat_meta_box' => true]);
        });
        add_action('save_post', [self::class, 'save'], 10, 2);
        add_action('enqueue_block_editor_assets', static function () use ($pluginFile): void {
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            if ($screen === null || !in_array($screen->post_type, Plugin::app()->settings()->postTypes(), true)) {
                return;
            }
            wp_enqueue_script(
                'crosspost-editor',
                plugins_url('assets/editor.js', $pluginFile),
                ['wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch', 'wp-i18n'],
                defined('CROSSPOST_VERSION') ? (string) constant('CROSSPOST_VERSION') : '0',
                true,
            );
        });
    }

    public static function metaBox(\WP_Post $post): void
    {
        if (!current_user_can('edit_post', $post->ID)) {
            return;
        }
        $app = Plugin::app();
        wp_nonce_field(self::NONCE, 'crosspost_panel_nonce');
        $optOut = Crosspost::optedOut($post->ID);
        echo '<p><label><input type="checkbox" name="crosspost_opt_out" value="1"' . checked($optOut, true, false) . '> ' . esc_html__('Do not crosspost', 'crosspost') . '</label></p>';
        $override = (int) get_post_meta($post->ID, ImageSelector::OVERRIDE_META, true);
        echo '<p><label>' . esc_html__('Image override (attachment id)', 'crosspost') . ' <input type="number" min="0" name="crosspost_image" value="' . esc_attr($override > 0 ? (string) $override : '') . '" style="width:7em"></label></p>';

        $status = $app->postStatus($post->ID);
        $settings = $app->settings();
        foreach (Network::ALL as $network) {
            if (!$settings->enabled($network) && !isset($status[$network])) {
                continue;
            }
            echo '<hr><p><strong>' . esc_html(Network::label($network)) . '</strong></p>';
            if (isset($status[$network])) {
                $row = $status[$network];
                echo '<p>' . esc_html((string) $row['state']) . ((string) $row['reason'] !== '' ? ': ' . esc_html((string) $row['reason']) : '') . '</p>';
                if ($row['state'] === Job::POSTED) {
                    if ((string) $row['remote_url'] !== '') {
                        echo '<p><a href="' . esc_url((string) $row['remote_url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('View the post', 'crosspost') . '</a></p>';
                    }
                    echo '<p class="description">' . esc_html(sprintf(__('Already posted on %s; this plugin never removes remote posts.', 'crosspost'), Network::label($network))) . '</p>';
                    continue;
                }
            }
            if (!$settings->enabled($network)) {
                continue;
            }
            $preview = $app->preview($post->ID, $network);
            echo '<pre style="white-space:pre-wrap;font-size:12px">' . esc_html((string) $preview['text']) . '</pre>';
            echo '<p>' . esc_html(sprintf(__('%1$d of %2$d', 'crosspost'), (int) $preview['count'], (int) $preview['limit'])) . '</p>';
            if ((string) $preview['error'] !== '') {
                echo '<p><strong>' . esc_html((string) $preview['error']) . '</strong></p>';
            }
            foreach ((array) $preview['warnings'] as $warning) {
                echo '<p>' . esc_html((string) $warning) . '</p>';
            }
            echo '<p>' . esc_html((int) $preview['image'] > 0 ? sprintf(__('Image %1$d, alt: %2$s', 'crosspost'), (int) $preview['image'], (string) $preview['alt']) : __('No image', 'crosspost')) . ' ' . esc_html((string) $preview['image_note']) . '</p>';
            if ($post->post_status === 'publish' && current_user_can('publish_posts') && self::canPostNow($status[$network] ?? null)) {
                $url = wp_nonce_url(admin_url('admin-post.php?action=crosspost_post_now&network=' . $network . '&post=' . $post->ID), 'crosspost_post_now');
                echo '<p><a class="button" href="' . esc_url($url) . '">' . esc_html__('Post now', 'crosspost') . '</a></p>';
            }
        }
        echo '<p><button type="submit" class="button-link" name="save" value="1">' . esc_html__('Refresh preview (saves the post)', 'crosspost') . '</button></p>';
    }

    /**
     * @param array<string, mixed>|null $status
     */
    private static function canPostNow(?array $status): bool
    {
        if ($status === null) {
            return true;
        }
        return in_array($status['state'], [Job::CANCELLED, Job::SKIPPED], true)
            || ($status['state'] === Job::FAILED && $status['reason'] !== Job::R_NEVER_LANDED);
    }

    public static function save(int $postId, \WP_Post $post): void
    {
        if (!isset($_POST['crosspost_panel_nonce']) || wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['crosspost_panel_nonce'])), self::NONCE) === false) {
            return;
        }
        if ((defined('DOING_AUTOSAVE') && constant('DOING_AUTOSAVE')) || wp_is_post_revision($postId) !== false || !current_user_can('edit_post', $postId)) {
            return;
        }
        $app = Plugin::app();
        $optOut = isset($_POST['crosspost_opt_out']) && sanitize_key(wp_unslash((string) $_POST['crosspost_opt_out'])) === '1';
        if ($optOut !== Crosspost::optedOut($postId)) {
            $app->setOptOut($postId, $optOut);
        }
        $image = isset($_POST['crosspost_image']) ? absint(wp_unslash((string) $_POST['crosspost_image'])) : 0;
        if ($image > 0) {
            update_post_meta($postId, ImageSelector::OVERRIDE_META, $image);
        } else {
            delete_post_meta($postId, ImageSelector::OVERRIDE_META);
        }
    }
}
