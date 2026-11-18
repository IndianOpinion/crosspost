<?php

declare(strict_types=1);

// A recording stand-in for WP-CLI.

namespace {
    final class WP_CLI_Error extends RuntimeException
    {
    }

    final class WP_CLI
    {
        /** @var list<string> */
        public static array $out = [];

        public static function line(string $message = ''): void
        {
            self::$out[] = $message;
        }

        public static function success(string $message): void
        {
            self::$out[] = 'Success: ' . $message;
        }

        public static function warning(string $message): void
        {
            self::$out[] = 'Warning: ' . $message;
        }

        public static function error(string $message): never
        {
            throw new WP_CLI_Error($message);
        }

        /**
         * @param array<string, mixed> $assoc
         */
        public static function confirm(string $question, array $assoc = []): void
        {
            if (!isset($assoc['yes'])) {
                throw new WP_CLI_Error('confirmation needed');
            }
        }

        public static function add_command(string $name, mixed $callable): void
        {
        }
    }
}

namespace WP_CLI\Utils {
    /**
     * @param list<array<string, mixed>> $items
     * @param list<string> $fields
     */
    function format_items(string $format, array $items, array $fields): void
    {
        foreach ($items as $item) {
            $row = [];
            foreach ($fields as $field) {
                $row[] = $field . '=' . (is_scalar($item[$field] ?? '') ? (string) ($item[$field] ?? '') : '');
            }
            \WP_CLI::line(implode(' | ', $row));
        }
    }
}
