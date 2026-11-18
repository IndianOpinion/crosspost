<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Tests\Feature;

use IndianOpinion\Crosspost\Admin\Actions;
use IndianOpinion\Crosspost\Admin\OAuth;
use IndianOpinion\Crosspost\Admin\PostPanel;
use IndianOpinion\Crosspost\Admin\Rest;
use IndianOpinion\Crosspost\Cli\Command;
use IndianOpinion\Crosspost\CredentialStore;
use IndianOpinion\Crosspost\Network;
use IndianOpinion\Crosspost\Settings;
use IndianOpinion\Crosspost\Store\Job;
use IndianOpinion\Crosspost\Tests\Stubs\State;
use IndianOpinion\Crosspost\Tests\Support\FakeHttp;
use IndianOpinion\Crosspost\Tests\TestCase;

/**
 * Sections 4.10 and 5.7, acceptance tests 52, 65, 67, 68 and 76.
 */
final class AdminTest extends TestCase
{
    public function testEveryAdminActionWithoutNonceOrCapabilityIsRefused(): void
    {
        foreach (array_keys(Actions::ACTIONS) as $action) {
            // No nonce.
            State::$caps = ['manage_options' => true, 'publish_posts' => true, 'edit_post' => true];
            $_REQUEST = ['network' => 'mastodon'];
            $this->assertDies(fn () => Actions::handle($action), $action . ' without a nonce');

            // Valid nonce, no capability.
            State::$caps = [];
            $_REQUEST = ['_wpnonce' => wp_create_nonce($action), 'network' => 'mastodon'];
            $this->assertDies(fn () => Actions::handle($action), $action . ' without the capability');
        }
        // Wrong action's nonce.
        State::$caps = ['manage_options' => true];
        $_REQUEST = ['_wpnonce' => wp_create_nonce('crosspost_verify')];
        $this->assertDies(fn () => Actions::handle('crosspost_kill_switch'), 'nonce for another action');
        self::assertSame([], State::$requests);
        self::assertFalse(Settings::load()->killSwitch());

        // The REST routes check edit_post; "post now" also publish_posts.
        State::$caps = [];
        self::assertFalse(Rest::canEdit(new \WP_REST_Request(['id' => 5])));

        // The classic meta box save ignores a request without its nonce.
        State::$caps = ['edit_post' => true];
        $_POST = ['crosspost_opt_out' => '1'];
        PostPanel::save(7, new \WP_Post(['ID' => 7]));
        self::assertSame([], State::$meta[7] ?? []);
    }

    public function testAValidActionRuns(): void
    {
        $_REQUEST = $_POST = ['_wpnonce' => wp_create_nonce('crosspost_kill_switch'), 'on' => '1'];
        Actions::handle('crosspost_kill_switch');
        self::assertTrue(Settings::load()->killSwitch());
        self::assertNotSame('', State::$redirect);
    }

    public function testSavingSettingsCannotSwitchANetworkOnOrSaveDirectVisibility(): void
    {
        $_REQUEST = $_POST = [
            '_wpnonce' => wp_create_nonce('crosspost_save_settings'),
            'crosspost' => [
                'kill_switch' => '1',
                'networks' => ['mastodon' => ['enabled' => '1', 'visibility' => 'direct', 'cap' => '7', 'language' => 'hi<script>']],
            ],
        ];
        Actions::handle('crosspost_save_settings');
        $settings = Settings::load();
        self::assertFalse($settings->enabled('mastodon'));
        self::assertFalse($settings->killSwitch());
        self::assertSame('public', $settings->visibility('mastodon'));
        self::assertSame(7, $settings->cap('mastodon'));
        self::assertSame('hiscript', $settings->language('mastodon'));
    }

    public function testLanguageAndVisibilityAreSent(): void
    {
        $this->connect(Network::MASTODON);
        $this->connect(Network::BLUESKY);
        update_option('WPLANG', 'hi_IN');
        $this->settings(['networks' => ['mastodon' => ['visibility' => 'unlisted']]]);
        $this->app->confirmPreview(Network::MASTODON, 1);
        $post = $this->post();
        $this->publish($post);
        $this->clock->advance(61);
        $this->mastodonPosts();
        $this->blueskyPosts();
        $this->app->run();
        $status = json_decode((string) FakeHttp::requests('#statuses$#', 'POST')[0]['body'], true);
        self::assertSame('unlisted', $status['visibility']);
        self::assertSame('hi', $status['language']);
        $record = json_decode((string) FakeHttp::requests('#createRecord$#')[0]['body'], true);
        self::assertSame(['hi-IN'], $record['record']['langs']);
    }

    public function testOAuthCallbackWithAWrongOrExpiredStateIsRefused(): void
    {
        update_option(CredentialStore::OPTION_PREFIX . 'x', ['client_id' => 'xclient'], false);
        OAuth::start('x');
        self::assertStringStartsWith('https://x.com/i/oauth2/authorize?', State::$redirect);
        parse_str((string) parse_url(State::$redirect, PHP_URL_QUERY), $query);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertSame('tweet.read tweet.write users.read offline.access', $query['scope']);
        $state = (string) $query['state'];

        $_GET = ['state' => 'forged', 'code' => 'abc'];
        $this->assertDies(fn () => OAuth::callback(), 'wrong state');
        // The state works once: it was consumed by the failed attempt.
        $_GET = ['state' => $state, 'code' => 'abc'];
        $this->assertDies(fn () => OAuth::callback(), 'state reused');

        OAuth::start('x');
        parse_str((string) parse_url(State::$redirect, PHP_URL_QUERY), $query);
        $this->clock->advance(601);
        $_GET = ['state' => (string) $query['state'], 'code' => 'abc'];
        $this->assertDies(fn () => OAuth::callback(), 'expired state');

        // Another user's state does not match this user's.
        OAuth::start('x');
        parse_str((string) parse_url(State::$redirect, PHP_URL_QUERY), $query);
        State::$userId = 2;
        $_GET = ['state' => (string) $query['state'], 'code' => 'abc'];
        $this->assertDies(fn () => OAuth::callback(), 'other user');

        // Without the capability.
        State::$userId = 1;
        State::$caps = [];
        $this->assertDies(fn () => OAuth::callback(), 'no capability');
        self::assertSame([], State::$requests);
    }

    public function testOAuthCallbackWithTheRightStateConnects(): void
    {
        update_option(CredentialStore::OPTION_PREFIX . 'x', ['client_id' => 'xclient'], false);
        OAuth::start('x');
        parse_str((string) parse_url(State::$redirect, PHP_URL_QUERY), $query);
        $this->http->on('POST', '#oauth2/token$#', function (array $args): array {
            self::assertSame('authorization_code', $args['body']['grant_type']);
            self::assertNotSame('', $args['body']['code_verifier']);
            return FakeHttp::json(200, ['access_token' => 'XACCESS1secret7token9value', 'refresh_token' => 'XREFRESH2secret8token0value', 'expires_in' => 7200]);
        });
        $this->http->on('GET', '#2/users/me$#', FakeHttp::json(200, ['data' => ['id' => '900', 'name' => 'Example News', 'username' => 'news']]));
        $_GET = ['state' => (string) $query['state'], 'code' => 'abc'];
        OAuth::callback();
        $creds = $this->app->credentials->get('x');
        self::assertTrue($this->app->credentials->isVerified($creds));
        self::assertSame('900', $creds->get('verified_account'));
    }

    public function testSettingsPreviewMakesNoWriteCallAndConfirmRecordsUserTimeAndHash(): void
    {
        $this->connect(Network::BLUESKY);
        $a = $this->post(['post_status' => 'publish']);
        $b = $this->post(['post_status' => 'publish', 'post_title' => 'Second']);
        State::$timeline = [];
        $preview = $this->app->networkPreview(Network::BLUESKY);
        self::assertCount(2, $preview);
        self::assertSame([], State::$requests);
        self::assertSame([], State::$timeline, 'No option written.');
        self::assertSame([], $this->store->jobs);

        State::$userId = 3;
        $this->clock->advance(10);
        $this->app->confirmPreview(Network::BLUESKY, 3);
        $record = $this->app->review->get(Network::BLUESKY);
        self::assertSame(['user' => 3, 'time' => $this->clock->now(), 'hash' => Settings::load()->outputHash(Network::BLUESKY)], $record);
        self::assertFalse(State::$autoload[\IndianOpinion\Crosspost\Review::OPTION_PREFIX . 'bluesky']);
    }

    public function testPostPanelAndCliPreviewShowTheSameTextWithoutRequests(): void
    {
        $this->connect(Network::MASTODON);
        $image = ImageTest::attachment(100, 100, 'Harbour at dawn');
        $post = $this->post(['post_status' => 'publish']);
        update_post_meta($post->ID, '_thumbnail_id', $image);

        $panel = Rest::panel(new \WP_REST_Request(['id' => $post->ID]))->data;
        $mastodon = $panel['networks'][0]['preview'];

        \WP_CLI::$out = [];
        (new Command($this->app))->preview([(string) $post->ID], ['network' => 'mastodon']);
        $out = implode("\n", \WP_CLI::$out);
        self::assertStringContainsString($mastodon['text'], $out);
        self::assertStringContainsString(sprintf('Length: %d of %d', $mastodon['count'], $mastodon['limit']), $out);
        self::assertStringContainsString('Image: ' . $image . ' alt: Harbour at dawn', $out);
        self::assertSame($image, $mastodon['image']);
        self::assertSame([], State::$requests);
        self::assertSame([], $this->store->jobs);

        // A preview needs no credentials at all.
        delete_option(CredentialStore::OPTION_PREFIX . 'mastodon');
        self::assertSame($mastodon['text'], crosspost_preview($post->ID, 'mastodon')['text']);
    }

    public function testPublicApiFunctions(): void
    {
        $this->connect(Network::MASTODON);
        $post = $this->post();
        $this->publish($post);
        $status = crosspost_status($post->ID);
        self::assertSame(Job::PENDING, $status['mastodon']['state']);
        self::assertFalse(crosspost_kill_switch());
        $this->app->cancel($status['mastodon']['job'], 1);
        $id = crosspost_queue($post->ID, 'mastodon');
        self::assertSame($status['mastodon']['job'], $id, 'The (post, network) row is reused.');
        self::assertSame(Job::PENDING, $this->store->job($id)?->state);
        $this->expectException(\IndianOpinion\Crosspost\Exception\ConfigurationException::class);
        crosspost_queue($post->ID, 'mastodon');
    }

    public function testCliCommands(): void
    {
        $this->connect(Network::MASTODON, false);
        $post = $this->post();
        $this->publish($post);
        $job = $this->job($post->ID, Network::MASTODON);
        $cli = new Command($this->app);
        \WP_CLI::$out = [];
        $cli->queue([], ['state' => 'held']);
        self::assertStringContainsString('reason=first post review', implode("\n", \WP_CLI::$out));
        try {
            $cli->send([(string) $job->id], []);
            self::fail('send must ask for confirmation');
        } catch (\WP_CLI_Error $e) {
            self::assertSame('confirmation needed', $e->getMessage());
        }
        $cli->send([(string) $job->id], ['yes' => '1']);
        self::assertSame(Job::PENDING, $this->store->job($job->id)?->state);
        $cli->killSwitch(['on'], []);
        self::assertTrue(Settings::load()->killSwitch());
        \WP_CLI::$out = [];
        $cli->run([], []);
        self::assertStringContainsString('Stopped: kill switch', implode("\n", \WP_CLI::$out));
        $cli->killSwitch(['off'], []);
        \WP_CLI::$out = [];
        $cli->log([], ['post' => (string) $post->ID]);
        self::assertStringContainsString('event=released', implode("\n", \WP_CLI::$out));
        $cli->cancel([(string) $job->id], []);
        self::assertSame(Job::CANCELLED, $this->store->job($job->id)?->state);
    }

    private function assertDies(callable $fn, string $label): void
    {
        try {
            $fn();
        } catch (\WPDieException $e) {
            self::assertGreaterThanOrEqual(400, $e->getCode(), $label);
            return;
        }
        self::fail('Not refused: ' . $label);
    }
}
