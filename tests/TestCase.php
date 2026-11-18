<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Tests;

use IndianOpinion\Crosspost\Admin\Actions;
use IndianOpinion\Crosspost\Adapter\MastodonAdapter;
use IndianOpinion\Crosspost\Crosspost;
use IndianOpinion\Crosspost\CredentialStore;
use IndianOpinion\Crosspost\Media\PublicUrl;
use IndianOpinion\Crosspost\Network;
use IndianOpinion\Crosspost\Notifier;
use IndianOpinion\Crosspost\Plugin;
use IndianOpinion\Crosspost\Settings;
use IndianOpinion\Crosspost\Store\Job;
use IndianOpinion\Crosspost\Tests\Stubs\State;
use IndianOpinion\Crosspost\Tests\Support\FakeHttp;
use IndianOpinion\Crosspost\Tests\Support\FrozenClock;
use IndianOpinion\Crosspost\Tests\Support\MemoryStore;
use IndianOpinion\Crosspost\Text\Graphemes;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /** Secrets used across the suite; no log row may contain one. */
    public const SECRETS = [
        'x' => ['access_token' => 'XACCESS1secret7token9value', 'refresh_token' => 'XREFRESH2secret8token0value'],
        'threads' => ['access_token' => 'THREADS3secret9long1token', 'app_secret' => 'THREADSAPP4secret0value'],
        'bluesky' => ['app_password' => 'abcd-efgh-ijkl-mnop', 'refresh_jwt' => 'BSKYREFRESH5secret1jwt'],
        'mastodon' => ['access_token' => 'MASTO6secret2token3value'],
    ];

    protected MemoryStore $store;
    protected FrozenClock $clock;
    protected Crosspost $app;
    protected FakeHttp $http;
    /** @var list<string> */
    protected array $missing = [];
    private int $nextPost = 100;

    protected function setUp(): void
    {
        State::reset();
        Graphemes::forceFallback(null);
        $this->store = new MemoryStore();
        $this->clock = new FrozenClock();
        $this->missing = [];
        $this->app = new Crosspost(
            $this->store,
            $this->clock,
            $this->clock,
            fn (): array => $this->missing,
            new PublicUrl(static fn (string $host): array => str_contains($host, 'intranet') ? ['10.0.0.5'] : ['93.184.216.34']),
        );
        Plugin::setApp($this->app);
        $this->http = new FakeHttp();
        Actions::$terminate = static function (): void {
        };
    }

    protected function tearDown(): void
    {
        Plugin::setApp(null);
        Actions::$terminate = null;
        Graphemes::forceFallback(null);
        foreach ($this->store->log as $entry) {
            foreach (self::SECRETS as $secrets) {
                foreach ($secrets as $secret) {
                    self::assertStringNotContainsString($secret, $entry->message, 'A log row contains a test secret.');
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $fields
     */
    protected function post(array $fields = []): \WP_Post
    {
        $id = $this->nextPost++;
        $date = gmdate('Y-m-d H:i:s', $this->clock->now());
        $post = new \WP_Post($fields + [
            'ID' => $id,
            'post_title' => 'Monsoon reaches Kerala early',
            'post_content' => '<p>The monsoon arrived three days ahead of the usual date. Farmers welcomed the rain.</p>',
            'post_status' => 'draft',
            'post_type' => 'post',
            'post_date' => $date,
            'post_date_gmt' => $date,
            'post_name' => 'monsoon-' . $id,
        ]);
        State::$posts[$post->ID] = $post;
        return $post;
    }

    protected function publish(\WP_Post $post, string $from = 'draft'): void
    {
        $post->post_status = 'publish';
        $this->app->onTransition('publish', $from, $post);
    }

    /**
     * @param array<string, mixed> $values
     */
    protected function settings(array $values): void
    {
        $current = get_option(Settings::OPTION, []);
        update_option(Settings::OPTION, array_replace_recursive(is_array($current) ? $current : [], $values), true);
    }

    /**
     * Connect, verify, confirm and switch on a network. With $reviewed,
     * the first post review is already done.
     */
    protected function connect(string $network, bool $reviewed = true): void
    {
        $now = $this->clock->now();
        $values = match ($network) {
            Network::X => ['client_id' => 'xclient', 'token_expires_at' => $now + 7200, 'user_id' => '900'] + self::SECRETS['x'],
            Network::THREADS => ['app_id' => '555', 'token_expires_at' => $now + 50 * 86400, 'user_id' => '1789'] + self::SECRETS['threads'],
            Network::BLUESKY => ['identifier' => 'news.example', 'service' => 'https://pds.example', 'did' => 'did:plc:abc', 'access_jwt' => self::jwt($now + 3600), 'access_exp' => $now + 3600] + self::SECRETS['bluesky'],
            default => ['instance' => 'https://social.example', 'account_id' => '42'] + self::SECRETS['mastodon'],
        };
        $accountId = match ($network) {
            Network::X => '900',
            Network::THREADS => '1789',
            Network::BLUESKY => 'did:plc:abc',
            default => '42',
        };
        $store = new CredentialStore();
        $creds = new \IndianOpinion\Crosspost\Value\Credentials($network, $values);
        $values += [
            'verified_account' => $accountId,
            'verified_name' => 'Example News',
            'verified_at' => $now,
            'verified_fp' => $store->fingerprint($creds),
            'account_id' => $accountId,
        ];
        update_option(CredentialStore::OPTION_PREFIX . $network, $values, false);
        $this->settings(['networks' => [$network => ['enabled' => true]]]);
        $this->app->review->confirm($network, 1, $now, Settings::load()->outputHash($network));
        $account = Notifier::fresh($network, $accountId, $now);
        $account->displayName = 'Example News';
        $account->lastPostedAt = $reviewed ? $now - 3600 : 0;
        $this->store->saveAccount($account);
        if ($network === Network::MASTODON) {
            set_transient(MastodonAdapter::CONFIG_TRANSIENT, MastodonAdapter::parseConfig([]), 86400);
        }
    }

    public static function jwt(int $exp): string
    {
        $part = static fn (array $data): string => rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');
        return $part(['alg' => 'none']) . '.' . $part(['exp' => $exp, 'scope' => 'test']) . '.sig';
    }

    protected function job(int $postId, string $network): Job
    {
        $job = $this->store->jobFor($postId, $network);
        self::assertNotNull($job, 'No job for ' . $network);
        return $job;
    }

    /**
     * Make a job due now.
     */
    protected function due(Job $job): void
    {
        $job->nextAttemptAt = $this->clock->now();
        $this->store->updateJob($job);
    }

    // Standard success responses.

    protected function xPosts(string $id = '1850000000000000001', int $times = 1): void
    {
        $this->http->on('POST', '#api\.x\.com/2/tweets$#', FakeHttp::json(201, ['data' => ['id' => $id, 'text' => 't']]), $times);
    }

    protected function mastodonPosts(string $id = '111', int $times = 1): void
    {
        $this->http->on('POST', '#/api/v1/statuses$#', FakeHttp::json(200, ['id' => $id, 'url' => 'https://social.example/statuses/' . $id]), $times);
    }

    protected function blueskyPosts(int $times = 1): void
    {
        $this->http->on('POST', '#createRecord$#', function (array $args): array {
            $body = json_decode((string) $args['body'], true);
            return FakeHttp::json(200, ['uri' => 'at://did:plc:abc/app.bsky.feed.post/' . $body['rkey'], 'cid' => 'bafy']);
        }, $times);
    }

    protected function threadsPosts(string $id = '18000001'): void
    {
        $this->http->on('POST', '#/1789/threads$#', FakeHttp::json(200, ['id' => 'C1']));
        $this->http->on('GET', '#/C1\?fields=status#', FakeHttp::json(200, ['status' => 'FINISHED']));
        $this->http->on('POST', '#threads_publish$#', FakeHttp::json(200, ['id' => $id]));
        $this->http->on('GET', '#/' . $id . '\?fields=permalink#', FakeHttp::json(200, ['permalink' => 'https://www.threads.net/t/abc']));
    }
}
