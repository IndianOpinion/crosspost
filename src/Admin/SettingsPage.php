<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Admin;

use IndianOpinion\Crosspost\CredentialStore;
use IndianOpinion\Crosspost\Network;
use IndianOpinion\Crosspost\Plugin;
use IndianOpinion\Crosspost\Settings;
use IndianOpinion\Crosspost\Store\Job;
use IndianOpinion\Crosspost\Text\Graphemes;

/**
 * Settings > Crosspost. Makes no network call on load.
 */
final class SettingsPage
{
    public const SLUG = 'crosspost';

    /**
     * Field labels for the credential forms.
     *
     * @var array<string, string>
     */
    private const FIELD_LABELS = [
        'client_id' => 'Client id',
        'client_secret' => 'Client secret (confidential clients only)',
        'app_id' => 'App id',
        'app_secret' => 'App secret',
        'service' => 'Service URL (leave empty for the main public service)',
        'identifier' => 'Account identifier',
        'app_password' => 'App password (never the account password)',
        'instance' => 'Instance URL (https://...)',
        'access_token' => 'Access token',
    ];

    private const SECRET_FIELDS = ['client_secret', 'app_secret', 'app_password', 'access_token'];

    public static function register(): void
    {
        add_action('admin_menu', static function (): void {
            add_options_page(
                __('Crosspost', 'crosspost'),
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
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash((string) $_GET['tab'])) : 'general';
        if ($tab !== 'general' && !Network::isValid($tab)) {
            $tab = 'general';
        }
        echo '<div class="wrap"><h1>' . esc_html__('Crosspost', 'crosspost') . '</h1>';
        $flash = Actions::takeFlash();
        if ($flash !== null) {
            echo '<div class="notice notice-' . esc_attr($flash['type'] === 'error' ? 'error' : 'success') . '"><p>' . esc_html($flash['message']) . '</p></div>';
        }
        echo '<nav class="nav-tab-wrapper">';
        foreach (array_merge(['general'], Network::ALL) as $item) {
            $label = $item === 'general' ? __('General', 'crosspost') : Network::label($item);
            $url = admin_url('options-general.php?page=' . self::SLUG . '&tab=' . $item);
            echo '<a class="nav-tab' . ($item === $tab ? ' nav-tab-active' : '') . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
        if ($tab === 'general') {
            self::general();
        } else {
            self::network($tab);
        }
        echo '</div>';
    }

    private static function general(): void
    {
        $app = Plugin::app();
        $settings = $app->settings();

        echo '<h2>' . esc_html__('Kill switch', 'crosspost') . '</h2>';
        $on = $settings->killSwitch();
        echo '<p><strong>' . esc_html($on ? __('On: no outbound calls at all.', 'crosspost') : __('Off: networks that are on may post.', 'crosspost')) . '</strong></p>';
        if (Settings::killSwitchFromConstant()) {
            echo '<p>' . esc_html__('Set by the CROSSPOST_KILL_SWITCH constant in wp-config.php.', 'crosspost') . '</p>';
        } else {
            self::button('crosspost_kill_switch', $on ? __('Turn the kill switch off', 'crosspost') : __('Turn the kill switch on', 'crosspost'), ['on' => $on ? '0' : '1'], $on ? 'secondary' : 'primary');
        }

        echo '<h2>' . esc_html__('Status', 'crosspost') . '</h2>';
        echo '<table class="widefat striped"><thead><tr>';
        foreach ([__('Network', 'crosspost'), __('On', 'crosspost'), __('Credentials', 'crosspost'), __('Account', 'crosspost'), __('Cap used', 'crosspost'), __('Next slot', 'crosspost'), __('Cooldown until', 'crosspost'), __('First post review', 'crosspost'), __('Last error', 'crosspost')] as $heading) {
            echo '<th>' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($app->networkStatus() as $network => $row) {
            echo '<tr><td>' . esc_html(Network::label($network)) . '</td>'
                . '<td>' . esc_html($row['enabled'] ? __('yes', 'crosspost') : __('no', 'crosspost')) . '</td>'
                . '<td>' . esc_html($row['credentials'] ? ($row['verified'] ? __('present, verified', 'crosspost') : __('present, not verified', 'crosspost')) : __('absent', 'crosspost')) . '</td>'
                . '<td>' . esc_html((string) $row['account']) . ($row['attention'] !== '' ? ' <strong>' . esc_html__('needs attention:', 'crosspost') . '</strong> ' . esc_html((string) $row['attention']) : '') . '</td>'
                . '<td>' . esc_html($row['used'] . ' / ' . $row['cap']) . '</td>'
                . '<td>' . esc_html(self::time((int) $row['next_release'])) . '</td>'
                . '<td>' . esc_html(self::time((int) $row['cooldown_until'])) . '</td>'
                . '<td>' . esc_html($row['first_post_review'] ? __('pending', 'crosspost') : __('done', 'crosspost')) . '</td>'
                . '<td>' . esc_html((string) $row['last_error']) . '</td></tr>';
        }
        echo '</tbody></table>';

        $review = [];
        foreach (Network::ALL as $network) {
            foreach ($app->store->jobs(['state' => Job::HELD, 'network' => $network], 20) as $job) {
                if ($job->reason === Job::R_FIRST_POST) {
                    $review[] = $job;
                }
            }
        }
        if ($review !== []) {
            echo '<h2>' . esc_html__('Waiting for first post review', 'crosspost') . '</h2>';
            echo '<p>' . esc_html__('Nothing goes out on a new network until a person has seen the first announcement. Send one; later ones flow on their own.', 'crosspost') . '</p>';
            echo '<table class="widefat striped"><tbody>';
            foreach ($review as $job) {
                echo '<tr><td>' . esc_html(Network::label($job->network)) . '</td><td><pre style="white-space:pre-wrap">' . esc_html($job->text) . '</pre></td><td>';
                self::button('crosspost_job', __('Send', 'crosspost'), ['job' => (string) $job->id, 'op' => 'send'], 'primary');
                self::button('crosspost_job', __('Cancel', 'crosspost'), ['job' => (string) $job->id, 'op' => 'cancel']);
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }

        echo '<h2>' . esc_html__('General settings', 'crosspost') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . Actions::fields('crosspost_save_settings'); // phpcs:ignore WordPress.Security.EscapeOutput
        echo '<table class="form-table">';
        self::row(__('Post types', 'crosspost'), '<input type="text" class="regular-text" name="crosspost[post_types]" value="' . esc_attr(implode(',', $settings->postTypes())) . '">');
        self::number('max_age', __('Maximum post age at publish (seconds)', 'crosspost'), $settings->int('max_age'));
        self::number('first_delay', __('Delay before the first run (seconds)', 'crosspost'), $settings->int('first_delay'));
        self::number('jobs_per_run', __('Jobs per run', 'crosspost'), $settings->int('jobs_per_run'));
        self::number('run_budget', __('Run time budget (seconds)', 'crosspost'), $settings->int('run_budget'));
        self::number('max_queue_age', __('Maximum queue age (seconds)', 'crosspost'), $settings->int('max_queue_age'));
        self::number('stale_sending', __('Stale "sending" threshold (seconds)', 'crosspost'), $settings->int('stale_sending'));
        self::number('reconcile_window', __('Reconciliation window (seconds)', 'crosspost'), $settings->int('reconcile_window'));
        self::number('first_post_pause_days', __('First post review again after this many days without a post', 'crosspost'), $settings->int('first_post_pause_days'));
        self::number('log_retention_days', __('Log retention (days)', 'crosspost'), $settings->int('log_retention_days'));
        self::number('job_retention_days', __('Job retention (days)', 'crosspost'), $settings->int('job_retention_days'));
        self::row(__('Notification email', 'crosspost'), '<input type="email" class="regular-text" name="crosspost[notify_email]" value="' . esc_attr($settings->string('notify_email')) . '">');
        echo '</table>';
        submit_button(__('Save settings', 'crosspost'));
        echo '</form>';

        echo '<h2>' . esc_html__('Environment', 'crosspost') . '</h2><ul>';
        echo '<li>' . esc_html(Graphemes::usingIntl() ? __('Grapheme counting: intl extension.', 'crosspost') : __('Grapheme counting: PCRE fallback (intl is not loaded).', 'crosspost')) . '</li>';
        echo '<li>' . esc_html(wp_image_editor_supports(['mime_type' => 'image/jpeg']) ? __('Image editor available for resizing.', 'crosspost') : __('No image editor (GD or Imagick): images that need resizing are skipped.', 'crosspost')) . '</li>';
        echo '<li>' . esc_html__('Re-encoded copies have their metadata removed. Original files sent unchanged keep their metadata.', 'crosspost') . '</li>';
        $missing = $app->missingColumns();
        if ($missing !== []) {
            echo '<li><strong>' . esc_html__('Sending is refused. Missing database columns:', 'crosspost') . '</strong> ' . esc_html(implode(', ', $missing)) . '</li>';
        }
        echo '</ul>';
    }

    private static function network(string $network): void
    {
        $app = Plugin::app();
        $settings = $app->settings();
        $creds = $app->credentials->get($network);
        $label = Network::label($network);

        echo '<h2>' . esc_html(sprintf(__('%s connection', 'crosspost'), $label)) . '</h2>';
        if ($app->credentials->isVerified($creds)) {
            echo '<p>' . esc_html(sprintf(__('Connected and verified: %1$s (account id %2$s).', 'crosspost'), $creds->get('verified_name'), $creds->get('verified_account'))) . '</p>';
        } else {
            echo '<p>' . esc_html__('Not verified.', 'crosspost') . '</p>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . Actions::fields('crosspost_save_credentials'); // phpcs:ignore WordPress.Security.EscapeOutput
        echo '<input type="hidden" name="network" value="' . esc_attr($network) . '"><table class="form-table">';
        foreach (CredentialStore::EDITABLE[$network] as $field) {
            $title = self::FIELD_LABELS[$field];
            if ($creds->fromConstant($field)) {
                self::row($title, esc_html__('Set by constant.', 'crosspost'));
                continue;
            }
            $secret = in_array($field, self::SECRET_FIELDS, true);
            $value = $secret ? '' : $creds->get($field);
            $note = $secret ? ($creds->has($field) ? __('Stored. Leave empty to keep it.', 'crosspost') : __('Not stored.', 'crosspost')) : '';
            self::row($title, '<input type="' . ($secret ? 'password' : 'text') . '" autocomplete="off" class="regular-text" name="credentials[' . esc_attr($field) . ']" value="' . esc_attr($value) . '"> <span class="description">' . esc_html($note) . '</span>');
        }
        echo '</table>';
        submit_button(__('Save credentials', 'crosspost'), 'secondary');
        echo '</form><p>';
        if ($network !== Network::BLUESKY) {
            self::button('crosspost_oauth_start', sprintf(__('Connect with %s', 'crosspost'), $label), ['network' => $network], 'primary');
        }
        self::button('crosspost_verify', __('Verify credentials', 'crosspost'), ['network' => $network]);
        self::button('crosspost_forget', __('Disconnect', 'crosspost'), ['network' => $network]);
        echo '</p><p class="description">' . esc_html(self::help($network)) . '</p>';

        echo '<h2>' . esc_html__('Posting', 'crosspost') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . Actions::fields('crosspost_save_settings'); // phpcs:ignore WordPress.Security.EscapeOutput
        $name = 'crosspost[networks][' . $network . ']';
        echo '<table class="form-table">';
        self::row(__('Template', 'crosspost'), '<textarea name="' . esc_attr($name) . '[template]" rows="4" cols="60">' . esc_textarea($settings->template($network)) . '</textarea><p class="description">' . esc_html__('Placeholders: {title} {excerpt} {url} {site_name} {author} {category} {tags} {date}', 'crosspost') . '</p>');
        if ($network !== Network::X) {
            $modes = [Settings::IMAGE_ATTACH => __('Attach the image', 'crosspost'), Settings::IMAGE_NONE => __('No image', 'crosspost')];
            if ($network === Network::BLUESKY) {
                $modes[Settings::IMAGE_CARD] = __('Link card only', 'crosspost');
            }
            self::row(__('Image', 'crosspost'), self::select($name . '[image_mode]', $modes, $settings->imageMode($network)));
            self::row(__('Require alt text', 'crosspost'), self::select($name . '[require_alt]', ['1' => __('Yes: no alt text means text only', 'crosspost'), '0' => __('No', 'crosspost')], $settings->requireAlt($network) ? '1' : '0'));
            self::row(__('When the image fails', 'crosspost'), self::select($name . '[image_failure]', [Settings::ON_IMAGE_FAIL_TEXT => __('Post as text only', 'crosspost'), Settings::ON_IMAGE_FAIL_RETRY => __('Retry later', 'crosspost')], $settings->imageFailure($network)));
        } else {
            self::row(__('Image', 'crosspost'), esc_html__('X posts are text only in this version.', 'crosspost'));
        }
        self::row(__('Daily cap (posts per rolling day)', 'crosspost'), '<input type="number" min="0" max="' . esc_attr((string) Network::ceiling($network)) . '" name="' . esc_attr($name) . '[cap]" value="' . esc_attr((string) $settings->cap($network)) . '"> <span class="description">' . esc_html(sprintf(__('0 holds everything. Ceiling: %d.', 'crosspost'), Network::ceiling($network))) . '</span>');
        self::row(__('Minimum spacing (seconds)', 'crosspost'), '<input type="number" min="0" name="' . esc_attr($name) . '[spacing]" value="' . esc_attr((string) $settings->spacing($network)) . '">');
        self::row(__('UTM parameters', 'crosspost'), self::select($name . '[utm]', ['0' => __('Off', 'crosspost'), '1' => __('On', 'crosspost')], $settings->utm($network) ? '1' : '0') . ' <input type="text" name="' . esc_attr($name) . '[utm_campaign]" value="' . esc_attr($settings->utmCampaign($network)) . '">');
        if ($network === Network::MASTODON) {
            self::row(__('Visibility', 'crosspost'), self::select($name . '[visibility]', ['public' => 'public', 'unlisted' => 'unlisted', 'private' => 'private'], $settings->visibility($network)));
        }
        if ($network === Network::BLUESKY || $network === Network::MASTODON) {
            self::row(__('Language (empty: site language)', 'crosspost'), '<input type="text" name="' . esc_attr($name) . '[language]" value="' . esc_attr($settings->language($network)) . '">');
        }
        echo '</table>';
        submit_button(__('Save', 'crosspost'));
        echo '</form>';

        echo '<h2>' . esc_html__('Preview', 'crosspost') . '</h2>';
        echo '<p>' . esc_html__('The template rendered against the three most recent posts, with this network\'s counting and image rules. Nothing is sent.', 'crosspost') . '</p>';
        foreach ($app->networkPreview($network) as $item) {
            echo '<div class="card" style="max-width:640px"><h3>' . esc_html((string) $item['title']) . '</h3>';
            echo '<pre style="white-space:pre-wrap">' . esc_html((string) $item['text']) . '</pre>';
            echo '<p>' . esc_html(sprintf(__('%1$d of %2$d', 'crosspost'), (int) $item['count'], (int) $item['limit'])) . '</p>';
            if ((string) $item['error'] !== '') {
                echo '<p><strong>' . esc_html((string) $item['error']) . '</strong></p>';
            }
            foreach ((array) $item['warnings'] as $warning) {
                echo '<p>' . esc_html((string) $warning) . '</p>';
            }
            $image = (int) $item['image'];
            echo '<p>' . esc_html($image > 0 ? sprintf(__('Image: attachment %1$d. Alt text: %2$s', 'crosspost'), $image, (string) $item['alt']) : __('No image.', 'crosspost')) . ' ' . esc_html((string) $item['image_note']) . '</p></div>';
        }
        $review = $app->review->get($network);
        if ($app->review->isConfirmed($network, $settings->outputHash($network))) {
            echo '<p>' . esc_html(sprintf(__('Confirmed by user %1$d on %2$s.', 'crosspost'), $review['user'], self::time($review['time']))) . '</p>';
        } else {
            self::button('crosspost_confirm', __('Confirm this preview', 'crosspost'), ['network' => $network], 'primary');
        }

        echo '<h2>' . esc_html__('Switch', 'crosspost') . '</h2>';
        if ($settings->enabled($network)) {
            echo '<p>' . esc_html__('On.', 'crosspost') . '</p>';
            self::button('crosspost_enable', __('Switch off', 'crosspost'), ['network' => $network, 'on' => '0']);
        } else {
            $why = $app->cannotEnable($network);
            echo '<p>' . esc_html($why === '' ? __('Off. Ready to switch on.', 'crosspost') : sprintf(__('Off. To switch on: %s.', 'crosspost'), $why)) . '</p>';
            if ($why === '') {
                self::button('crosspost_enable', __('Switch on', 'crosspost'), ['network' => $network, 'on' => '1'], 'primary');
            }
        }
    }

    private static function help(string $network): string
    {
        switch ($network) {
            case Network::X:
                return __('Create an app in the X developer portal with OAuth 2.0, type "Web App", and this redirect URL: ', 'crosspost') . OAuth::redirectUri() . __('. Scopes requested: tweet.read tweet.write users.read offline.access.', 'crosspost');
            case Network::THREADS:
                return __('Create a Threads app in the Meta developer dashboard with this redirect URL: ', 'crosspost') . OAuth::redirectUri() . __('. Scopes requested: threads_basic, threads_content_publish.', 'crosspost');
            case Network::BLUESKY:
                return __('Create an app password in the account\'s privacy and security settings. Never enter the main account password.', 'crosspost');
            default:
                return __('Paste a token from the instance\'s development settings (scopes read:accounts read:statuses write:statuses write:media), or connect, which registers this site as an app on the instance.', 'crosspost');
        }
    }

    /**
     * @param array<string, string> $fields
     */
    public static function button(string $action, string $label, array $fields, string $style = 'secondary'): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:6px">';
        echo Actions::fields($action); // phpcs:ignore WordPress.Security.EscapeOutput
        foreach ($fields as $name => $value) {
            echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
        }
        echo '<button type="submit" class="button button-' . esc_attr($style) . '">' . esc_html($label) . '</button></form>';
    }

    private static function row(string $label, string $html): void
    {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . $html . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- callers escape.
    }

    private static function number(string $key, string $label, int $value): void
    {
        self::row($label, '<input type="number" min="0" name="crosspost[' . esc_attr($key) . ']" value="' . esc_attr((string) $value) . '">');
    }

    /**
     * @param array<int|string, string> $options
     */
    private static function select(string $name, array $options, string $current): string
    {
        $html = '<select name="' . esc_attr($name) . '">';
        foreach ($options as $value => $label) {
            $html .= '<option value="' . esc_attr((string) $value) . '"' . selected((string) $value, $current, false) . '>' . esc_html($label) . '</option>';
        }
        return $html . '</select>';
    }

    public static function time(int $timestamp): string
    {
        return $timestamp > 0 ? gmdate('Y-m-d H:i', $timestamp) . ' UTC' : '';
    }
}
