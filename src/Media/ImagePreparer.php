<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Media;

use IndianOpinion\Crosspost\Text\Graphemes;
use IndianOpinion\Crosspost\Text\PlainText;
use IndianOpinion\Crosspost\Value\Limits;
use IndianOpinion\Crosspost\Value\MediaFile;

/**
 * Makes an attachment fit a network: the smallest registered size that is
 * big enough, or a resized, re-encoded JPEG copy in a temporary file. The
 * media library is never changed.
 */
final class ImagePreparer
{
    /** @var list<int> */
    public const QUALITIES = [85, 75, 65];
    public const MIN_EDGE = 320;
    public const EDGE_STEP = 0.75;

    public function alt(int $attachmentId, int $limit, string $network): string
    {
        $alt = PlainText::line((string) get_post_meta($attachmentId, '_wp_attachment_image_alt', true));
        if ($alt === '') {
            $caption = wp_get_attachment_caption($attachmentId);
            $alt = PlainText::line(is_string($caption) ? $caption : '');
        }
        $filtered = apply_filters('crosspost_alt_text', $alt, $attachmentId, $network);
        $alt = is_string($filtered) ? PlainText::line($filtered) : $alt;
        if ($limit > 0 && Graphemes::count($alt) > $limit) {
            $alt = Graphemes::head($alt, $limit);
        }
        return $alt;
    }

    /**
     * @return MediaFile|string the prepared image, or the reason it is not sent
     */
    public function prepare(int $attachmentId, Limits $limits, string $network, bool $requireAlt): MediaFile|string
    {
        if (!$limits->acceptsImages()) {
            return 'this network takes no images';
        }
        $path = get_attached_file($attachmentId);
        if (!is_string($path) || $path === '' || !is_readable($path)) {
            return 'image file missing';
        }
        $mime = (string) get_post_mime_type($attachmentId);
        if ($mime === 'image/svg+xml') {
            return 'SVG images are not sent';
        }
        if ($mime === 'image/gif' && self::isAnimatedGif($path)) {
            return 'animated GIF images are not sent';
        }
        if ($mime === 'image/avif' && !in_array($mime, $limits->mimeTypes, true)) {
            return 'AVIF is not accepted by this network';
        }
        $alt = $this->alt($attachmentId, $limits->altLimit, $network);
        if ($alt === '' && $requireAlt) {
            return 'no alt text (require alt text is on)';
        }

        $candidates = $this->candidates($attachmentId, $path, $mime);
        // Smallest size that reaches the preferred edge, else the largest.
        $choice = null;
        foreach ($candidates as $candidate) {
            if (max($candidate['width'], $candidate['height']) >= $limits->preferredEdge) {
                $choice = $candidate;
                break;
            }
        }
        $choice ??= $candidates[count($candidates) - 1];

        if ($this->fits($choice, $limits)) {
            return $this->file($attachmentId, $choice, $alt, false);
        }

        if ($limits->imageTransport === Limits::TRANSPORT_PUBLIC_URL) {
            // Cannot host a re-encoded copy: try smaller registered sizes.
            foreach (array_reverse($candidates) as $candidate) {
                if ($this->fits($candidate, $limits)) {
                    return $this->file($attachmentId, $candidate, $alt, false);
                }
            }
            return 'no size of this image fits a network that fetches images by URL';
        }

        return $this->reencode($attachmentId, $path, $choice, $limits, $alt);
    }

    public function cleanup(?MediaFile $file): void
    {
        if ($file !== null && $file->temporary && is_file($file->path)) {
            wp_delete_file($file->path);
        }
    }

    /**
     * @return list<array{path: string, mime: string, width: int, height: int, size: string}>
     */
    private function candidates(int $attachmentId, string $path, string $mime): array
    {
        $meta = wp_get_attachment_metadata($attachmentId);
        $meta = is_array($meta) ? $meta : [];
        $width = (int) ($meta['width'] ?? 0);
        $height = (int) ($meta['height'] ?? 0);
        if ($width === 0 || $height === 0) {
            $info = getimagesize($path);
            if (is_array($info)) {
                [$width, $height] = [(int) $info[0], (int) $info[1]];
            }
        }
        $out = [];
        $dir = dirname($path);
        foreach (is_array($meta['sizes'] ?? null) ? $meta['sizes'] : [] as $name => $size) {
            if (!is_array($size) || !isset($size['file']) || !is_string($size['file'])) {
                continue;
            }
            $file = $dir . '/' . basename($size['file']);
            if (!is_readable($file)) {
                continue;
            }
            $out[] = [
                'path' => $file,
                'mime' => is_string($size['mime-type'] ?? null) ? $size['mime-type'] : $mime,
                'width' => (int) ($size['width'] ?? 0),
                'height' => (int) ($size['height'] ?? 0),
                'size' => (string) $name,
            ];
        }
        usort($out, static fn (array $a, array $b): int => max($a['width'], $a['height']) <=> max($b['width'], $b['height']));
        $out[] = ['path' => $path, 'mime' => $mime, 'width' => $width, 'height' => $height, 'size' => 'full'];
        return $out;
    }

    /**
     * @param array{path: string, mime: string, width: int, height: int, size: string} $candidate
     */
    private function fits(array $candidate, Limits $limits): bool
    {
        $bytes = filesize($candidate['path']);
        return in_array($candidate['mime'], $limits->mimeTypes, true)
            && $bytes !== false
            && $bytes <= $limits->maxBytes
            && max($candidate['width'], $candidate['height']) <= $limits->maxEdge;
    }

    /**
     * @param array{path: string, mime: string, width: int, height: int, size: string} $candidate
     */
    private function file(int $attachmentId, array $candidate, string $alt, bool $temporary): MediaFile
    {
        $url = '';
        $src = wp_get_attachment_image_src($attachmentId, $candidate['size']);
        if (is_array($src) && isset($src[0]) && is_string($src[0])) {
            $url = $src[0];
        }
        $bytes = filesize($candidate['path']);
        return new MediaFile(
            $attachmentId,
            $candidate['path'],
            $candidate['mime'],
            $bytes === false ? 0 : $bytes,
            $candidate['width'],
            $candidate['height'],
            $alt,
            $temporary,
            $url,
        );
    }

    /**
     * @param array{path: string, mime: string, width: int, height: int, size: string} $choice
     */
    private function reencode(int $attachmentId, string $original, array $choice, Limits $limits, string $alt): MediaFile|string
    {
        $edge = min($limits->maxEdge, max($limits->preferredEdge, 1), max($choice['width'], $choice['height'], 1));
        while ($edge >= self::MIN_EDGE) {
            foreach (self::QUALITIES as $quality) {
                $editor = wp_get_image_editor($original);
                if (is_wp_error($editor)) {
                    return 'the image editor cannot open this file';
                }
                $editor->resize($edge, $edge, false);
                $editor->set_quality($quality);
                $target = self::tempPath();
                $saved = $editor->save($target, 'image/jpeg');
                if (is_wp_error($saved) || !is_array($saved)) {
                    self::remove($target);
                    return 'the image editor could not write a copy';
                }
                $savedPath = is_string($saved['path'] ?? null) ? $saved['path'] : $target;
                $bytes = file_get_contents($savedPath);
                if ($bytes === false) {
                    self::remove($savedPath);
                    continue;
                }
                $stripped = JpegMeta::strip($bytes);
                if ($stripped !== $bytes) {
                    file_put_contents($savedPath, $stripped);
                }
                if (strlen($stripped) <= $limits->maxBytes) {
                    return new MediaFile(
                        $attachmentId,
                        $savedPath,
                        'image/jpeg',
                        strlen($stripped),
                        (int) ($saved['width'] ?? $edge),
                        (int) ($saved['height'] ?? $edge),
                        $alt,
                        true,
                    );
                }
                self::remove($savedPath);
            }
            $edge = (int) floor($edge * self::EDGE_STEP);
        }
        return 'the image could not be made small enough';
    }

    private static function tempPath(): string
    {
        $dir = function_exists('get_temp_dir') ? get_temp_dir() : sys_get_temp_dir();
        return rtrim($dir, '/') . '/crosspost-' . bin2hex(random_bytes(8)) . '.jpg';
    }

    private static function remove(string $path): void
    {
        if (is_file($path)) {
            wp_delete_file($path);
        }
    }

    public static function isAnimatedGif(string $path): bool
    {
        $bytes = file_get_contents($path, false, null, 0, 4 * 1024 * 1024);
        if ($bytes === false) {
            return false;
        }
        // More than one graphic control extension followed by an image descriptor.
        return preg_match_all('/\x00\x21\xF9\x04.{4}\x00(\x2C|\x21)/s', $bytes) > 1;
    }
}
