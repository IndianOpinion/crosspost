<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Tests\Feature;

use IndianOpinion\Crosspost\Crosspost;
use IndianOpinion\Crosspost\Network;
use IndianOpinion\Crosspost\Settings;
use IndianOpinion\Crosspost\Store\Job;
use IndianOpinion\Crosspost\Tests\Stubs\State;
use IndianOpinion\Crosspost\Tests\Support\FakeHttp;
use IndianOpinion\Crosspost\Tests\TestCase;

/**
 * Sections 4.4 to 4.6 and 4.10, acceptance tests 15 to 28, 35 to 40, 47,
 * 69 and 70.
 */
final class RunnerTest extends TestCase
{
    private function queued(string $network = Network::MASTODON): Job
    {
        $post = $this->post();
        $this->publish($post);
        $this->clock->advance(61);
        return $this->job($post->ID, $network);
    }

    public function testSecondRunExitsOnTheLockAndAnExpiredLockIsReplaced(): void
    {
        $this->connect(Network::MASTODON);
        $this->queued();
        $token = $this->store->acquireLock(Crosspost::LOCK, $this->clock->now(), 600);
        self::assertNotNull($token);
        $report = $this->app->run();
        self::assertTrue($report->locked);
        self::assertSame([], State::$requests);

        // A lock left by a dead run expires and is replaced.
        $this->clock->advance(601);
        $this->mastodonPosts();
        $report = $this->app->run();
        self::assertFalse($report->locked);
        self::assertSame(1, $report->posted);
    }

    public function testOneJobPerNetworkPerRunOldestFirstAfterReconciling(): void
    {
        $this->connect(Network::MASTODON);
        $this->connect(Network::BLUESKY);
        $first = $this->queued();
        $this->clock->advance(5);
        $second = $this->queued();
        // An uncertain Bluesky job is reconciled before anything is sent.
        $uncertain = $this->job($first->postId, Network::BLUESKY);
        $uncertain->state = Job::UNCERTAIN;
        $uncertain->sentAt = $uncertain->firstSentAt = $this->clock->now() - 600;
        $this->store->updateJob($uncertain);

        $this->http->on('GET', '#getRecord#', FakeHttp::json(200, ['uri' => 'at://did:plc:abc/app.bsky.feed.post/k', 'cid' => 'c']));
        $this->mastodonPosts();
        $this->blueskyPosts();
        $report = $this->app->run();

        self::assertSame('GET', State::$requests[0]['method']);
        self::assertStringContainsString('getRecord', State::$requests[0]['url']);
        self::assertSame(Job::POSTED, $this->store->job($uncertain->id)?->state);
        self::assertSame(Job::POSTED, $this->store->job($first->id)?->state);
        self::assertSame(Job::PENDING, $this->store->job($second->id)?->state);
        self::assertSame(Job::POSTED, $this->job($second->postId, Network::BLUESKY)->state);
        self::assertSame(1, $report->reconciled);

        // The run-wide limit.
        $this->clock->advance(3600);
        $third = $this->queued();
        $this->mastodonPosts();
        $report = $this->app->run(null, 1);
        self::assertSame(1, $report->sent);
        self::assertSame(Job::POSTED, $this->store->job($second->id)?->state, 'Oldest first.');
        self::assertSame(Job::PENDING, $this->store->job($third->id)?->state);
    }

    public function testKillSwitchDuringARunStopsFurtherRequestsAndLeavesTheJob(): void
    {
        $this->connect(Network::MASTODON);
        $this->connect(Network::BLUESKY);
        $this->queued();
        $this->http->on('POST', '#createRecord$#', function (): array {
            // The kill switch goes on while the first call is in flight.
            $this->settings(['kill_switch' => true]);
            return FakeHttp::json(200, ['uri' => 'at://did:plc:abc/app.bsky.feed.post/k', 'cid' => 'c']);
        });
        $report = $this->app->run();
        self::assertCount(1, State::$requests);
        self::assertSame(Job::R_KILL_SWITCH, $report->stopReason);
        $mastodon = $this->store->jobs(['network' => Network::MASTODON])[0];
        self::assertSame(Job::PENDING, $mastodon->state);
        self::assertSame(0, $mastodon->attempts);

        // With the switch on, a run makes no call at all and schedules nothing.
        State::$events = [];
        $this->app->run();
        self::assertCount(1, State::$requests);
        self::assertSame([], State::$events);
    }

    public function testHttpLayerRefusesEveryCallWhileTheKillSwitchIsOn(): void
    {
        $this->settings(['kill_switch' => true]);
        $response = $this->app->http->request('x', 'verify', 'GET', 'https://api.x.com/2/users/me');
        self::assertSame('blocked', $response->errorKind);
        self::assertSame([], State::$requests);
    }

    public function testCapCountsPostedAndUncertainOverARollingDay(): void
    {
        $this->connect(Network::MASTODON);
        $this->settings(['networks' => ['mastodon' => ['cap' => 2, 'spacing' => 0]]]);
        $this->app->confirmPreview(Network::MASTODON, 1);
        $posted = $this->queued();
        $posted->state = Job::POSTED;
        $posted->sentAt = $this->clock->now() - 7200;
        $this->store->updateJob($posted);
        $uncertain = $this->queued();
        $uncertain->state = Job::UNCERTAIN;
        $uncertain->sentAt = $this->clock->now() - 3600;
        $uncertain->firstSentAt = $uncertain->sentAt;
        $this->store->updateJob($uncertain);
        $next = $this->queued();

        $this->http->on('GET', '#/api/v1/accounts/42/statuses#', FakeHttp::json(200, []));
        $this->app->run();
        $held = $this->store->job($next->id);
        self::assertSame(Job::HELD, $held?->state);
        self::assertSame(Job::R_CAP, $held?->reason);
        self::assertSame($posted->sentAt + 86400, $held?->nextAttemptAt);
        self::assertSame([], FakeHttp::requests('#statuses$#', 'POST'));
    }

    public function testStuckSendingBecomesUncertainNeverPending(): void
    {
        $this->connect(Network::MASTODON);
        $job = $this->queued();
        $job->state = Job::SENDING;
        $job->sentAt = $this->clock->now() - 1200;
        $this->store->updateJob($job);
        // The same key is resent inside the idempotency window.
        $this->mastodonPosts('77');
        $this->app->run();
        $events = $this->store->events($job->id);
        self::assertContains('uncertain', $events);
        self::assertNotContains('retry', $events);
        self::assertSame(Job::POSTED, $this->store->job($job->id)?->state);
    }

    public function testStuckXJobBecomesUnknownAndWaitsForAPerson(): void
    {
        $this->connect(Network::X);
        $job = $this->queued(Network::X);
        $job->state = Job::SENDING;
        $job->sentAt = $this->clock->now() - 1200;
        $this->store->updateJob($job);
        $this->app->run();
        self::assertSame(Job::UNKNOWN, $this->store->job($job->id)?->state);
        self::assertSame([], State::$requests);
    }

    public function testSlowImagePollStopsAtTheRunBudget(): void
    {
        $this->connect(Network::MASTODON);
        $this->connect(Network::BLUESKY);
        $this->settings(['run_budget' => 20, 'networks' => ['mastodon' => ['image_failure' => 'retry']]]);
        $this->app->confirmPreview(Network::MASTODON, 1);
        $image = \IndianOpinion\Crosspost\Tests\Feature\ImageTest::attachment(200, 200, 'A sunrise');
        $post = $this->post();
        update_post_meta($post->ID, '_thumbnail_id', $image);
        $this->publish($post);
        $bluesky = $this->job($post->ID, Network::BLUESKY);
        $bluesky->createdAt += 10; // Mastodon goes first.
        $this->store->updateJob($bluesky);
        $this->clock->advance(61);

        $this->http->on('POST', '#/api/v2/media$#', FakeHttp::json(202, ['id' => 'm1', 'url' => null]));
        $this->http->on('GET', '#/api/v1/media/m1$#', FakeHttp::json(206, ['id' => 'm1']), 100);
        $report = $this->app->run();
        self::assertSame('run budget', $report->stopReason);
        self::assertSame(Job::PENDING, $this->job($post->ID, Network::MASTODON)->state, 'Image failure with retry policy.');
        self::assertSame(0, $this->store->job($bluesky->id)?->attempts, 'Left for the next run.');
        self::assertSame([], FakeHttp::requests('#pds\.example#'));
    }

    public function testEmptyQueueSchedulesNothing(): void
    {
        $this->connect(Network::MASTODON);
        $this->queued();
        $this->mastodonPosts();
        State::$events = [];
        $this->app->run();
        self::assertSame([], State::$events);
    }

    public function testCapOfZeroHoldsEverything(): void
    {
        $this->connect(Network::MASTODON);
        $this->settings(['networks' => ['mastodon' => ['cap' => 0]]]);
        $job = $this->queued();
        $this->app->run();
        self::assertSame(Job::HELD, $this->store->job($job->id)?->state);
        self::assertSame(Job::R_CAP, $this->store->job($job->id)?->reason);
        self::assertSame([], State::$requests);
    }

    public function testSpacingLetsOneGoAndTheOtherWait(): void
    {
        $this->connect(Network::MASTODON);
        $a = $this->queued();
        $b = $this->queued();
        $this->mastodonPosts('1');
        $this->app->run();
        $this->clock->advance(60);
        $this->app->run();
        self::assertSame(Job::POSTED, $this->store->job($a->id)?->state);
        $waiting = $this->store->job($b->id);
        self::assertSame(Job::HELD, $waiting?->state);
        self::assertSame(Job::R_SPACING, $waiting?->reason);
        self::assertSame($this->store->job($a->id)?->sentAt + 600, $waiting?->nextAttemptAt);
        self::assertNotContains('failed', $this->store->events($b->id));

        $this->clock->advance(600);
        $this->mastodonPosts('2');
        $this->app->run();
        self::assertSame(Job::POSTED, $this->store->job($b->id)?->state);
    }

    public function testCooldownAfter429BlocksTheNetworkUntilTheResetTime(): void
    {
        $this->connect(Network::MASTODON);
        $this->settings(['networks' => ['mastodon' => ['spacing' => 0]]]);
        $this->app->confirmPreview(Network::MASTODON, 1);
        $a = $this->queued();
        $reset = gmdate('Y-m-d\TH:i:s\Z', $this->clock->now() + 1800);
        $this->http->on('POST', '#/api/v1/statuses$#', FakeHttp::json(429, ['error' => 'Too many requests'], ['X-RateLimit-Reset' => $reset]));
        $this->app->run();
        $job = $this->store->job($a->id);
        self::assertSame(Job::PENDING, $job?->state);
        self::assertSame($this->clock->now() + 1800, $job?->nextAttemptAt);

        $b = $this->queued();
        $this->clock->advance(600);
        $this->app->run();
        self::assertSame(Job::R_COOLDOWN, $this->store->job($b->id)?->reason);
        self::assertCount(1, FakeHttp::requests('#statuses$#', 'POST'));
    }

    public function testPostNowAndBackfillObeyTheCap(): void
    {
        $this->connect(Network::MASTODON);
        $this->settings(['networks' => ['mastodon' => ['cap' => 1, 'spacing' => 0]]]);
        $this->app->confirmPreview(Network::MASTODON, 1);
        $first = $this->queued();
        $this->mastodonPosts();
        $this->app->run();
        self::assertSame(Job::POSTED, $this->store->job($first->id)?->state);

        $old = $this->post(['post_status' => 'publish', 'post_date_gmt' => gmdate('Y-m-d H:i:s', $this->clock->now() - 5 * 86400)]);
        $manual = $this->app->postNow($old->ID, Network::MASTODON, 1);
        $this->app->run();
        self::assertSame(Job::R_CAP, $this->store->job($manual->id)?->reason);

        $older = $this->post(['post_status' => 'publish', 'post_date_gmt' => gmdate('Y-m-d H:i:s', $this->clock->now() - 4 * 86400)]);
        $dry = $this->app->backfill($this->clock->now() - 10 * 86400, Network::MASTODON, 10, false);
        self::assertSame([$older->ID], array_column($dry, 'post_id'));
        self::assertNull($this->store->jobFor($older->ID, Network::MASTODON), 'Dry run writes nothing.');
        $this->app->backfill($this->clock->now() - 10 * 86400, Network::MASTODON, 10, true);
        $this->app->run();
        self::assertSame(Job::R_CAP, $this->job($older->ID, Network::MASTODON)->reason);
        self::assertCount(1, FakeHttp::requests('#statuses$#', 'POST'));
    }

    public function testCapAboveTheCeilingIsStoredAsTheCeiling(): void
    {
        $stored = Settings::sanitize(['networks' => ['x' => ['cap' => 500]]], []);
        self::assertSame(Network::CEILING['x'], $stored['networks']['x']['cap']);
        $this->settings(['networks' => ['x' => ['cap' => 999]]]);
        self::assertSame(Network::CEILING['x'], Settings::load()->cap('x'));
    }

    public function testTextMadeTooLongIsRefusedAtSend(): void
    {
        $this->connect(Network::MASTODON);
        // A filter that blows the limit is caught at creation...
        add_filter('crosspost_text', static fn (string $t): string => $t . str_repeat(' padding', 100));
        $post = $this->post();
        $this->publish($post);
        self::assertSame(Job::R_TOO_LONG, $this->job($post->ID, Network::MASTODON)->reason);
        // ...and a stored text over the limit is refused at the point of sending.
        remove_all_filters('crosspost_text');
        $job = $this->queued();
        $job->text = str_repeat('word ', 200);
        $this->store->updateJob($job);
        $this->app->run();
        self::assertSame(Job::FAILED, $this->store->job($job->id)?->state);
        self::assertSame(Job::R_TOO_LONG, $this->store->job($job->id)?->reason);
        self::assertSame([], State::$requests);
    }

    public function testTemplateChangeAfterConfirmationHoldsNewJobsOnly(): void
    {
        $this->connect(Network::MASTODON);
        $before = $this->queued();
        $this->settings(['networks' => ['mastodon' => ['template' => "{title} {url}"]]]);
        $after = $this->queued();
        self::assertSame(Job::PENDING, $this->store->job($before->id)?->state);
        self::assertSame(Job::HELD, $this->store->job($after->id)?->state);
        self::assertSame(Job::R_PREVIEW, $this->store->job($after->id)?->reason);
        self::assertStringContainsString("\n\n", $this->store->job($before->id)?->text ?? '');

        $this->app->confirmPreview(Network::MASTODON, 1);
        self::assertSame($this->clock->now(), $this->store->job($after->id)?->nextAttemptAt, 'Confirming wakes held jobs.');
    }

    public function testFirstPostOnANewNetworkWaitsForReview(): void
    {
        $this->connect(Network::MASTODON, false);
        $this->settings(['networks' => ['mastodon' => ['spacing' => 0]]]);
        $this->app->confirmPreview(Network::MASTODON, 1);
        $first = $this->queued();
        self::assertSame(Job::R_FIRST_POST, $this->store->job($first->id)?->reason);
        $second = $this->queued();
        $this->app->run();
        self::assertSame(Job::HELD, $this->store->job($second->id)?->state);
        self::assertSame([], State::$requests);

        $this->app->send($first->id, 1);
        $this->mastodonPosts('1');
        $this->app->run();
        self::assertSame(Job::POSTED, $this->store->job($first->id)?->state);

        $this->app->wake(Network::MASTODON, [Job::R_FIRST_POST]);
        $this->mastodonPosts('2');
        $this->app->run();
        self::assertSame(Job::POSTED, $this->store->job($second->id)?->state, 'Later jobs flow without review.');

        // After a long pause the review applies again.
        $this->clock->advance(91 * 86400);
        $later = $this->queued();
        self::assertSame(Job::R_FIRST_POST, $this->store->job($later->id)?->reason);
    }

    public function testNetworkCannotBeSwitchedOnWithoutVerifiedCredentialsAndAConfirmedPreview(): void
    {
        try {
            $this->app->setEnabled(Network::BLUESKY, true);
            self::fail('Switched on without credentials.');
        } catch (\IndianOpinion\Crosspost\Exception\ConfigurationException $e) {
            self::assertStringContainsString('connect the account first', $e->getMessage());
        }
        $this->app->credentials->replace(Network::BLUESKY, ['identifier' => 'news.example', 'app_password' => 'abcd-efgh-ijkl-mnop']);
        self::assertSame('verify the credentials first', $this->app->cannotEnable(Network::BLUESKY));
        $this->http->on('POST', '#createSession$#', FakeHttp::json(200, ['accessJwt' => self::jwt($this->clock->now() + 3600), 'refreshJwt' => 'BSKYREFRESH5secret1jwt', 'did' => 'did:plc:abc', 'handle' => 'news.example']));
        $this->http->on('GET', '#getSession$#', FakeHttp::json(200, ['did' => 'did:plc:abc', 'handle' => 'news.example']));
        $this->app->verify(Network::BLUESKY);
        self::assertSame('view and confirm the preview first', $this->app->cannotEnable(Network::BLUESKY));
        $this->app->confirmPreview(Network::BLUESKY, 1);
        $this->app->setEnabled(Network::BLUESKY, true);
        self::assertTrue(Settings::load()->enabled(Network::BLUESKY));
        $review = $this->app->review->get(Network::BLUESKY);
        self::assertSame(1, $review['user']);
        self::assertSame($this->clock->now(), $review['time']);
        self::assertSame(Settings::load()->outputHash(Network::BLUESKY), $review['hash']);
    }
    public function testOnlyAnAdministratorsPostNowStandsInForTheFirstPostReview(): void
    {
        $this->connect(Network::MASTODON, false);
        $author = $this->post(['post_status' => 'publish']);
        State::$caps = ['publish_posts' => true, 'edit_post' => true];
        $job = $this->app->postNow($author->ID, Network::MASTODON, 1);
        self::assertSame(Job::HELD, $job->state);
        self::assertSame(Job::R_FIRST_POST, $job->reason, "An author's post now waits for the review.");

        // A cancelled job rebuilt by code running without a user (cron, CLI) waits too.
        $this->app->cancel($job->id, 1);
        $rebuilt = $this->app->postNow($author->ID, Network::MASTODON, 0);
        self::assertSame(Job::R_FIRST_POST, $rebuilt->reason);

        State::$caps = ['manage_options' => true, 'publish_posts' => true, 'edit_post' => true];
        $admin = $this->post(['post_status' => 'publish']);
        self::assertSame(Job::PENDING, $this->app->postNow($admin->ID, Network::MASTODON, 1)->state);
    }

    public function testJobsHeldBehindTheFirstPostFlowOnceItIsLive(): void
    {
        $this->connect(Network::MASTODON, false);
        $this->settings(['networks' => ['mastodon' => ['spacing' => 0]]]);
        $first = $this->queued();
        $second = $this->queued();
        self::assertSame(Job::R_FIRST_POST, $this->store->job($second->id)?->reason);
        $this->app->send($first->id, 1);
        $this->mastodonPosts('1');
        $this->app->run();
        self::assertSame(Job::POSTED, $this->store->job($first->id)?->state);
        self::assertSame($this->clock->now(), $this->store->job($second->id)?->nextAttemptAt, 'Woken without a manual step.');
    }

    public function testReconciliationMakesNoCallDuringACooldown(): void
    {
        $this->connect(Network::MASTODON);
        $this->settings(['networks' => ['mastodon' => ['spacing' => 0]]]);
        $job = $this->queued();
        $this->http->on('POST', '#statuses$#', FakeHttp::error('cURL error 28: Operation timed out after 15000 milliseconds with 0 bytes received'));
        $this->app->run();
        self::assertSame(Job::UNCERTAIN, $this->store->job($job->id)?->state);

        $account = $this->app->accountRecord(Network::MASTODON);
        self::assertNotNull($account);
        $account->cooldownUntil = $this->clock->now() + 3600;
        $this->store->saveAccount($account);
        $this->clock->advance(400);
        $before = count(State::$requests);
        $this->app->run();
        self::assertCount($before, State::$requests, 'No same-key resend while the network cools down.');
        self::assertSame(Job::UNCERTAIN, $this->store->job($job->id)?->state);
        self::assertSame($account->cooldownUntil, $this->store->job($job->id)?->nextAttemptAt);
    }

    public function testKillSwitchDuringAThreadsContainerWaitLeavesTheJob(): void
    {
        $this->connect(Network::THREADS);
        $job = $this->queued(Network::THREADS);
        $this->http->on('POST', '#/1789/threads$#', function (): array {
            $this->settings(['kill_switch' => true]);
            return FakeHttp::json(200, ['id' => 'C1']);
        });
        $report = $this->app->run();
        self::assertSame(Job::R_KILL_SWITCH, $report->stopReason);
        $after = $this->store->job($job->id);
        self::assertSame(Job::PENDING, $after?->state);
        self::assertSame(0, $after?->attempts);
        self::assertSame([], FakeHttp::requests('#threads_publish#'));
    }
}
