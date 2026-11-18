<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Tests\Unit;

use IndianOpinion\Crosspost\Media\PublicUrl;
use PHPUnit\Framework\TestCase;

/**
 * Case 68: Threads image URLs must be public HTTPS on public addresses.
 */
final class PublicUrlTest extends TestCase
{
    public function testRejectsPrivateAndNonHttpsUrls(): void
    {
        $check = new PublicUrl(static fn (string $host): array => match ($host) {
            'cdn.example' => ['93.184.216.34'],
            'intranet.example' => ['192.168.1.20'],
            'v6.example' => ['fd00::1'],
            default => [],
        });
        self::assertSame('', $check->problem('https://cdn.example/a.jpg'));
        self::assertSame('not HTTPS', $check->problem('http://cdn.example/a.jpg'));
        self::assertSame('host resolves to a private address', $check->problem('https://intranet.example/a.jpg'));
        self::assertSame('host resolves to a private address', $check->problem('https://v6.example/a.jpg'));
        self::assertSame('host resolves to a private address', $check->problem('https://127.0.0.1/a.jpg'));
        self::assertSame('host resolves to a private address', $check->problem('https://169.254.169.254/latest'));
        self::assertSame('host is not public', $check->problem('https://localhost/a.jpg'));
        self::assertSame('host does not resolve', $check->problem('https://nowhere.example/a.jpg'));
        self::assertSame('URL carries credentials', $check->problem('https://u:p@cdn.example/a.jpg'));
    }
    public function testIpv6AddressesCarryingAPrivateIpv4AddressAreRefused(): void
    {
        foreach (['::ffff:127.0.0.1', '::ffff:10.0.0.1', '64:ff9b::7f00:1', '2002:7f00:1::1', '::127.0.0.1', '198.18.0.1', '224.0.0.1', 'ff02::1'] as $ip) {
            self::assertFalse(PublicUrl::isPublic($ip), $ip);
        }
        foreach (['93.184.216.34', '::ffff:93.184.216.34', '2606:4700::1111'] as $ip) {
            self::assertTrue(PublicUrl::isPublic($ip), $ip);
        }
        $check = new PublicUrl(static fn (string $host): array => ['::ffff:169.254.169.254']);
        self::assertSame('host resolves to a private address', $check->problem('https://mapped.example/a.jpg'));
    }
}
