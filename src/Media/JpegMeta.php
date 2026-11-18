<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Media;

/**
 * Removes metadata segments (EXIF, XMP, comments) from a JPEG byte string
 * without decoding the image.
 */
final class JpegMeta
{
    public static function strip(string $bytes): string
    {
        if (strlen($bytes) < 4 || substr($bytes, 0, 2) !== "\xFF\xD8") {
            return $bytes;
        }
        $out = "\xFF\xD8";
        $pos = 2;
        $length = strlen($bytes);
        while ($pos + 4 <= $length) {
            if ($bytes[$pos] !== "\xFF") {
                return $bytes;
            }
            $marker = ord($bytes[$pos + 1]);
            if ($marker === 0xDA) {
                // Start of scan: the rest is image data.
                return $out . substr($bytes, $pos);
            }
            $size = (ord($bytes[$pos + 2]) << 8) | ord($bytes[$pos + 3]);
            if ($size < 2 || $pos + 2 + $size > $length) {
                return $bytes;
            }
            // APP1 to APP15 and COM carry metadata. APP0 (JFIF) stays.
            $drop = ($marker >= 0xE1 && $marker <= 0xEF) || $marker === 0xFE;
            if (!$drop) {
                $out .= substr($bytes, $pos, 2 + $size);
            }
            $pos += 2 + $size;
        }
        return $bytes;
    }

    public static function hasExif(string $bytes): bool
    {
        $pos = 2;
        $length = strlen($bytes);
        while ($pos + 4 <= $length && $bytes[$pos] === "\xFF") {
            $marker = ord($bytes[$pos + 1]);
            if ($marker === 0xDA) {
                return false;
            }
            $size = (ord($bytes[$pos + 2]) << 8) | ord($bytes[$pos + 3]);
            if ($marker === 0xE1 && substr($bytes, $pos + 4, 4) === 'Exif') {
                return true;
            }
            $pos += 2 + $size;
        }
        return false;
    }
}
