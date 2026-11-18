<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Support;

/**
 * Timestamp identifiers as the AT Protocol defines them: 64 bits (top bit
 * zero, 53 bits of microseconds since the Unix epoch, 10 bits of clock id)
 * written as 13 characters of sortable base32.
 */
final class Tid
{
    public const ALPHABET = '234567abcdefghijklmnopqrstuvwxyz';

    public static function generate(int $micros, ?int $clockId = null): string
    {
        $clockId ??= random_int(0, 1023);
        $value = (($micros & 0x1FFFFFFFFFFFFF) << 10) | ($clockId & 0x3FF);
        $out = '';
        for ($i = 0; $i < 13; $i++) {
            $out = self::ALPHABET[$value & 0x1F] . $out;
            $value >>= 5;
        }
        return $out;
    }

    public static function isValid(string $tid): bool
    {
        return preg_match('/^[234567abcdefghij][234567abcdefghijklmnopqrstuvwxyz]{12}$/', $tid) === 1;
    }
}
