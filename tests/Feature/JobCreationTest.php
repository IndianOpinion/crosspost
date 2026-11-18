<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Tests\Feature;

use IndianOpinion\Crosspost\Crosspost;
use IndianOpinion\Crosspost\Network;
use IndianOpinion\Crosspost\Store\Job;
use IndianOpinion\Crosspost\Tests\Stubs\State;
use IndianOpinion\Crosspost\Tests\TestCase;

/**
 * Sections 4.2 and 4.3, acceptance tests 1 to 14 and 48.
 */
final class JobCreationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->connect(Network::MASTODON);
        $this->connect(Network::BLUESKY);
    }

    public function testPublishCreatesOneJobPerEnabledNetworkAndResavingCreatesNothing(): void
    {
        $post = $this->post();
        $this->publish($post);
        self::assertSame(Job::PENDING, $this->job($post->ID, Network::MASTODON)->state);
        self::assertSame(Job::PENDING, $this->job($post->ID, Network::BLUESKY)->state);
        self::assertSame(Job::SKIPPED, $this->job($post->ID, Network::X)->state);
        self::assertCount(4, $this->store->jobs);

        $this->app->onTransition('publish', 'publish', $post);
        self::assertCount(4, $this->store->jobs);
        self::assertSame([], State::$requests);
    }

    public function testScheduledPostIsAnnouncedWhenItGoesLive(): void
    {
        $post = $this->post(['post_status' => 'future']);
        $this->app->onTransition('future', 'draft', $post);
        self::assertSame([], $this->store->jobs);
        $this->publish($post, 'future');
        self::assertSame(Job::PENDING, $this->job($post->ID, Network::MASTODON)->state);
    }

    public function testPostTypesOutsideTheListCreateNoJobs(): void
    {
        $this->publish($this->post(['post_type' => 'page']));
        $this->publish($this->post(['post_type' => 'product']));
        self::assertSame([], $this->store->jobs);

        add_filter('crosspost_post_types', static fn (array $types): array => [...$types, 'product']);
        $product = $this->post(['post_type' => 'product']);
        $this->publish($product);
        self::assertSame(Job::PENDING, $this->job($product->ID, Network::MASTODON)->state);
    }

    public function testPasswordProtectedPostIsSkipped(): void
    {
        $post = $this->post(['post_password' => 'secret']);
        $this->publish($post);
        $job = $this->job($post->ID, Network::MASTODON);
        self::assertSame(Job::SKIPPED, $job->state);
        self::assertSame(Job::R_PASSWORD, $job->reason);
    }

    public function testOldPostIsSkippedAsTooOld(): void
    {
        $date = gmdate('Y-m-d H:i:s', $this->clock->now() - 3 * 86400);
        $post = $this->post(['post_date_gmt' => $date, 'post_date' => $date]);
        $this->publish($post);
        self::assertSame(Job::R_TOO_OLD, $this->job($post->ID, Network::MASTODON)->reason);
        self::assertSame(Job::SKIPPED, $this->job($post->ID, Network::MASTODON)->state);
    }

    public function testOptOutBeforeAndAfterPublish(): void
    {
        $before = $this->post();
        update_post_meta($before->ID, Crosspost::OPT_OUT_META, '1');
        $this->publish($before);
        self::assertSame(Job::R_OPTED_OUT, $this->job($before->ID, Network::MASTODON)->reason);

        $after = $this->post();
        $this->publish($after);
        self::assertSame(Job::PENDING, $this->job($after->ID, Network::MASTODON)->state);
        $this->app->setOptOut($after->ID, true);
        self::assertSame(Job::CANCELLED, $this->job($after->ID, Network::MASTODON)->state);
        self::assertSame(Job::R_OPTED_OUT, $this->job($after->ID, Network::MASTODON)->reason);
    }

    public function testUnpublishAndRepublishNeverCreatesASecondJob(): void
    {
        $post = $this->post();
        $this->publish($post);
        $job = $this->job($post->ID, Network::MASTODON);
        $job->state = Job::POSTED;
        $this->store->updateJob($job);

        $post->post_status = 'draft';
        $this->app->onTransition('draft', 'publish', $post);
        $this->publish($post);
        $post->post_title = 'New title';
        $post->post_name = 'new-slug';
        $this->app->onTransition('trash', 'publish', $post);
        $this->publish($post, 'trash');

        self::assertCount(4, $this->store->jobs);
        self::assertSame(Job::POSTED, $this->job($post->ID, Network::MASTODON)->state);

        // The store refuses a duplicate (post, network) whatever the caller.
        $dup = new Job();
        $dup->postId = $post->ID;
        $dup->network = Network::MASTODON;
        self::assertNull($this->store->insertJob($dup));
    }

    public function testNetworkOffAtPublishIsSkippedAndNotRevived(): void
    {
        $post = $this->post();
        $this->publish($post);
        $x = $this->job($post->ID, Network::X);
        self::assertSame(Job::SKIPPED, $x->state);
        self::assertSame(Job::R_NETWORK_OFF, $x->reason);

        $this->connect(Network::X);
        $this->clock->advance(120);
        $this->mastodonPosts();
        $this->blueskyPosts();
        $this->app->run();
        self::assertSame(Job::SKIPPED, $this->job($post->ID, Network::X)->state);
        self::assertSame([], \IndianOpinion\Crosspost\Tests\Support\FakeHttp::requests('#api\.x\.com#'));
    }

    public function testEveryNetworkOffCreatesNothingAndSchedulesNothing(): void
    {
        $this->settings(['networks' => ['mastodon' => ['enabled' => false], 'bluesky' => ['enabled' => false]]]);
        $this->publish($this->post());
        self::assertSame([], $this->store->jobs);
        self::assertSame([], State::$events);
    }

    public function testRestAndCliPublishingTakeTheSamePath(): void
    {
        // The trigger is the status transition, whatever made it.
        foreach (['rest', 'cli', 'xmlrpc'] as $source) {
            $post = $this->post(['post_title' => 'From ' . $source]);
            $this->publish($post, 'auto-draft');
            self::assertSame(Job::PENDING, $this->job($post->ID, Network::MASTODON)->state);
        }
        $private = $this->post();
        $this->publish($private, 'private');
        self::assertSame(Job::PENDING, $this->job($private->ID, Network::MASTODON)->state);
    }

    public function testVetoFilterReasonIsLogged(): void
    {
        add_filter('crosspost_should_post', static fn ($ok, \WP_Post $p, string $n) => $n === Network::MASTODON ? 'sponsored content' : $ok, 10, 3);
        $post = $this->post();
        $this->publish($post);
        $job = $this->job($post->ID, Network::MASTODON);
        self::assertSame(Job::SKIPPED, $job->state);
        self::assertSame('sponsored content', $job->reason);
        self::assertSame(Job::PENDING, $this->job($post->ID, Network::BLUESKY)->state);
        self::assertSame('sponsored content', $this->store->log(['job_id' => $job->id])[0]->message);
    }

    public function testBulkPublishCreatesRowsWithoutRequests(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->publish($this->post());
        }
        self::assertCount(200, $this->store->jobs);
        self::assertSame([], State::$requests);
        $runs = array_filter(State::$events, static fn (array $e): bool => $e['hook'] === Crosspost::RUN_HOOK);
        self::assertCount(1, $runs);
    }

    public function testQueuedTextIsFrozenAtCreation(): void
    {
        $post = $this->post();
        $this->publish($post);
        $before = $this->job($post->ID, Network::MASTODON)->text;
        $post->post_title = 'Completely different title';
        $this->app->onTransition('publish', 'publish', $post);
        self::assertSame($before, $this->job($post->ID, Network::MASTODON)->text);
        self::assertStringContainsString('Monsoon reaches Kerala early', $before);
    }

    public function testPostTrashedBeforeItsTurnIsNeverSent(): void
    {
        $post = $this->post();
        $this->publish($post);
        // Removed from under the queue without a status transition.
        unset(State::$posts[$post->ID]);
        $this->clock->advance(120);
        $this->app->run();
        self::assertSame(Job::SKIPPED, $this->job($post->ID, Network::MASTODON)->state);
        self::assertSame(Job::R_POST_GONE, $this->job($post->ID, Network::MASTODON)->reason);
        self::assertSame([], State::$requests);

        // A trash transition cancels unsent jobs directly.
        $other = $this->post();
        $this->publish($other);
        $other->post_status = 'trash';
        $this->app->onTransition('trash', 'publish', $other);
        self::assertSame(Job::CANCELLED, $this->job($other->ID, Network::MASTODON)->state);
    }

    public function testPublishMakesNoRequestAndSchedulesOneDelayedEvent(): void
    {
        $this->publish($this->post());
        self::assertSame([], State::$requests);
        self::assertCount(1, State::$events);
        self::assertSame(Crosspost::RUN_HOOK, State::$events[0]['hook']);
        self::assertSame($this->clock->now() + 60, State::$events[0]['time']);
    }

    public function testUtmParametersOnlyWhenTurnedOnForThatNetwork(): void
    {
        $this->settings(['networks' => ['mastodon' => ['utm' => true, 'utm_campaign' => 'launch']]]);
        $this->app->confirmPreview(Network::MASTODON, 1);
        $post = $this->post();
        $this->publish($post);
        self::assertStringContainsString('utm_source=mastodon&utm_medium=social&utm_campaign=launch', $this->job($post->ID, Network::MASTODON)->text);
        self::assertStringNotContainsString('utm_', $this->job($post->ID, Network::BLUESKY)->text);
    }

    public function testManualExcerptWinsAndHtmlIsStripped(): void
    {
        $this->settings(['networks' => ['mastodon' => ['template' => "{title}: {excerpt}\n{url}"]]]);
        $this->app->confirmPreview(Network::MASTODON, 1);
        $post = $this->post(['post_title' => 'Rain &amp; <em>wind</em>', 'post_excerpt' => 'Hand <b>written</b> [gallery] summary.']);
        $this->publish($post);
        self::assertStringStartsWith('Rain & wind: Hand written summary.', $this->job($post->ID, Network::MASTODON)->text);
    }
}
