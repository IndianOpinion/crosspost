<?php

/**
 * Public PHP API. Global functions with the crosspost_ prefix, so site code
 * can call them without importing a namespace.
 */

declare(strict_types=1);

use IndianOpinion\Crosspost\Plugin;

if (!function_exists('crosspost_queue')) {
    /**
     * Create a job by hand ("post now"). Every rule of job creation, the
     * cap and the preview confirmation apply.
     *
     * @param array{user_id?: int} $args
     * @throws \IndianOpinion\Crosspost\Exception\CrosspostException when the post cannot be queued
     */
    function crosspost_queue(int $postId, string $network, array $args = []): int
    {
        $user = (int) ($args['user_id'] ?? get_current_user_id());
        return Plugin::app()->postNow($postId, $network, $user)->id;
    }
}

if (!function_exists('crosspost_preview')) {
    /**
     * The text, counted length, limit, image and alt text, and warnings.
     * No network call, no write, no credentials needed.
     *
     * @return array<string, mixed>
     */
    function crosspost_preview(int $postId, string $network): array
    {
        return Plugin::app()->preview($postId, $network);
    }
}

if (!function_exists('crosspost_status')) {
    /**
     * Per network: job state, reason, remote URL and times.
     *
     * @return array<string, array<string, mixed>>
     */
    function crosspost_status(int $postId): array
    {
        return Plugin::app()->postStatus($postId);
    }
}

if (!function_exists('crosspost_kill_switch')) {
    /**
     * True when every outbound call is stopped.
     */
    function crosspost_kill_switch(): bool
    {
        return Plugin::app()->settings()->killSwitch();
    }
}
