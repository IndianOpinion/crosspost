<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Tests\Feature;

use IndianOpinion\Crosspost\Adapter\Adapter;
use IndianOpinion\Crosspost\Adapter\BlueskyAdapter;
use IndianOpinion\Crosspost\Adapter\MastodonAdapter;
use IndianOpinion\Crosspost\Adapter\ThreadsAdapter;
use IndianOpinion\Crosspost\Adapter\XAdapter;
use IndianOpinion\Crosspost\CredentialStore;
use IndianOpinion\Crosspost\Exception\ConfigurationException;
use IndianOpinion\Crosspost\Exception\CredentialsException;
use IndianOpinion\Crosspost\Network;
use IndianOpinion\Crosspost\Store\Job;
use IndianOpinion\Crosspost\Tests\Stubs\State;
use IndianOpinion\Crosspost\Tests\Support\FakeHttp;
use IndianOpinion\Crosspost\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Section 4.9 and 4.11, acceptance tests 61 to 66 and 71.
 */
final class CredentialsTest extends TestCase
{
    public function testXRefreshStoresTheNewPairInOneWriteBeforeTheNextCall(): void
    {
        $this->connect(Network::X);
        $this->settings(['networks' => ['x' => ['spacing' => 0]]]);
        $this->app->confirmPreview(Network::X, 1);
        $creds = get_option(CredentialStore::OPTION_PREFIX . 'x');
        $creds['token_expires_at'] = $this->clock->now() + 60;
        update_option(CredentialStore::OPTION_PREFIX . 'x', $creds, false);
        $post = $this->post();
        $this->publish($post);
        $this->clock->advance(61);
        $this->http->on('POST', '#oauth2/token$#', FakeHttp::json(200, ['access_token' => 'XACCESS1secret7token9value-new', 'refresh_token' => 'XREFRESH2secret8token0value-new', 'expires_in' => 7200]));
        $this->xPosts();
        State::$timeline = [];
        $this->app->run();

        $timeline = array_values(array_filter(State::$timeline, static fn (string $e): bool => str_contains($e, 'credentials_x') || str_starts_with($e, 'request:')));
        self::assertSame([
            'request:POST https://api.x.com/2/oauth2/token',
            'option:crosspost_credentials_x',
            'request:POST https://api.x.com/2/tweets',
        ], $timeline);
        $stored = get_option(CredentialStore::OPTION_PREFIX . 'x');
        self::assertSame('XACCESS1secret7token9value-new', $stored['access_token']);
        self::assertSame('XREFRESH2secret8token0value-new', $stored['refresh_token']);
        self::assertSame('Bearer XACCESS1secret7token9value-new', FakeHttp::requests('#2/tweets#')[0]['headers']['Authorization']);
        self::assertSame(Job::POSTED, $this->job($post->ID, Network::X)->state);
        self::assertSame(0, (int) ($this->store->log(['event' => 'refresh'])[0]->httpStatus));
    }

    public function testThreadsTokenNearExpiryIsRefreshedAndAFailedRefreshMarksTheNetwork(): void
    {
        $this->connect(Network::THREADS);
        $creds = get_option(CredentialStore::OPTION_PREFIX . 'threads');
        $creds['token_expires_at'] = $this->clock->now() + 5 * 86400;
        update_option(CredentialStore::OPTION_PREFIX . 'threads', $creds, false);
        $adapter = new ThreadsAdapter($this->app->http, $this->clock, $this->clock);
        $this->http->on('GET', '#refresh_access_token\?grant_type=th_refresh_token#', FakeHttp::json(200, ['access_token' => 'THREADS3secret9long1token-2', 'expires_in' => 5184000]));
        $fresh = $adapter->refresh($this->app->credentials->get('threads'));
        self::assertNotNull($fresh);
        self::assertSame('THREADS3secret9long1token-2', $fresh->get('access_token'));
        self::assertSame($this->clock->now() + 5184000, $fresh->int('token_expires_at'));

        // More than 7 days left: no call.
        self::assertNull($adapter->refresh($fresh));

        // A failed refresh in a run marks the network.
        $post = $this->post();
        $this->publish($post);
        $this->clock->advance(61);
        $this->http->on('GET', '#refresh_access_token#', FakeHttp::json(400, ['error' => ['message' => 'Session expired', 'code' => 190]]));
        $this->app->run();
        self::assertSame('attention', $this->app->accountRecord('threads')?->status);
        self::assertSame(Job::R_AUTH, $this->job($post->ID, Network::THREADS)->reason);
        self::assertSame([], FakeHttp::requests('#/1789/threads#'));
    }

    public function testBlueskyRejectsAMainPasswordAndReusesItsSession(): void
    {
        try {
            $this->app->credentials->replace('bluesky', ['identifier' => 'news.example', 'app_password' => 'my-real-password-123']);
            self::fail('A main password shape was accepted.');
        } catch (CredentialsException $e) {
            self::assertStringContainsString('app password', $e->getMessage());
        }

        $adapter = new BlueskyAdapter($this->app->http, $this->clock, $this->clock);
        $this->connect(Network::BLUESKY);
        $creds = $this->app->credentials->get('bluesky');
        // Fresh access token: nothing happens.
        self::assertNull($adapter->refresh($creds));
        self::assertSame([], State::$requests);

        // Near expiry: refresh, not a new session.
        $old = $creds->with(['access_exp' => $this->clock->now() + 60]);
        $this->http->on('POST', '#refreshSession$#', FakeHttp::json(200, ['accessJwt' => self::jwt($this->clock->now() + 7200), 'refreshJwt' => 'BSKYREFRESH5secret1jwt-2', 'did' => 'did:plc:abc']));
        $fresh = $adapter->refresh($old);
        self::assertSame($this->clock->now() + 7200, $fresh?->int('access_exp'));
        self::assertSame([], FakeHttp::requests('#createSession#'));

        // Refresh refused: only now a new session.
        $this->http->on('POST', '#refreshSession$#', FakeHttp::json(400, ['error' => 'ExpiredToken']));
        $this->http->on('POST', '#createSession$#', FakeHttp::json(200, ['accessJwt' => self::jwt($this->clock->now() + 7200), 'refreshJwt' => 'BSKYREFRESH5secret1jwt-3', 'did' => 'did:plc:abc']));
        $again = $adapter->refresh($old);
        self::assertSame('BSKYREFRESH5secret1jwt-3', $again?->get('refresh_jwt'));
        self::assertCount(1, FakeHttp::requests('#createSession#'));
        $body = json_decode((string) FakeHttp::requests('#createSession#')[0]['body'], true);
        self::assertSame('abcd-efgh-ijkl-mnop', $body['password']);
    }

    public function testMastodonInstanceWithoutHttpsIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->app->credentials->replace('mastodon', ['instance' => 'http://social.example', 'access_token' => 'MASTO6secret2token3value']);
    }

    public function testVerifyShowsOnlyAccountIdAndName(): void
    {
        $this->app->credentials->replace('mastodon', ['instance' => 'https://social.example', 'access_token' => 'MASTO6secret2token3value']);
        $this->http->on('GET', '#/api/v2/instance$#', FakeHttp::json(200, []));
        $this->http->on('GET', '#verify_credentials$#', FakeHttp::json(200, ['id' => '42', 'username' => 'news', 'display_name' => 'Example News', 'note' => 'private bio', 'email' => 'x@example.org']));
        $account = $this->app->verify('mastodon');
        self::assertSame('42', $account->id);
        self::assertSame('Example News', $account->name);
        self::assertSame(['id' => '42', 'name' => 'Example News'], get_object_vars($account));
        $status = $this->app->networkStatus()['mastodon'];
        self::assertTrue($status['credentials']);
        self::assertSame('Example News', $status['account']);
        self::assertStringNotContainsString('MASTO6', (string) json_encode($status));
        self::assertSame('Bearer MASTO6secret2token3value', FakeHttp::requests('#verify_credentials#')[0]['headers']['Authorization']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCredentialConstantWinsAndIsNeverPrinted(): void
    {
        define('CROSSPOST_MASTODON_TOKEN', 'MASTO6secret2token3value');
        define('CROSSPOST_MASTODON_INSTANCE', 'https://social.example');
        update_option(CredentialStore::OPTION_PREFIX . 'mastodon', ['instance' => 'https://other.example', 'access_token' => 'stored-token-value'], false);
        $creds = $this->app->credentials->get('mastodon');
        self::assertSame('MASTO6secret2token3value', $creds->get('access_token'));
        self::assertSame('https://social.example', $creds->get('instance'));
        self::assertTrue($creds->fromConstant('access_token'));
        self::assertArrayNotHasKey('access_token', $creds->storable(), 'A constant is never copied into the database.');

        $command = new \IndianOpinion\Crosspost\Cli\Command($this->app);
        \WP_CLI::$out = [];
        $command->status([], []);
        self::assertStringNotContainsString('MASTO6', implode("\n", \WP_CLI::$out));
        self::assertStringContainsString('credentials=yes', implode("\n", \WP_CLI::$out));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testKillSwitchConstantCannotBeTurnedOffByTheOption(): void
    {
        define('CROSSPOST_KILL_SWITCH', true);
        $this->settings(['kill_switch' => false]);
        self::assertTrue(crosspost_kill_switch());
        $this->connect(Network::MASTODON);
        $this->publish($this->post());
        $this->clock->advance(61);
        $this->app->run();
        self::assertSame([], State::$requests);
        $this->expectException(\WP_CLI_Error::class);
        (new \IndianOpinion\Crosspost\Cli\Command($this->app))->killSwitch(['off'], []);
    }

    public function testAdaptersExposeNoWayToEditOrDelete(): void
    {
        $allowed = array_map(static fn (\ReflectionMethod $m): string => $m->getName(), (new \ReflectionClass(Adapter::class))->getMethods());
        self::assertSame(['id', 'label', 'limits', 'count', 'verify', 'prepareMedia', 'publish', 'reconcile', 'refresh'], $allowed);
        foreach ([XAdapter::class, ThreadsAdapter::class, BlueskyAdapter::class, MastodonAdapter::class] as $class) {
            $public = array_map(static fn (\ReflectionMethod $m): string => $m->getName(), (new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC));
            $extra = array_diff($public, $allowed, ['__construct', 'facets', 'jwtExpiry', 'parseConfig']);
            self::assertSame([], array_values($extra), $class);
            foreach ($public as $name) {
                self::assertDoesNotMatchRegularExpression('/delete|remove|edit|update|unpublish|hide|reply|destroy/i', $name, $class);
            }
        }
    }

    public function testNoRequestInTheSuiteUsesADeleteOrEditEndpoint(): void
    {
        // Runs a representative flow per network, then inspects every request.
        foreach (Network::ALL as $network) {
            $this->connect($network);
        }
        $post = $this->post();
        $this->publish($post);
        $this->clock->advance(61);
        $this->xPosts();
        $this->threadsPosts();
        $this->blueskyPosts();
        $this->mastodonPosts();
        $this->app->run();
        self::assertNotEmpty(State::$requests);
        foreach (State::$requests as $request) {
            self::assertContains($request['method'], ['GET', 'POST'], $request['url']);
            self::assertDoesNotMatchRegularExpression('/deleteRecord|putRecord|destroy|\/delete|\/edit/i', $request['url']);
        }

        // Trashing a posted post makes no request.
        State::$requests = [];
        $post->post_status = 'trash';
        $this->app->onTransition('trash', 'publish', $post);
        self::assertSame([], State::$requests);
        self::assertSame(Job::POSTED, $this->job($post->ID, Network::MASTODON)->state);
    }
}
