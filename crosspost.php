<?php

/**
 * Plugin Name:       Crosspost
 * Plugin URI:        https://github.com/IndianOpinion/crosspost
 * Description:       Announces each newly published post on X, Threads, Bluesky and Mastodon through their official APIs, with per-network templates, caps, a retry queue and a log. Never deletes or edits a remote post.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Indian Opinion
 * Author URI:        https://indianopinion.org
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       crosspost
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('CROSSPOST_VERSION', '0.1.0');
define('CROSSPOST_FILE', __FILE__);

if (is_readable(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    require_once __DIR__ . '/src/autoload.php';
}

\IndianOpinion\Crosspost\Plugin::boot(__FILE__);
