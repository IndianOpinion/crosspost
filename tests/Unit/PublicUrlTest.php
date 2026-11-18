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
}
