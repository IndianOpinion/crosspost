<?php

/**
 * Example site customisations. Copy what you need into a site plugin.
 *
 * Every default in crosspost has a filter; these are the common ones.
 */

declare(strict_types=1);

// Announce a custom post type as well as posts.
add_filter('crosspost_post_types', static function (array $types): array {
    $types[] = 'report';
    return $types;
});

// Never announce posts in the "Corrections" category, and say why in the log.
add_filter('crosspost_should_post', static function ($ok, WP_Post $post, string $network) {
    foreach (get_the_category($post->ID) as $category) {
        if ($category->slug === 'corrections') {
            return 'corrections are not announced';
        }
    }
    return $ok;
}, 10, 3);

// A shorter template for X only.
add_filter('crosspost_template', static function (string $template, WP_Post $post, string $network): string {
    return $network === 'x' ? "{title} {url}" : $template;
}, 10, 3);

// A placeholder of your own: {standfirst} from a custom field.
add_filter('crosspost_placeholders', static function (array $values, WP_Post $post): array {
    $values['standfirst'] = (string) get_post_meta($post->ID, 'standfirst', true);
    return $values;
}, 10, 2);

// Gentler retries on a slow host: 10 minutes, 1 hour, 6 hours.
add_filter('crosspost_backoff', static fn (): array => [600, 3600, 21600]);

// Wire your own monitoring.
add_action('crosspost_network_needs_attention', static function (string $network, string $reason): void {
    error_log(sprintf('crosspost: %s needs attention: %s', $network, $reason));
}, 10, 2);
