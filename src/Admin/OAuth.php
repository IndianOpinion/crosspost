<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Admin;

use IndianOpinion\Crosspost\Adapter\XAdapter;
use IndianOpinion\Crosspost\Exception\ConfigurationException;
use IndianOpinion\Crosspost\Exception\CredentialsException;
use IndianOpinion\Crosspost\Exception\CrosspostException;
use IndianOpinion\Crosspost\Network;
use IndianOpinion\Crosspost\Plugin;
use IndianOpinion\Crosspost\Value\Credentials;

/**
 * The documented authorisation flows for X (OAuth 2.0 with PKCE), Threads
 * and Mastodon. Each flow carries a state value bound to the current user
 * that expires in 10 minutes and works once.
 */
final class OAuth
{
    public const STATE_TTL = 600;
    public const X_AUTHORIZE = 'https://x.com/i/oauth2/authorize';
    public const X_SCOPES = 'tweet.read tweet.write users.read offline.access';
    public const THREADS_AUTHORIZE = 'https://threads.net/oauth/authorize';
    public const THREADS_TOKEN = 'https://graph.threads.net/oauth/access_token';
    public const THREADS_EXCHANGE = 'https://graph.threads.net/access_token';
    public const THREADS_SCOPES = 'threads_basic,threads_content_publish';
    public const MASTODON_SCOPES = 'read:accounts read:statuses write:statuses write:media';

    public static function redirectUri(): string
    {
        return admin_url('admin-post.php?action=' . Actions::OAUTH_CALLBACK);
    }

    public static function start(string $network): void
    {
        $app = Plugin::app();
        $creds = $app->credentials->get($network);
        $state = wp_generate_password(32, false, false);
        $verifier = self::base64url(random_bytes(48));
        $redirect = self::redirectUri();

        switch ($network) {
            case Network::X:
                if (!$creds->has('client_id')) {
                    throw new ConfigurationException('Enter the X app client id first.');
                }
                $url = self::X_AUTHORIZE . '?' . http_build_query([
                    'response_type' => 'code',
                    'client_id' => $creds->get('client_id'),
                    'redirect_uri' => $redirect,
                    'scope' => self::X_SCOPES,
                    'state' => $state,
                    'code_challenge' => self::base64url(hash('sha256', $verifier, true)),
                    'code_challenge_method' => 'S256',
                ], '', '&', PHP_QUERY_RFC3986);
                break;
            case Network::THREADS:
                if (!$creds->has('app_id') || !$creds->has('app_secret')) {
                    throw new ConfigurationException('Enter the Threads app id and secret first.');
                }
                $url = self::THREADS_AUTHORIZE . '?' . http_build_query([
                    'client_id' => $creds->get('app_id'),
                    'redirect_uri' => $redirect,
                    'scope' => self::THREADS_SCOPES,
                    'response_type' => 'code',
                    'state' => $state,
                ], '', '&', PHP_QUERY_RFC3986);
                break;
            case Network::MASTODON:
                $creds = self::mastodonApp($creds, $redirect);
                $url = rtrim($creds->get('instance'), '/') . '/oauth/authorize?' . http_build_query([
                    'response_type' => 'code',
                    'client_id' => $creds->get('client_id'),
                    'redirect_uri' => $redirect,
                    'scope' => self::MASTODON_SCOPES,
                    'state' => $state,
                ], '', '&', PHP_QUERY_RFC3986);
                break;
            default:
                throw new ConfigurationException(sprintf('%s does not use an authorisation flow; enter the credentials directly.', Network::label($network)));
        }

        set_transient(self::key(), [
            'network' => $network,
            'state' => $state,
            'verifier' => $verifier,
            'expires' => $app->clock->now() + self::STATE_TTL,
        ], self::STATE_TTL);
        wp_redirect($url);
        Actions::terminate();
    }

    public static function callback(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do this.', 'crosspost'), '', ['response' => 403]);
        }
        $app = Plugin::app();
        $stored = get_transient(self::key());
        delete_transient(self::key());
        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash((string) $_GET['state'])) : '';
        if (
            !is_array($stored)
            || !isset($stored['state'], $stored['network'], $stored['expires'], $stored['verifier'])
            || !is_string($stored['state'])
            || $state === ''
            || !hash_equals($stored['state'], $state)
            || (int) $stored['expires'] < $app->clock->now()
        ) {
            wp_die(esc_html__('The authorisation request is invalid or expired. Start again from the settings screen.', 'crosspost'), '', ['response' => 403]);
        }
        $network = Network::assertValid((string) $stored['network']);
        $back = admin_url('options-general.php?page=crosspost&tab=' . $network);
        if (isset($_GET['error'])) {
            Actions::flash(sprintf(__('%s did not authorise the connection.', 'crosspost'), Network::label($network)), 'error');
            Actions::back($back);
            return;
        }
        $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash((string) $_GET['code'])) : '';
        if ($code === '') {
            wp_die(esc_html__('No authorisation code was returned.', 'crosspost'), '', ['response' => 400]);
        }
        try {
            $creds = self::exchange($network, $app->credentials->get($network), $code, (string) $stored['verifier']);
            // A new authorisation may be a different account: verify again.
            $creds = $creds->with(['verified_account' => '', 'verified_name' => '', 'verified_at' => 0, 'verified_fp' => '']);
            $app->credentials->save($creds);
            $account = $app->verify($network);
            Actions::flash(sprintf(__('%1$s connected: %2$s.', 'crosspost'), Network::label($network), $account->name));
        } catch (CrosspostException $e) {
            Actions::flash($app->logger->scrub($e->getMessage()), 'error');
        }
        Actions::back($back);
    }

    private static function exchange(string $network, Credentials $creds, string $code, string $verifier): Credentials
    {
        $http = Plugin::app()->http;
        $now = Plugin::app()->clock->now();
        $redirect = self::redirectUri();
        $form = ['Content-Type' => 'application/x-www-form-urlencoded'];

        if ($network === Network::X) {
            $headers = $form;
            if ($creds->has('client_secret')) {
                $headers['Authorization'] = 'Basic ' . base64_encode($creds->get('client_id') . ':' . $creds->get('client_secret'));
            }
            $response = $http->request(Network::X, 'oauth', 'POST', XAdapter::TOKEN_URL, $headers, [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirect,
                'code_verifier' => $verifier,
                'client_id' => $creds->get('client_id'),
            ], true);
            $data = $response->json();
            if (!$response->success() || !is_string($data['access_token'] ?? null)) {
                throw new CredentialsException('X did not issue a token.');
            }
            return $creds->with([
                'access_token' => $data['access_token'],
                'refresh_token' => is_string($data['refresh_token'] ?? null) ? $data['refresh_token'] : '',
                'token_expires_at' => $now + (int) ($data['expires_in'] ?? 7200),
            ]);
        }

        if ($network === Network::THREADS) {
            $response = $http->request(Network::THREADS, 'oauth', 'POST', self::THREADS_TOKEN, $form, [
                'client_id' => $creds->get('app_id'),
                'client_secret' => $creds->get('app_secret'),
                'grant_type' => 'authorization_code',
                'redirect_uri' => $redirect,
                'code' => $code,
            ], true);
            $data = $response->json();
            if (!$response->success() || !is_string($data['access_token'] ?? null)) {
                throw new CredentialsException('Threads did not issue a token.');
            }
            // The documented long-lived exchange is a GET with parameters in
            // the query string; listed as an exception in ARCHITECTURE.md.
            $long = $http->request(Network::THREADS, 'oauth', 'GET', self::THREADS_EXCHANGE . '?' . http_build_query([
                'grant_type' => 'th_exchange_token',
                'client_secret' => $creds->get('app_secret'),
                'access_token' => $data['access_token'],
            ]));
            $longData = $long->json();
            if (!$long->success() || !is_string($longData['access_token'] ?? null)) {
                throw new CredentialsException('Threads did not issue a long-lived token.');
            }
            return $creds->with([
                'access_token' => $longData['access_token'],
                'token_expires_at' => $now + (int) ($longData['expires_in'] ?? 60 * 86400),
                'user_id' => is_scalar($data['user_id'] ?? null) ? (string) $data['user_id'] : '',
            ]);
        }

        if ($network === Network::MASTODON) {
            $response = $http->request(Network::MASTODON, 'oauth', 'POST', rtrim($creds->get('instance'), '/') . '/oauth/token', $form, [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'client_id' => $creds->get('client_id'),
                'client_secret' => $creds->get('client_secret'),
                'redirect_uri' => $redirect,
                'scope' => self::MASTODON_SCOPES,
            ], true);
            $data = $response->json();
            if (!$response->success() || !is_string($data['access_token'] ?? null)) {
                throw new CredentialsException('Mastodon did not issue a token.');
            }
            return $creds->with(['access_token' => $data['access_token']]);
        }

        throw new ConfigurationException('No authorisation flow for this network.');
    }

    /**
     * Register this site as an app on the instance, once per instance.
     */
    private static function mastodonApp(Credentials $creds, string $redirect): Credentials
    {
        $instance = rtrim($creds->get('instance'), '/');
        if ($instance === '') {
            throw new ConfigurationException('Enter the Mastodon instance URL first.');
        }
        if ($creds->has('client_id') && $creds->get('client_instance') === $instance) {
            return $creds;
        }
        $app = Plugin::app();
        $response = $app->http->request(Network::MASTODON, 'oauth', 'POST', $instance . '/api/v1/apps', ['Content-Type' => 'application/x-www-form-urlencoded'], [
            'client_name' => 'crosspost on ' . wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES),
            'redirect_uris' => $redirect,
            'scopes' => self::MASTODON_SCOPES,
            'website' => home_url('/'),
        ], true);
        $data = $response->json();
        if (!$response->success() || !is_string($data['client_id'] ?? null) || !is_string($data['client_secret'] ?? null)) {
            throw new CredentialsException('The instance did not register the app.');
        }
        $creds = $creds->with(['client_id' => $data['client_id'], 'client_secret' => $data['client_secret'], 'client_instance' => $instance]);
        $app->credentials->save($creds);
        return $creds;
    }

    private static function key(): string
    {
        return 'crosspost_oauth_' . get_current_user_id();
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
