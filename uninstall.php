<?php

/**
 * Uninstall: local data only. No network call is made and no token is
 * revoked remotely; revoke the app's access in each network's settings.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (is_readable(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    require_once __DIR__ . '/src/autoload.php';
}

\IndianOpinion\Crosspost\Uninstaller::run();
