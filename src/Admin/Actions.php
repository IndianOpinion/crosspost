<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Admin;

use IndianOpinion\Crosspost\CredentialStore;
use IndianOpinion\Crosspost\Exception\CrosspostException;
use IndianOpinion\Crosspost\Network;
use IndianOpinion\Crosspost\Plugin;
use IndianOpinion\Crosspost\Settings;

/**
 * Every admin action. Each one checks a capability and a nonce before it
 * reads any input; the OAuth callback checks a state value bound to the
 * user instead of a nonce, because the network makes the redirect.
 */
final class Actions
{
    /**
     * Action => capability. "edit_post" and "publish_posts" actions also
     * check the post.
     *
     * @var array<string, string>
     */
    public const ACTIONS = [
        'crosspost_save_settings' => 'manage_options',
        'crosspost_save_credentials' => 'manage_options',
        'crosspost_forget' => 'manage_options',
        'crosspost_verify' => 'manage_options',
        'crosspost_confirm' => 'manage_options',
        'crosspost_enable' => 'manage_options',
        'crosspost_kill_switch' => 'manage_options',
        'crosspost_job' => 'manage_options',
        'crosspost_dismiss' => 'manage_options',
        'crosspost_oauth_start' => 'manage_options',
        'crosspost_post_now' => 'publish_posts',
    ];

    public const OAUTH_CALLBACK = 'crosspost_oauth_callback';

    /** @var (\Closure(): void)|null */
    public static ?\Closure $terminate = null;

    public static function register(): void
    {
        foreach (array_keys(self::ACTIONS) as $action) {
            add_action('admin_post_' . $action, static function () use ($action): void {
                self::handle($action);
            });
        }
        add_action('admin_post_' . self::OAUTH_CALLBACK, static function (): void {
            OAuth::callback();
        });
        add_action('admin_notices', [Notices::class, 'render']);
    }

    public static function handle(string $action): void
    {
        $capability = self::ACTIONS[$action] ?? null;
        if ($capability === null) {
            wp_die(esc_html__('Unknown action.', 'crosspost'), '', ['response' => 400]);
        }
        $nonce = isset($_REQUEST['_wpnonce']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['_wpnonce'])) : '';
        if ($nonce === '' || wp_verify_nonce($nonce, $action) === false) {
            wp_die(esc_html__('The link has expired. Go back and try again.', 'crosspost'), '', ['response' => 403]);
        }
        if (!current_user_can($capability)) {
            wp_die(esc_html__('You are not allowed to do this.', 'crosspost'), '', ['response' => 403]);
        }
        try {
            match ($action) {
                'crosspost_save_settings' => self::saveSettings(),
                'crosspost_save_credentials' => self::saveCredentials(),
                'crosspost_forget' => self::forget(),
                'crosspost_verify' => self::verify(),
                'crosspost_confirm' => self::confirm(),
                'crosspost_enable' => self::enable(),
                'crosspost_kill_switch' => self::killSwitch(),
                'crosspost_job' => self::job(),
                'crosspost_dismiss' => self::dismiss(),
                'crosspost_oauth_start' => self::oauthStart(),
                'crosspost_post_now' => self::postNow(),
                default => wp_die(esc_html__('Unknown action.', 'crosspost'), '', ['response' => 400]),
            };
        } catch (CrosspostException $e) {
            self::flash(Plugin::app()->logger->scrub($e->getMessage()), 'error');
            self::back();
        }
    }

    private static function saveSettings(): void
    {
        $input = isset($_POST['crosspost']) && is_array($_POST['crosspost']) ? wp_unslash($_POST['crosspost']) : [];
        if (!is_array($input)) {
            $input = [];
        }
        // Switching a network on has its own rules; not through this form.
        if (isset($input['networks']) && is_array($input['networks'])) {
            foreach ($input['networks'] as $network => $values) {
                if (is_array($values)) {
                    unset($input['networks'][$network]['enabled']);
                }
            }
        }
        unset($input['kill_switch']);
        $current = get_option(Settings::OPTION, []);
        update_option(Settings::OPTION, Settings::sanitize($input, is_array($current) ? $current : []), true);
        Plugin::app()->syncEvents();
        self::flash(__('Settings saved.', 'crosspost'));
        self::back();
    }

    private static function saveCredentials(): void
    {
        $network = self::network();
        $input = [];
        $raw = isset($_POST['credentials']) && is_array($_POST['credentials']) ? wp_unslash($_POST['credentials']) : [];
        foreach (CredentialStore::EDITABLE[$network] as $field) {
            if (is_array($raw) && isset($raw[$field]) && is_string($raw[$field])) {
                $input[$field] = sanitize_text_field($raw[$field]);
            }
        }
        Plugin::app()->credentials->replace($network, $input);
        self::flash(__('Credentials saved. Verify them next.', 'crosspost'));
        self::back();
    }

    private static function forget(): void
    {
        $network = self::network();
        $app = Plugin::app();
        $app->setEnabled($network, false);
        $app->credentials->forget($network);
        self::flash(__('Disconnected locally. Revoke the app\'s access in the network\'s own settings as well.', 'crosspost'));
        self::back();
    }

    private static function verify(): void
    {
        $network = self::network();
        $account = Plugin::app()->verify($network);
        /* translators: 1: network, 2: account display name, 3: account id */
        self::flash(sprintf(__('%1$s verified: %2$s (%3$s).', 'crosspost'), Network::label($network), $account->name, $account->id));
        self::back();
    }

    private static function confirm(): void
    {
        $network = self::network();
        Plugin::app()->confirmPreview($network, get_current_user_id());
        self::flash(__('Preview confirmed.', 'crosspost'));
        self::back();
    }

    private static function enable(): void
    {
        $network = self::network();
        $on = isset($_POST['on']) && sanitize_key(wp_unslash((string) $_POST['on'])) === '1';
        Plugin::app()->setEnabled($network, $on);
        self::flash($on ? __('Network switched on.', 'crosspost') : __('Network switched off.', 'crosspost'));
        self::back();
    }

    private static function killSwitch(): void
    {
        if (Settings::killSwitchFromConstant()) {
            self::flash(__('The kill switch is set by a constant in wp-config.php.', 'crosspost'), 'error');
            self::back();
            return;
        }
        $on = isset($_POST['on']) && sanitize_key(wp_unslash((string) $_POST['on'])) === '1';
        Plugin::app()->setKillSwitch($on);
        self::flash($on ? __('Kill switch on: no outbound calls.', 'crosspost') : __('Kill switch off.', 'crosspost'));
        self::back();
    }

    private static function job(): void
    {
        $id = isset($_POST['job']) ? absint(wp_unslash((string) $_POST['job'])) : 0;
        $op = isset($_POST['op']) ? sanitize_key(wp_unslash((string) $_POST['op'])) : '';
        $app = Plugin::app();
        if ($op === 'send') {
            $app->send($id, get_current_user_id());
            self::flash(__('Released. It goes out on the next run, subject to the cap.', 'crosspost'));
        } elseif ($op === 'cancel') {
            $app->cancel($id, get_current_user_id());
            self::flash(__('Cancelled.', 'crosspost'));
        } else {
            self::flash(__('Unknown operation.', 'crosspost'), 'error');
        }
        self::back();
    }

    private static function dismiss(): void
    {
        $key = isset($_POST['key']) ? sanitize_key(wp_unslash((string) $_POST['key'])) : '';
        Plugin::app()->notifier->dismiss($key);
        self::back();
    }

    private static function oauthStart(): void
    {
        OAuth::start(self::network());
    }

    private static function postNow(): void
    {
        $postId = isset($_REQUEST['post']) ? absint(wp_unslash((string) $_REQUEST['post'])) : 0;
        if (!current_user_can('edit_post', $postId)) {
            wp_die(esc_html__('You are not allowed to do this.', 'crosspost'), '', ['response' => 403]);
        }
        Plugin::app()->postNow($postId, self::network(), get_current_user_id());
        self::flash(__('Queued. It goes out on the next run, subject to the cap.', 'crosspost'));
        self::back();
    }

    private static function network(): string
    {
        $network = isset($_REQUEST['network']) ? sanitize_key(wp_unslash((string) $_REQUEST['network'])) : '';
        return Network::assertValid($network);
    }

    public static function flash(string $message, string $type = 'success'): void
    {
        set_transient('crosspost_flash_' . get_current_user_id(), ['message' => $message, 'type' => $type], 60);
    }

    /**
     * @return array{message: string, type: string}|null
     */
    public static function takeFlash(): ?array
    {
        $key = 'crosspost_flash_' . get_current_user_id();
        $flash = get_transient($key);
        if (!is_array($flash) || !isset($flash['message']) || !is_string($flash['message'])) {
            return null;
        }
        delete_transient($key);
        return ['message' => $flash['message'], 'type' => ($flash['type'] ?? '') === 'error' ? 'error' : 'success'];
    }

    public static function back(?string $url = null): void
    {
        $url ??= (string) wp_get_referer();
        if ($url === '') {
            $url = admin_url('options-general.php?page=crosspost');
        }
        wp_safe_redirect($url);
        self::terminate();
    }

    public static function terminate(): void
    {
        if (self::$terminate !== null) {
            (self::$terminate)();
            return;
        }
        exit;
    }

    /**
     * A form's hidden fields for an action.
     */
    public static function fields(string $action): string
    {
        return '<input type="hidden" name="action" value="' . esc_attr($action) . '">' . wp_nonce_field($action, '_wpnonce', true, false);
    }
}
