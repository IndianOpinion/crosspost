<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost;

use IndianOpinion\Crosspost\Admin\Actions;
use IndianOpinion\Crosspost\Admin\PostPanel;
use IndianOpinion\Crosspost\Admin\Rest;
use IndianOpinion\Crosspost\Admin\SettingsPage;
use IndianOpinion\Crosspost\Admin\ToolsPage;
use IndianOpinion\Crosspost\Cli\Command;
use IndianOpinion\Crosspost\Media\ImageSelector;
use IndianOpinion\Crosspost\Store\Job;
use IndianOpinion\Crosspost\Store\Schema;
use IndianOpinion\Crosspost\Store\WpdbStore;
use IndianOpinion\Crosspost\Support\SystemClock;
use IndianOpinion\Crosspost\Support\SystemSleeper;

/**
 * Wires the plugin into WordPress.
 */
final class Plugin
{
    private static ?Crosspost $app = null;

    public static function app(): Crosspost
    {
        if (self::$app === null) {
            global $wpdb;
            $schema = new Schema($wpdb);
            self::$app = new Crosspost(
                new WpdbStore($wpdb),
                new SystemClock(),
                new SystemSleeper(),
                static fn (): array => $schema->maybeUpgrade(),
            );
        }
        return self::$app;
    }

    /**
     * Tests and site code that need a custom container use this.
     */
    public static function setApp(?Crosspost $app): void
    {
        self::$app = $app;
    }

    public static function boot(string $file): void
    {
        add_action('transition_post_status', [self::class, 'onTransition'], 10, 3);
        add_action('before_delete_post', [self::class, 'onDelete'], 10, 1);
        add_action(Crosspost::RUN_HOOK, [self::class, 'onRun']);
        add_action(Crosspost::PRUNE_HOOK, [self::class, 'onPrune']);
        add_action('init', [self::class, 'registerMeta']);
        add_action('rest_api_init', [Rest::class, 'register']);
        add_action('admin_init', [self::class, 'privacyText']);

        if (is_admin()) {
            Actions::register();
            SettingsPage::register();
            ToolsPage::register();
            PostPanel::register($file);
        }
        if (defined('WP_CLI') && constant('WP_CLI') && class_exists('WP_CLI')) {
            \WP_CLI::add_command('crosspost', Command::class);
        }
        register_activation_hook($file, [self::class, 'activate']);
        register_deactivation_hook($file, [self::class, 'deactivate']);
    }

    /**
     * @param mixed $post
     */
    public static function onTransition(string $new, string $old, $post): void
    {
        if ($post instanceof \WP_Post) {
            self::app()->onTransition($new, $old, $post);
        }
    }

    public static function onDelete(int $postId): void
    {
        self::app()->cancelUnsent($postId, Job::R_POST_GONE);
    }

    public static function onRun(): void
    {
        self::app()->run();
    }

    public static function onPrune(): void
    {
        self::app()->prune();
    }

    public static function registerMeta(): void
    {
        $auth = static fn (bool $allowed, string $key, int $postId): bool => current_user_can('edit_post', $postId);
        $prepare = static function ($value, \WP_REST_Request $request) {
            $id = (int) $request->get_param('id');
            return $id > 0 && current_user_can('edit_post', $id) ? $value : null;
        };
        foreach (self::app()->settings()->postTypes() as $type) {
            register_post_meta($type, Crosspost::OPT_OUT_META, [
                'type' => 'boolean',
                'single' => true,
                'default' => false,
                'auth_callback' => $auth,
                'show_in_rest' => ['prepare_callback' => $prepare],
            ]);
            register_post_meta($type, ImageSelector::OVERRIDE_META, [
                'type' => 'integer',
                'single' => true,
                'default' => 0,
                'sanitize_callback' => 'absint',
                'auth_callback' => $auth,
                'show_in_rest' => ['prepare_callback' => $prepare],
            ]);
        }
    }

    public static function privacyText(): void
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }
        wp_add_privacy_policy_content(
            'Crosspost',
            wp_kses_post(
                '<p>' . __('When a post is published, this site may announce it on social networks that the site owner has connected (X, Threads, Bluesky, Mastodon). The announcement contains the post title, excerpt, link, image and image description, which are already public. Nothing about readers is sent. The site stores the connected account id and display name, and the user id of the administrator who confirmed each network\'s preview.', 'crosspost') . '</p>'
            )
        );
    }

    public static function activate(): void
    {
        global $wpdb;
        (new Schema($wpdb))->maybeUpgrade(true);
        self::app()->syncEvents();
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(Crosspost::RUN_HOOK);
        wp_clear_scheduled_hook(Crosspost::PRUNE_HOOK);
    }
}
