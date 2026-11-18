<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Tests\Text;

use IndianOpinion\Crosspost\Adapter\BlueskyAdapter;
use IndianOpinion\Crosspost\Adapter\MastodonAdapter;
use IndianOpinion\Crosspost\Adapter\ThreadsAdapter;
use IndianOpinion\Crosspost\Adapter\XAdapter;
use IndianOpinion\Crosspost\Tests\Support\FakeHttp;
use IndianOpinion\Crosspost\Tests\TestCase;
use IndianOpinion\Crosspost\Text\Graphemes;
use IndianOpinion\Crosspost\Text\XWeightedCount;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Case 50, acceptance test 44: each network's own counting rule.
 */
final class CountingTest extends TestCase
{
    /**
     * Fixtures for the published X counting rules. If X changes its rules,
     * these are the cases to update first.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function xFixtures(): array
    {
        return [
            'ascii' => ['Hello world', 11],
            'link counts 23' => ['Read https://news.example/a/very/long/path/that/goes/on/and/on?x=1', 5 + 23],
            'two links' => ['https://a.example https://b.example/path', 23 + 1 + 23],
            'CJK counts 2' => ['日本語', 6],
            'Devanagari counts 1' => ['नमस्ते', 6],
            'emoji counts 2' => ['🇮🇳', 2],
            'emoji with modifier counts 2' => ['👍🏽', 2],
            'family sequence counts 2' => ['👨‍👩‍👧', 2],
            'mixed' => ['日本 news https://x.example 👍', 4 + 6 + 23 + 1 + 2],
            'smart quotes are light' => ["\u{201C}quoted\u{201D}", 8],
        ];
    }

    #[DataProvider('xFixtures')]
    public function testXWeightedCount(string $text, int $expected): void
    {
        self::assertSame($expected, XWeightedCount::count($text));
    }

    public function testXLimitIs280(): void
    {
        $x = new XAdapter($this->app->http, $this->clock, $this->clock);
        self::assertSame(280, $x->limits()->textLimit);
        self::assertSame(280, $x->count(str_repeat('a', 280)));
        self::assertSame(280, $x->count(str_repeat('日', 140)));
    }

    public function testThreadsCountsCharactersUpTo500(): void
    {
        $threads = new ThreadsAdapter($this->app->http, $this->clock, $this->clock);
        self::assertSame(500, $threads->limits()->textLimit);
        self::assertSame(500, $threads->count(str_repeat('ab', 250)));
        self::assertSame(3, $threads->count('é日a'));
    }

    public function testBlueskyCountsGraphemesWithEmojiAndConjuncts(): void
    {
        $bsky = new BlueskyAdapter($this->app->http, $this->clock, $this->clock);
        self::assertSame(300, $bsky->limits()->textLimit);
        // क्ष is one conjunct cluster; the family emoji is one cluster.
        self::assertSame(1, $bsky->count('क्ष'));
        self::assertSame(1, $bsky->count('👨‍👩‍👧'));
        self::assertSame(3, $bsky->count('a🇮🇳b'));
        self::assertSame(300, $bsky->count(str_repeat('👍🏽', 300)));
    }

    public function testGraphemeFallbackMatchesIntl(): void
    {
        $samples = ['क्षत्रिय', '👨‍👩‍👧 family', 'a🇮🇳b', 'plain'];
        $intl = array_map([Graphemes::class, 'count'], $samples);
        Graphemes::forceFallback(true);
        self::assertFalse(Graphemes::usingIntl());
        self::assertSame($intl, array_map([Graphemes::class, 'count'], $samples));
    }

    public function testMastodonCountsLinksAs23AndReadsTheInstanceLimit(): void
    {
        $this->http->on('GET', '#social\.example/api/v2/instance$#', FakeHttp::json(200, [
            'configuration' => [
                'statuses' => ['max_characters' => 1000, 'characters_reserved_per_url' => 23],
                'media_attachments' => ['image_size_limit' => 8388608, 'description_limit' => 1500],
            ],
        ]));
        $masto = new MastodonAdapter($this->app->http, $this->clock, $this->clock);
        self::assertSame(500, $masto->limits()->textLimit, 'Default before the instance says otherwise.');
        $creds = new \IndianOpinion\Crosspost\Value\Credentials('mastodon', ['instance' => 'https://social.example', 'access_token' => 'MASTO6secret2token3value']);
        self::assertNull($masto->refresh($creds));
        self::assertSame(1000, $masto->limits()->textLimit);
        self::assertSame(8388608, $masto->limits()->maxBytes);
        $long = 'https://news.example/' . str_repeat('x', 200);
        self::assertSame(5 + 23, $masto->count('Read ' . $long));
        // Cached for a day: no second request.
        $masto->refresh($creds);
        self::assertCount(1, FakeHttp::requests('#instance#'));
    }
}
