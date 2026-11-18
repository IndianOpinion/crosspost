<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Adapter;

use IndianOpinion\Crosspost\Exception\CredentialsException;
use IndianOpinion\Crosspost\Http\HttpResponse;
use IndianOpinion\Crosspost\Network;
use IndianOpinion\Crosspost\Support\Deadline;
use IndianOpinion\Crosspost\Text\Counter;
use IndianOpinion\Crosspost\Text\XWeightedCount;
use IndianOpinion\Crosspost\Value\Account;
use IndianOpinion\Crosspost\Value\Credentials;
use IndianOpinion\Crosspost\Value\Draft;
use IndianOpinion\Crosspost\Value\Limits;
use IndianOpinion\Crosspost\Value\MediaFile;
use IndianOpinion\Crosspost\Value\MediaRef;
use IndianOpinion\Crosspost\Value\Outcome;

/**
 * X, through the v2 API.
 *
 * Posting uses the v2 create-post endpoint only: no media upload and no
 * timeline reads, so X posts are text only and an uncertain X post cannot
 * be checked automatically. It waits for a person (job state "unknown").
 * The "who am I" endpoint is used only by an explicit credential check.
 *
 * Credential fields: client_id, client_secret (optional), access_token,
 * refresh_token, token_expires_at.
 */
final class XAdapter extends AbstractAdapter
{
    public const API = 'https://api.x.com';
    public const TOKEN_URL = 'https://api.x.com/2/oauth2/token';
    /** Refresh this many seconds before the access token expires. */
    public const REFRESH_MARGIN = 300;

    public function id(): string
    {
        return Network::X;
    }

    public function limits(): Limits
    {
        return new Limits(
            XWeightedCount::LIMIT,
            Counter::WEIGHTED,
            Limits::TRANSPORT_NONE,
            0,
            0,
            0,
            [],
            0,
            Limits::RECONCILE_NONE,
        );
    }

    public function verify(Credentials $credentials): Account
    {
        $response = $this->http->request(Network::X, 'verify', 'GET', self::API . '/2/users/me', self::bearer($credentials->get('access_token')));
        if (!$response->success()) {
            throw new CredentialsException($this->message($response, $credentials));
        }
        $data = $response->json()['data'] ?? [];
        $id = is_array($data) ? self::str($data['id'] ?? '') : '';
        if ($id === '') {
            throw new CredentialsException('X returned no account id.');
        }
        return new Account($id, is_array($data) ? self::str($data['name'] ?? $id) : $id);
    }

    public function prepareMedia(MediaFile $file, Credentials $credentials, Deadline $deadline): MediaRef
    {
        return MediaRef::failed(Outcome::permanent('X posts are text only'));
    }

    public function publish(Draft $draft, ?MediaRef $media, Credentials $credentials, Deadline $deadline): Outcome
    {
        $refused = $this->lengthOutcome($draft);
        if ($refused !== null) {
            return $refused;
        }
        $response = $this->http->request(
            Network::X,
            'create',
            'POST',
            self::API . '/2/tweets',
            self::bearer($credentials->get('access_token')) + ['Content-Type' => 'application/json'],
            self::encode(['text' => $draft->text]),
            true,
        );
        if ($response->success()) {
            $data = $response->json()['data'] ?? null;
            $id = is_array($data) ? self::str($data['id'] ?? '') : '';
            if ($id === '') {
                // A success status without an id: it may be live.
                return Outcome::uncertain('success without a post id', $response->status);
            }
            return Outcome::posted($id, 'https://x.com/i/web/status/' . rawurlencode($id), $response->status);
        }
        return $this->classify($response, true, $credentials);
    }

    /**
     * X is never reconciled automatically; see the class comment.
     */
    public function reconcile(array $uncertain, Credentials $credentials): array
    {
        $out = [];
        foreach ($uncertain as $draft) {
            $out[$draft->jobId] = Outcome::uncertain('X posts are not checked automatically');
        }
        return $out;
    }

    public function refresh(Credentials $credentials, bool $force = false): ?Credentials
    {
        $expires = $credentials->int('token_expires_at');
        if (!$force && $expires > 0 && $expires - $this->clock->now() > self::REFRESH_MARGIN) {
            return null;
        }
        if (!$credentials->has('refresh_token') || !$credentials->has('client_id')) {
            if (!$force && $expires === 0 && $credentials->has('access_token')) {
                return null;
            }
            throw new CredentialsException('X has no refresh token; reconnect the account.');
        }
        $headers = ['Content-Type' => 'application/x-www-form-urlencoded'];
        $body = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $credentials->get('refresh_token'),
            'client_id' => $credentials->get('client_id'),
        ];
        if ($credentials->has('client_secret')) {
            $headers['Authorization'] = 'Basic ' . base64_encode($credentials->get('client_id') . ':' . $credentials->get('client_secret'));
        }
        $response = $this->http->request(Network::X, 'refresh', 'POST', self::TOKEN_URL, $headers, $body, true);
        $data = $response->json();
        $access = self::str($data['access_token'] ?? '');
        if (!$response->success() || $access === '') {
            throw new CredentialsException('X token refresh failed: ' . $this->message($response, $credentials));
        }
        $changes = [
            'access_token' => $access,
            'token_expires_at' => $this->clock->now() + (int) ($data['expires_in'] ?? 7200),
        ];
        $refresh = self::str($data['refresh_token'] ?? '');
        if ($refresh !== '') {
            $changes['refresh_token'] = $refresh;
        }
        return $credentials->with($changes);
    }

    protected function isAuthError(HttpResponse $response): bool
    {
        if ($response->status !== 403) {
            return false;
        }
        $body = strtolower($response->body);
        return str_contains($body, 'unsupported authentication') || str_contains($body, 'oauth') || str_contains($body, 'client-not-enrolled');
    }
}
