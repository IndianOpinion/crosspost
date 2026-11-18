<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Tests\Feature;

use IndianOpinion\Crosspost\Network;
use IndianOpinion\Crosspost\Store\Job;
use IndianOpinion\Crosspost\Tests\Stubs\State;
use IndianOpinion\Crosspost\Tests\Support\FakeHttp;
use IndianOpinion\Crosspost\Tests\TestCase;

/**
 * Cases 31 to 36, acceptance tests 29 to 34, and ruling 1 for X.
 */
final class ReconcileTest extends TestCase
{
    private function uncertain(string $network, string $timeout = 'cURL error 28: Operation timed out after 15000 milliseconds with 0 bytes received'): Job
    {
        $this->connect($network);
        $this->settings(['networks' => [$network => ['spacing' => 0]]]);
        $this->app->confirmPreview($network, 1);
        $post = $this->post();
        $this->publish($post);
        $this->clock->advance(61);
        return $this->job($post->ID, $network);
    }

    public function testMastodonResendsWithTheSameIdempotencyKey(): void
    {
        $job = $this->uncertain(Network::MASTODON);
        $this->http->on('POST', '#statuses$#', FakeHttp::error('cURL error 28: Operation timed out after 15000 milliseconds with 0 bytes received'));
        $this->app->run();
        self::assertSame(Job::UNCERTAIN, $this->store->job($job->id)?->state);

        // The server returns the original status for the same key.
        $this->http->on('POST', '#statuses$#', FakeHttp::json(200, ['id' => 'orig-1', 'url' => 'https://social.example/statuses/orig-1']));
        $this->clock->advance(300);
        $this->app->run();
        $calls = FakeHttp::requests('#statuses$#', 'POST');
        self::assertCount(2, $calls);
        self::assertSame($calls[0]['headers']['Idempotency-Key'], $calls[1]['headers']['Idempotency-Key']);
        self::assertSame(\IndianOpinion\Crosspost\Crosspost::idempotencyKey($job->id), $calls[0]['headers']['Idempotency-Key']);
        self::assertSame('orig-1', $this->store->job($job->id)?->remoteId);
    }

    public function testMastodonAfterTheKeyWindowMatchesRecentStatusesInstead(): void
    {
        $job = $this->uncertain(Network::MASTODON);
        $this->http->on('POST', '#statuses$#', FakeHttp::error('cURL error 52: Empty reply from server'));
        $this->app->run();
        $text = $this->store->job($job->id)?->text ?? '';
        $html = '<p>' . htmlspecialchars(explode("\n", $text)[0]) . '</p><p><a href="' . get_permalink($job->postId) . '" rel="nofollow">news.example/monsoon…</a></p>';
        $this->http->on('GET', '#/api/v1/accounts/42/statuses#', FakeHttp::json(200, [['id' => 'late-7', 'url' => 'https://social.example/statuses/late-7', 'content' => $html]]));
        $this->clock->advance(3600);
        $this->app->run();
        self::assertSame(Job::POSTED, $this->store->job($job->id)?->state);
        self::assertSame('late-7', $this->store->job($job->id)?->remoteId);
        self::assertCount(1, FakeHttp::requests('#statuses$#', 'POST'));
    }

    public function testBlueskyFindsItsRecordKeyOrResendsWithTheSameKey(): void
    {
        $job = $this->uncertain(Network::BLUESKY);
        $this->http->on('POST', '#createRecord$#', FakeHttp::json(502, ['error' => 'UpstreamFailure']));
        $this->app->run();
        self::assertSame(Job::UNCERTAIN, $this->store->job($job->id)?->state);

        $this->http->on('GET', '#getRecord#', FakeHttp::json(200, ['uri' => 'at://did:plc:abc/app.bsky.feed.post/' . $job->idempotencyKey, 'cid' => 'c']));
        $this->clock->advance(300);
        $this->app->run();
        self::assertSame(Job::POSTED, $this->store->job($job->id)?->state);
        self::assertStringContainsString('rkey=' . $job->idempotencyKey, FakeHttp::requests('#getRecord#')[0]['url']);
        self::assertCount(1, FakeHttp::requests('#createRecord#'));

        // Absent: resent with the same key.
        $this->clock->advance(600);
        $other = $this->post();
        $this->publish($other);
        $this->clock->advance(61);
        $second = $this->job($other->ID, Network::BLUESKY);
        $this->http->on('POST', '#createRecord$#', FakeHttp::error('cURL error 56: Recv failure: Connection reset by peer'));
        $this->app->run();
        $this->http->on('GET', '#getRecord#', FakeHttp::json(400, ['error' => 'RecordNotFound', 'message' => 'Could not locate record']));
        $this->blueskyPosts();
        $this->clock->advance(300);
        $this->app->run();
        $creates = FakeHttp::requests('#createRecord#');
        self::assertCount(3, $creates);
        self::assertSame($second->idempotencyKey, json_decode((string) $creates[1]['body'], true)['rkey']);
        self::assertSame($second->idempotencyKey, json_decode((string) $creates[2]['body'], true)['rkey']);
        self::assertSame(Job::POSTED, $this->store->job($second->id)?->state);
    }

    public function testThreadsMatchesTheListingOrStaysUncertainThenNeverLanded(): void
    {
        $job = $this->uncertain(Network::THREADS);
        $this->http->on('POST', '#/1789/threads$#', FakeHttp::json(200, ['id' => 'C7']));
        $this->http->on('GET', '#/C7\?fields=status#', FakeHttp::json(200, ['status' => 'FINISHED']));
        $this->http->on('POST', '#threads_publish$#', FakeHttp::error('cURL error 28: Operation timed out after 15000 milliseconds with 0 bytes received'));
        $this->app->run();
        $after = $this->store->job($job->id);
        self::assertSame(Job::UNCERTAIN, $after?->state);
        self::assertSame('C7', $after?->meta['container_id'] ?? '', 'Container id stored on the job.');

        // Listing without the text: still uncertain.
        $this->http->on('GET', '#/me/threads#', FakeHttp::json(200, ['data' => [['id' => '1', 'text' => 'something else']]]));
        $this->clock->advance(300);
        $this->app->run();
        self::assertSame(Job::UNCERTAIN, $this->store->job($job->id)?->state);

        // Listing with the same text (whitespace and entities differ): posted.
        $listed = str_replace("\n\n", "\n \n", (string) $after?->text);
        $this->http->on('GET', '#/me/threads#', FakeHttp::json(200, ['data' => [['id' => 'T55', 'text' => $listed, 'permalink' => 'https://www.threads.net/t/T55']]]));
        $this->clock->advance(300);
        $this->app->run();
        self::assertSame(Job::POSTED, $this->store->job($job->id)?->state);
        self::assertSame('T55', $this->store->job($job->id)?->remoteId);
        self::assertCount(1, FakeHttp::requests('#threads_publish#'));
    }

    public function testThreadsNeverLandedAfterTheWindowWithNoCreateCall(): void
    {
        $job = $this->uncertain(Network::THREADS);
        $job->state = Job::UNCERTAIN;
        $job->sentAt = $job->firstSentAt = $this->clock->now();
        $this->store->updateJob($job);
        $this->http->on('GET', '#/me/threads#', FakeHttp::json(200, ['data' => []]), 100);
        for ($i = 0; $i < 5; $i++) {
            $this->clock->advance(5 * 3600);
            $this->app->run();
        }
        $after = $this->store->job($job->id);
        self::assertSame(Job::FAILED, $after?->state);
        self::assertSame(Job::R_NEVER_LANDED, $after?->reason);
        self::assertSame([], FakeHttp::requests('#threads_publish|/1789/threads$#'));
        self::assertSame(0, $this->store->countSent(Network::THREADS, 0), 'The cap slot is freed.');

        // A person can resend it by hand.
        $this->app->send($job->id, 1);
        self::assertSame(Job::PENDING, $this->store->job($job->id)?->state);
    }

    public function testFailingListingKeepsTheJobUncertainAndNeverPosts(): void
    {
        $job = $this->uncertain(Network::THREADS);
        $job->state = Job::UNCERTAIN;
        $job->sentAt = $job->firstSentAt = $this->clock->now();
        $this->store->updateJob($job);
        $this->http->on('GET', '#/me/threads#', FakeHttp::json(500, ['error' => ['message' => 'oops']]));
        $this->app->run();
        self::assertSame(Job::UNCERTAIN, $this->store->job($job->id)?->state);
        self::assertSame([], FakeHttp::requests('#threads_publish|/1789/threads$#'));
        self::assertStringContainsString('listing failed', $this->store->log(['job_id' => $job->id, 'event' => 'uncertain'])[0]->message);
    }

    public function testOneListingCallCoversEveryUncertainThreadsJob(): void
    {
        $jobs = [];
        $this->connect(Network::THREADS);
        for ($i = 0; $i < 3; $i++) {
            $post = $this->post();
            $this->publish($post);
            $job = $this->job($post->ID, Network::THREADS);
            $job->state = Job::UNCERTAIN;
            $job->sentAt = $job->firstSentAt = $this->clock->now();
            $this->store->updateJob($job);
            $jobs[] = $job;
        }
        $this->http->on('GET', '#/me/threads#', FakeHttp::json(200, ['data' => []]));
        $this->app->run();
        self::assertCount(1, FakeHttp::requests('#/me/threads#'));
    }

    public function testXTimeoutIsUnknownNeverListedNeverResentWithoutAPerson(): void
    {
        $jobs = [];
        $this->connect(Network::X);
        $this->settings(['networks' => ['x' => ['spacing' => 0]]]);
        $this->app->confirmPreview(Network::X, 1);
        for ($i = 0; $i < 3; $i++) {
            $post = $this->post();
            $this->publish($post);
            $this->clock->advance(61);
            $this->http->on('POST', '#api\.x\.com/2/tweets$#', FakeHttp::error('cURL error 28: Operation timed out after 15000 milliseconds with 0 bytes received'));
            $this->app->run();
            $jobs[] = $this->job($post->ID, Network::X);
        }
        foreach ($jobs as $job) {
            self::assertSame(Job::UNKNOWN, $job->state);
        }
        $this->clock->advance(2 * 86400);
        $this->app->run();
        self::assertSame([], FakeHttp::requests('#api\.x\.com/2/users#'));
        self::assertCount(3, FakeHttp::requests('#api\.x\.com/2/tweets$#'));

        // A human click resends.
        $this->app->send($jobs[0]->id, 1);
        $this->http->on('POST', '#oauth2/token$#', FakeHttp::json(200, ['access_token' => 'XACCESS1secret7token9value', 'refresh_token' => 'XREFRESH2secret8token0value', 'expires_in' => 7200]));
        $this->xPosts('42');
        $this->app->run();
        self::assertSame(Job::POSTED, $this->store->job($jobs[0]->id)?->state);
        self::assertSame(Job::UNKNOWN, $this->store->job($jobs[1]->id)?->state);
    }

    public function testThreadsContainerFailureIsRetryableAndPublishTimeoutIsUncertain(): void
    {
        $job = $this->uncertain(Network::THREADS);
        $this->http->on('POST', '#/1789/threads$#', FakeHttp::json(500, ['error' => ['message' => 'container failed', 'code' => 2]]));
        $this->app->run();
        self::assertSame(Job::PENDING, $this->store->job($job->id)?->state);
        self::assertSame([], FakeHttp::requests('#threads_publish#'));

        $this->clock->advance(300);
        $this->http->on('POST', '#/1789/threads$#', FakeHttp::json(200, ['id' => 'C9']));
        $this->http->on('GET', '#/C9\?fields=status#', FakeHttp::json(200, ['status' => 'FINISHED']));
        $this->http->on('POST', '#threads_publish$#', FakeHttp::json(504, ['error' => ['message' => 'gateway timeout']]));
        $this->app->run();
        $after = $this->store->job($job->id);
        self::assertSame(Job::UNCERTAIN, $after?->state);
        self::assertSame('C9', $after?->meta['container_id'] ?? '');
        self::assertSame(['creation_id' => 'C9'], FakeHttp::requests('#threads_publish#')[0]['body']);
        self::assertCount(1, State::actionsNamed('crosspost_uncertain'));
    }
}
