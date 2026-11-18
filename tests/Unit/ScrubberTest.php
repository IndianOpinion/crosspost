<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Tests\Unit;

use IndianOpinion\Crosspost\Support\Scrubber;
use IndianOpinion\Crosspost\Value\Credentials;
use PHPUnit\Framework\TestCase;

/**
 * Section 8, acceptance test 75.
 */
final class ScrubberTest extends TestCase
{
    public function testRemovesAKnownTokenFromAnErrorBody(): void
    {
        $s = new Scrubber(['XACCESS1secret7token9value']);
        $out = $s->scrub('{"error":"bad token XACCESS1secret7token9value for user"}');
        self::assertStringNotContainsString('XACCESS1secret7token9value', $out);
        self::assertStringContainsString('[redacted]', $out);
    }

    public function testRemovesKeyValuePairs(): void
    {
        $s = new Scrubber();
        foreach (
            [
            'GET /refresh?grant_type=x&access_token=abc123def&x=1',
            'token=shortvalue',
            '{"refresh_token":"r-123-xyz","ok":1}',
            'Authorization: Bearer abcdefgh12345678',
            'password: hunter22',
            'client_secret=s3cr3tv4lue',
            ] as $input
        ) {
            $out = $s->scrub($input);
            self::assertStringContainsString('[redacted]', $out, $input);
            foreach (['abc123def', 'shortvalue', 'r-123-xyz', 'abcdefgh12345678', 'hunter22', 's3cr3tv4lue'] as $secret) {
                self::assertStringNotContainsString($secret, $out);
            }
        }
    }

    public function testRemovesLongRandomStrings(): void
    {
        $random = 'aZ3' . bin2hex(random_bytes(24));
        $out = (new Scrubber())->scrub('upstream said ' . $random . ' and gave up');
        self::assertSame('upstream said [redacted] and gave up', $out);
    }

    public function testKeepsOrdinaryText(): void
    {
        self::assertSame('Rate limit exceeded: try later', (new Scrubber())->scrub('Rate limit exceeded: try later'));
    }

    public function testCutsTo255BytesOnAUtf8Boundary(): void
    {
        $text = str_repeat('क', 200); // 3 bytes each
        $out = (new Scrubber())->scrub($text);
        self::assertLessThanOrEqual(255, strlen($out));
        self::assertTrue(mb_check_encoding($out, 'UTF-8'));
        self::assertSame(255, strlen($out));
        $emoji = str_repeat('a', 253) . '😀';
        $cut = Scrubber::cut($emoji);
        self::assertSame(253, strlen($cut));
        self::assertTrue(mb_check_encoding($cut, 'UTF-8'));
    }

    public function testCredentialsNeverPrintTheirValues(): void
    {
        $creds = new Credentials('x', ['access_token' => 'XACCESS1secret7token9value', 'client_id' => 'abc']);
        self::assertSame('[redacted]', (string) $creds);
        self::assertStringNotContainsString('XACCESS1', print_r($creds, true));
        self::assertStringNotContainsString('XACCESS1', var_export($creds->presence(), true));
        self::assertStringNotContainsString('XACCESS1', (string) json_encode(['c' => $creds]));
        self::assertSame(['XACCESS1secret7token9value'], $creds->secrets());
        self::assertSame(['access_token' => true, 'client_id' => true], $creds->presence());
    }
}
