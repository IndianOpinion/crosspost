<?php

/**
 * Plugin Name: Crosspost (loader)
 * Description: Loads crosspost from a directory outside wp-content/plugins.
 *
 * crosspost is a normal plugin, because it has admin screens and an
 * uninstall routine. If you deploy plugins as must-use plugins, copy the
 * crosspost directory to wp-content/mu-plugins/crosspost and this file to
 * wp-content/mu-plugins/crosspost-loader.php. Note that must-use plugins
 * never run activation or uninstall hooks: the tables are created on first
 * use, and removing the plugin leaves its data until you run the uninstall
 * routine yourself (see the README).
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/crosspost/crosspost.php';
