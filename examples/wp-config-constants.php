<?php

/**
 * Example lines for wp-config.php. Constants win over the settings screen,
 * which shows "set by constant" and never the value. Only values that do
 * not rotate can be constants; refreshed tokens are always stored.
 */

declare(strict_types=1);

// Stop every outbound call, whatever the settings screen says.
define('CROSSPOST_KILL_SWITCH', false);

// X: your developer app (OAuth 2.0). Connect from the settings screen.
define('CROSSPOST_X_CLIENT_ID', 'your-client-id');
define('CROSSPOST_X_CLIENT_SECRET', 'your-client-secret');

// Threads: your app. Connect from the settings screen.
define('CROSSPOST_THREADS_APP_ID', 'your-app-id');
define('CROSSPOST_THREADS_APP_SECRET', 'your-app-secret');

// Bluesky: an app password from the account settings, never the main password.
define('CROSSPOST_BLUESKY_IDENTIFIER', 'your-identifier');
define('CROSSPOST_BLUESKY_APP_PASSWORD', 'xxxx-xxxx-xxxx-xxxx');
// define('CROSSPOST_BLUESKY_SERVICE', 'https://your-data-server.example');

// Mastodon: instance URL and a token with read:accounts read:statuses write:statuses write:media.
define('CROSSPOST_MASTODON_INSTANCE', 'https://your-instance.example');
define('CROSSPOST_MASTODON_TOKEN', 'your-token');
