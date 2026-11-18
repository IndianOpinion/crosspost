<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Media;

/**
 * Picks the image for a post: per-post override, featured image, first
 * image in the content, none. A filter has the last word.
 */
final class ImageSelector
{
    public const OVERRIDE_META = '_crosspost_image';

    public function select(\WP_Post $post, string $network): int
    {
        $id = (int) get_post_meta($post->ID, self::OVERRIDE_META, true);
        if ($id <= 0 || !self::isImage($id)) {
            $id = (int) get_post_thumbnail_id($post);
        }
        if ($id <= 0 || !self::isImage($id)) {
            $id = self::firstInContent($post);
        }
        $filtered = apply_filters('crosspost_image', $id, $post, $network);
        $id = is_numeric($filtered) ? (int) $filtered : $id;
        return $id > 0 && self::isImage($id) ? $id : 0;
    }

    private static function firstInContent(\WP_Post $post): int
    {
        if (preg_match('/wp-image-(\d+)/', $post->post_content, $m) === 1 && self::isImage((int) $m[1])) {
            return (int) $m[1];
        }
        $attached = get_attached_media('image', $post->ID);
        foreach ($attached as $attachment) {
            if ($attachment instanceof \WP_Post) {
                return $attachment->ID;
            }
        }
        return 0;
    }

    private static function isImage(int $id): bool
    {
        $mime = get_post_mime_type($id);
        return is_string($mime) && str_starts_with($mime, 'image/');
    }
}
