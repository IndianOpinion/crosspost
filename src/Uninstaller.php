<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost;

use IndianOpinion\Crosspost\Adapter\MastodonAdapter;
use IndianOpinion\Crosspost\Media\ImageSelector;
use IndianOpinion\Crosspost\Store\Schema;

/**
 * Removes everything the plugin created on this site: tables, options,
 * transients, post meta and scheduled events. Makes no network call.
 */
final class Uninstaller
{
    public static function run(): void
    {
        if (function_exists('is_multisite') && is_multisite() && function_exists('get_sites')) {
            foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
                switch_to_blog((int) $siteId);
                self::site();
                restore_current_blog();
            }
            return;
        }
        self::site();
    }

    public static function site(): void
    {
        global $wpdb;
        wp_clear_scheduled_hook(Crosspost::RUN_HOOK);
        wp_clear_scheduled_hook(Crosspost::PRUNE_HOOK);
        (new Schema($wpdb))->drop();

        $options = [
            Settings::OPTION,
            Schema::VERSION_OPTION,
            Schema::MISSING_OPTION,
            Notifier::NOTICES_OPTION,
            Crosspost::LOCK,
        ];
        foreach (Network::ALL as $network) {
            $options[] = CredentialStore::OPTION_PREFIX . $network;
            $options[] = Review::OPTION_PREFIX . $network;
        }
        foreach ($options as $option) {
            delete_option($option);
        }
        delete_transient(MastodonAdapter::CONFIG_TRANSIENT);
        // Per-user transients: OAuth state and flash messages.
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like('_transient_crosspost_') . '%',
            $wpdb->esc_like('_transient_timeout_crosspost_') . '%',
            $wpdb->esc_like('crosspost_') . '%',
            $wpdb->esc_like('_site_transient_crosspost_') . '%',
        ));
        delete_post_meta_by_key(Crosspost::OPT_OUT_META);
        delete_post_meta_by_key(ImageSelector::OVERRIDE_META);
    }
}
