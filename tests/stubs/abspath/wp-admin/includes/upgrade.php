<?php

declare(strict_types=1);

// Test stand-in for WordPress's upgrade.php: dbDelta runs the statement
// through the test database object.

if (!function_exists('dbDelta')) {
    /**
     * @param string|list<string> $queries
     * @return array<string, string>
     */
    function dbDelta($queries = '', bool $execute = true): array
    {
        global $wpdb;
        foreach ((array) $queries as $query) {
            if (is_object($wpdb) && method_exists($wpdb, 'dbDelta')) {
                $wpdb->dbDelta((string) $query);
            }
        }
        return [];
    }
}
