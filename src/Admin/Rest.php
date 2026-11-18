<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Admin;

use IndianOpinion\Crosspost\Crosspost;
use IndianOpinion\Crosspost\Exception\CrosspostException;
use IndianOpinion\Crosspost\Network;
use IndianOpinion\Crosspost\Plugin;

/**
 * REST routes for the block editor panel. Cookie authentication with the
 * REST nonce; every route checks edit_post on the post.
 */
final class Rest
{
    public const NS = 'crosspost/v1';

    public static function register(): void
    {
        $id = ['id' => ['validate_callback' => static fn ($v): bool => is_numeric($v) && (int) $v > 0]];
        register_rest_route(self::NS, '/posts/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [self::class, 'panel'],
            'permission_callback' => [self::class, 'canEdit'],
            'args' => $id,
        ]);
        register_rest_route(self::NS, '/posts/(?P<id>\d+)/opt-out', [
            'methods' => 'POST',
            'callback' => [self::class, 'optOut'],
            'permission_callback' => [self::class, 'canEdit'],
            'args' => $id + ['opt_out' => ['type' => 'boolean', 'required' => true]],
        ]);
        register_rest_route(self::NS, '/posts/(?P<id>\d+)/send', [
            'methods' => 'POST',
            'callback' => [self::class, 'postNow'],
            'permission_callback' => static fn (\WP_REST_Request $r): bool => self::canEdit($r) && current_user_can('publish_posts'),
            'args' => $id + ['network' => ['type' => 'string', 'required' => true, 'enum' => Network::ALL]],
        ]);
    }

    public static function canEdit(\WP_REST_Request $request): bool
    {
        return current_user_can('edit_post', (int) $request->get_param('id'));
    }

    public static function panel(\WP_REST_Request $request): \WP_REST_Response
    {
        $app = Plugin::app();
        $postId = (int) $request->get_param('id');
        $settings = $app->settings();
        $status = $app->postStatus($postId);
        $networks = [];
        foreach (Network::ALL as $network) {
            if (!$settings->enabled($network) && !isset($status[$network])) {
                continue;
            }
            $networks[] = [
                'network' => $network,
                'label' => Network::label($network),
                'enabled' => $settings->enabled($network),
                'status' => $status[$network] ?? null,
                'preview' => $settings->enabled($network) ? $app->preview($postId, $network) : null,
            ];
        }
        return new \WP_REST_Response([
            'opt_out' => Crosspost::optedOut($postId),
            'can_post_now' => current_user_can('publish_posts'),
            'networks' => $networks,
        ]);
    }

    public static function optOut(\WP_REST_Request $request): \WP_REST_Response
    {
        $postId = (int) $request->get_param('id');
        Plugin::app()->setOptOut($postId, (bool) $request->get_param('opt_out'));
        return new \WP_REST_Response(['opt_out' => Crosspost::optedOut($postId)]);
    }

    public static function postNow(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $app = Plugin::app();
        try {
            $job = $app->postNow((int) $request->get_param('id'), (string) $request->get_param('network'), get_current_user_id());
        } catch (CrosspostException $e) {
            return new \WP_Error('crosspost_refused', $app->logger->scrub($e->getMessage()), ['status' => 409]);
        }
        return new \WP_REST_Response(['job' => $job->id, 'state' => $job->state, 'reason' => $job->reason]);
    }
}
