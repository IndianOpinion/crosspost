<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Tests\Feature;

use IndianOpinion\Crosspost\Network;
use IndianOpinion\Crosspost\Store\Job;
use IndianOpinion\Crosspost\Tests\Stubs\State;
use IndianOpinion\Crosspost\Tests\Support\FakeHttp;
use IndianOpinion\Crosspost\Tests\TestCase;

/**
 * Section 4.5, acceptance tests 22 to 28.
 */
final class OutcomeTest extends TestCase
{
    private function queued(string $network): Job
    {
        $this->connect($network);
        $this->settings(['networks' => [$network => ['spacing' => 0]]]);
        $this->app->confirmPreview($network, 1);
        $post = $this->post();
        $this->publish($post);
        $this->clock->advance(61);
        return $this->job($post->ID, $network);
    }

    public function testSuccessStoresRemoteIdAndUrl(): void
    {
        $job = $this->queued(Network::MASTODON);
        $this->mastodonPosts('9001');
        $this->app->run();
        $done = $this->store->job($job->id);
        self::assertSame(Job::POSTED, $done?->state);
        self::assertSame('9001', $done?->remoteId);
        self::assertSame('https://social.example/statuses/9001', $done?->remoteUrl);
        self::assertSame($this->clock->now(), $done?->postedAt);
        self::assertCount(1, State::actionsNamed('crosspost_posted'));
    }

    public function testRetryableOutcomesReturnToPendingAtTheRightTime(): void
    {
        $job = $this->queued(Network::MASTODON);
        $this->http->on('POST', '#statuses$#', FakeHttp::error('cURL error 7: Failed to connect to social.example port 443: Connection refused'));
        $this->app->run();
        $after = $this->store->job($job->id);
        self::assertSame(Job::PENDING, $after?->state);
        self::assertSame($this->clock->now() + 300, $after?->nextAttemptAt);

        $this->clock->advance(300);
        $this->http->on('POST', '#statuses$#', FakeHttp::json(429, ['error' => 'slow down'], ['Retry-After' => '7200']));
        $this->app->run();
        $after = $this->store->job($job->id);
        self::assertSame(Job::PENDING, $after?->state);
        self::assertSame($this->clock->now() + 7200, $after?->nextAttemptAt, 'Retry-After wins when later than the backoff.');
    }

    public function testServerErrorOnAnUploadIsRetryableBeforeSend(): void
    {
        $job = $this->queued(Network::BLUESKY);
        $this->settings(['networks' => ['bluesky' => ['image_failure' => 'retry']]]);
        $this->app->confirmPreview(Network::BLUESKY, 1);
        $id = ImageTest::attachment(100, 100, 'Alt');
        $job->attachmentId = $id;
        $this->store->updateJob($job);
        $this->http->on('POST', '#uploadBlob$#', FakeHttp::json(500, ['error' => 'InternalServerError']));
        $this->app->run();
        self::assertSame(Job::PENDING, $this->store->job($job->id)?->state);
        self::assertSame([], FakeHttp::requests('#createRecord#'));
    }

    public function testReadTimeoutOnTheFinalCallIsUncertainWithNoSecondCreate(): void
    {
        $job = $this->queued(Network::MASTODON);
        $this->http->on('POST', '#statuses$#', FakeHttp::error('cURL error 28: Operation timed out after 15001 milliseconds with 0 bytes received'));
        $this->app->run();
        $after = $this->store->job($job->id);
        self::assertSame(Job::UNCERTAIN, $after?->state);
        self::assertCount(1, FakeHttp::requests('#statuses$#', 'POST'));
        self::assertCount(1, State::actionsNamed('crosspost_uncertain'));
    }

    public function testPermanentErrorsFailWithoutRetry(): void
    {
        $job = $this->queued(Network::MASTODON);
        $this->http->on('POST', '#statuses$#', FakeHttp::json(422, ['error' => 'Validation failed: Text character limit of 500 exceeded']));
        $this->app->run();
        $after = $this->store->job($job->id);
        self::assertSame(Job::FAILED, $after?->state);
        self::assertStringContainsString('character limit', $after?->reason ?? '');
        $this->clock->advance(86400);
        $this->app->run();
        self::assertCount(1, FakeHttp::requests('#statuses$#', 'POST'));
    }

    public function testAuthErrorHoldsTheJobMarksTheNetworkAndNotifiesOncePerDay(): void
    {
        $this->settings(['notify_email' => 'hello@indianopinion.org']);
        $job = $this->queued(Network::MASTODON);
        $this->http->on('POST', '#statuses$#', FakeHttp::json(401, ['error' => 'The access token is invalid']), 2);
        // Mastodon refresh re-reads the instance configuration once.
        $this->http->on('GET', '#/api/v2/instance$#', FakeHttp::json(200, []));
        $this->app->run();
        $after = $this->store->job($job->id);
        self::assertSame(Job::HELD, $after?->state);
        self::assertSame(Job::R_AUTH, $after?->reason);
        self::assertSame('attention', $this->app->accountRecord(Network::MASTODON)?->status);
        self::assertCount(1, State::$mail);
        self::assertCount(1, State::actionsNamed('crosspost_network_needs_attention'));

        // Further jobs make no call, and no second notice the same day.
        $post = $this->post();
        $this->publish($post);
        $this->clock->advance(61);
        $this->app->run();
        self::assertSame(Job::R_AUTH, $this->job($post->ID, Network::MASTODON)->reason);
        self::assertCount(2, FakeHttp::requests('#statuses$#', 'POST'), 'One call, one retry after refresh.');
        $this->app->notifier->attention(Network::MASTODON, '42', 'again', \IndianOpinion\Crosspost\Settings::load());
        self::assertCount(1, State::$mail);
        self::assertCount(1, $this->app->notifier->notices());
    }

    public function testRetriesExhaustedAfterTheBackoffSchedule(): void
    {
        $job = $this->queued(Network::MASTODON);
        $this->http->on('POST', '#statuses$#', FakeHttp::json(503, ['error' => 'down'], []), 1);
        $this->http->on('POST', '#statuses$#', FakeHttp::error('cURL error 6: Could not resolve host: social.example'), 10);
        // The first 503 on the final call is uncertain; resolve it as absent so retries continue.
        $this->app->run();
        self::assertSame(Job::UNCERTAIN, $this->store->job($job->id)?->state);
        $reset = $this->store->job($job->id);
        self::assertNotNull($reset);
        $reset->state = Job::PENDING;
        $reset->attempts = 0;
        $this->store->updateJob($reset);
        $steps = [300, 900, 3600, 14400, 43200];
        for ($i = 0; $i < 6; $i++) {
            $current = $this->store->job($job->id);
            self::assertNotNull($current);
            $this->clock->time = max($this->clock->time, $current->nextAttemptAt);
            $this->settings(['max_queue_age' => 7 * 86400]);
            $this->app->run();
            $after = $this->store->job($job->id);
            if ($i < 5) {
                self::assertSame(Job::PENDING, $after?->state, 'attempt ' . ($i + 1));
                self::assertSame($this->clock->now() + $steps[$i], $after?->nextAttemptAt);
            }
        }
        $final = $this->store->job($job->id);
        self::assertSame(Job::FAILED, $final?->state);
        self::assertSame(Job::R_RETRIES, $final?->reason);
        self::assertSame(6, $final?->attempts, 'One try plus five retries.');
    }

    public function testJobHeldPastTheQueueAgeBecomesStale(): void
    {
        $this->connect(Network::MASTODON);
        $this->settings(['networks' => ['mastodon' => ['cap' => 0]]]);
        $post = $this->post();
        $this->publish($post);
        $this->clock->advance(61);
        $this->app->run();
        self::assertSame(Job::R_CAP, $this->job($post->ID, Network::MASTODON)->reason);
        $this->clock->advance(86400);
        $this->app->run();
        self::assertSame(Job::SKIPPED, $this->job($post->ID, Network::MASTODON)->state);
        self::assertSame(Job::R_STALE, $this->job($post->ID, Network::MASTODON)->reason);
    }
}
