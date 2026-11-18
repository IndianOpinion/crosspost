<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Adapter;

use IndianOpinion\Crosspost\Exception\CredentialsException;
use IndianOpinion\Crosspost\Http\HttpClient;
use IndianOpinion\Crosspost\Http\HttpResponse;
use IndianOpinion\Crosspost\Media\PublicUrl;
use IndianOpinion\Crosspost\Network;
use IndianOpinion\Crosspost\Support\Clock;
use IndianOpinion\Crosspost\Support\Deadline;
use IndianOpinion\Crosspost\Support\Sleeper;
use IndianOpinion\Crosspost\Text\Counter;
use IndianOpinion\Crosspost\Text\Normaliser;
use IndianOpinion\Crosspost\Value\Account;
use IndianOpinion\Crosspost\Value\Credentials;
use IndianOpinion\Crosspost\Value\Draft;
use IndianOpinion\Crosspost\Value\Limits;
use IndianOpinion\Crosspost\Value\MediaFile;
use IndianOpinion\Crosspost\Value\MediaRef;
use IndianOpinion\Crosspost\Value\Outcome;

/**
 * Threads, through the official Threads API.
 *
 * A post is two calls: create a container (text, or image by public URL),
 * then publish it. The container step is "before send"; the publish step
 * is the final create call. Images are fetched by Threads from a public
 * HTTPS URL, so the URL is checked first.
 *
 * Credential fields: app_id, app_secret, access_token (long-lived),
 * token_expires_at, user_id.
 */
final class ThreadsAdapter extends AbstractAdapter
{
    public const API = 'https://graph.threads.net/v1.0';
    public const REFRESH_URL = 'https://graph.threads.net/refresh_access_token';
    /** Refresh when less than this many seconds of token life remain. */
    public const REFRESH_WINDOW = 7 * 86400;
    /** Containers older than this are not reused. */
    public const CONTAINER_TTL = 23 * 3600;
    public const POLL_STEP = 5;
    public const POLL_WAIT = 30;

    private PublicUrl $publicUrl;

    public function __construct(HttpClient $http, Clock $clock, Sleeper $sleeper, ?PublicUrl $publicUrl = null)
    {
        parent::__construct($http, $clock, $sleeper);
        $this->publicUrl = $publicUrl ?? new PublicUrl();
    }

    public function id(): string
    {
        return Network::THREADS;
    }

    public function limits(): Limits
    {
        return new Limits(
            500,
            Counter::CODE_POINTS,
            Limits::TRANSPORT_PUBLIC_URL,
            8 * 1024 * 1024,
            1440,
            1440,
            ['image/jpeg', 'image/png'],
            1000,
            Limits::RECONCILE_LISTING,
        );
    }

    public function verify(Credentials $credentials): Account
    {
        $response = $this->http->request(Network::THREADS, 'verify', 'GET', self::API . '/me?fields=id,username,name', self::bearer($credentials->get('access_token')));
        if (!$response->success()) {
            throw new CredentialsException($this->message($response, $credentials));
        }
        $data = $response->json();
        $id = self::str($data['id'] ?? '');
        if ($id === '') {
            throw new CredentialsException('Threads returned no account id.');
        }
        $name = self::str($data['name'] ?? '');
        return new Account($id, $name !== '' ? $name : self::str($data['username'] ?? $id));
    }

    /**
     * Threads fetches the image itself; nothing is uploaded here. The URL
     * must be public HTTPS on a public address.
     */
    public function prepareMedia(MediaFile $file, Credentials $credentials, Deadline $deadline): MediaRef
    {
        $problem = $this->publicUrl->problem($file->publicUrl);
        if ($problem !== '') {
            return MediaRef::failed(Outcome::permanent('image URL refused: ' . $problem));
        }
        return MediaRef::ready('', [], $file->publicUrl, $file->alt, $file->width, $file->height);
    }

    public function publish(Draft $draft, ?MediaRef $media, Credentials $credentials, Deadline $deadline): Outcome
    {
        $refused = $this->lengthOutcome($draft);
        if ($refused !== null) {
            return $refused;
        }
        $user = $credentials->get('user_id') !== '' ? $credentials->get('user_id') : 'me';
        $headers = self::bearer($credentials->get('access_token'));

        $container = '';
        $reuse = self::str($draft->meta['container_id'] ?? '');
        $age = $this->clock->now() - (int) ($draft->meta['container_at'] ?? 0);
        if ($reuse !== '' && $age < self::CONTAINER_TTL) {
            $container = $reuse;
        }
        $imageNote = '';

        if ($container === '' && $media !== null && $media->ok()) {
            $result = $this->createContainer($user, $headers, $draft, $media, $credentials);
            if ($result instanceof Outcome) {
                if ($result->blocked || !$draft->textOnlyOnImageFailure) {
                    return $result;
                }
                $imageNote = 'image container failed: ' . $result->message;
            } else {
                $state = $this->waitFor($result, $headers, $credentials, $deadline);
                if ($state === 'BLOCKED') {
                    return Outcome::blocked();
                }
                if ($state === 'FINISHED') {
                    $container = $result;
                } else {
                    if (!$draft->textOnlyOnImageFailure) {
                        return Outcome::retryable('image container ' . strtolower($state));
                    }
                    $imageNote = 'image container ' . strtolower($state);
                }
            }
        }

        if ($container === '') {
            $result = $this->createContainer($user, $headers, $draft, null, $credentials);
            if ($result instanceof Outcome) {
                return $result;
            }
            $state = $this->waitFor($result, $headers, $credentials, $deadline);
            if ($state === 'BLOCKED') {
                // The kill switch went on mid-attempt: leave the job as it was.
                return Outcome::blocked();
            }
            if ($state !== 'FINISHED') {
                return Outcome::retryable('text container ' . strtolower($state));
            }
            $container = $result;
        }

        $meta = ['container_id' => $container, 'container_at' => (int) ($draft->meta['container_at'] ?? $this->clock->now())];
        if ($reuse !== $container) {
            $meta['container_at'] = $this->clock->now();
        }
        if ($imageNote !== '') {
            $meta['image_note'] = $imageNote;
        }

        $response = $this->http->request(
            Network::THREADS,
            'create',
            'POST',
            self::API . '/' . rawurlencode($user) . '/threads_publish',
            $headers,
            ['creation_id' => $container],
            true,
        );
        if ($response->success()) {
            $id = self::str($response->json()['id'] ?? '');
            if ($id === '') {
                return Outcome::uncertain('success without a post id', $response->status, $meta);
            }
            return Outcome::posted($id, $this->permalink($id, $headers), $response->status, $meta);
        }
        $outcome = $this->classify($response, true, $credentials);
        if ($outcome->is(Outcome::UNCERTAIN)) {
            return Outcome::uncertain($outcome->message, $outcome->httpStatus, $meta);
        }
        if ($outcome->is(Outcome::RETRYABLE)) {
            return Outcome::retryable($outcome->message, $outcome->httpStatus, $outcome->retryAfter, $outcome->cooldown, $meta);
        }
        return $outcome;
    }

    /**
     * One listing call covers every uncertain job.
     */
    public function reconcile(array $uncertain, Credentials $credentials): array
    {
        $out = [];
        if ($uncertain === []) {
            return $out;
        }
        $response = $this->http->request(
            Network::THREADS,
            'list',
            'GET',
            self::API . '/me/threads?fields=id,text,permalink,timestamp&limit=50',
            self::bearer($credentials->get('access_token')),
        );
        if (!$response->success()) {
            $message = $this->message($response, $credentials);
            foreach ($uncertain as $draft) {
                $out[$draft->jobId] = Outcome::uncertain('listing failed: ' . $message, $response->status);
            }
            return $out;
        }
        $byHash = [];
        $items = $response->json()['data'] ?? [];
        foreach (is_array($items) ? $items : [] as $item) {
            if (is_array($item) && isset($item['text'], $item['id']) && is_string($item['text'])) {
                $byHash[Normaliser::hash($item['text'])] ??= [self::str($item['id']), self::str($item['permalink'] ?? '')];
            }
        }
        foreach ($uncertain as $draft) {
            $match = $byHash[Normaliser::hash($draft->text)] ?? null;
            $out[$draft->jobId] = $match === null
                ? Outcome::uncertain('not found in recent posts')
                : Outcome::posted($match[0], $match[1]);
        }
        return $out;
    }

    public function refresh(Credentials $credentials, bool $force = false): ?Credentials
    {
        $expires = $credentials->int('token_expires_at');
        if (!$force && ($expires === 0 || $expires - $this->clock->now() > self::REFRESH_WINDOW)) {
            return null;
        }
        if (!$credentials->has('access_token')) {
            throw new CredentialsException('Threads has no token; reconnect the account.');
        }
        // The documented refresh endpoint takes the token only as a query
        // parameter. This is one of the listed exceptions in ARCHITECTURE.md.
        $url = self::REFRESH_URL . '?grant_type=th_refresh_token&access_token=' . rawurlencode($credentials->get('access_token'));
        $response = $this->http->request(Network::THREADS, 'refresh', 'GET', $url);
        $data = $response->json();
        $token = self::str($data['access_token'] ?? '');
        if (!$response->success() || $token === '') {
            throw new CredentialsException('Threads token refresh failed: ' . $this->message($response, $credentials));
        }
        return $credentials->with([
            'access_token' => $token,
            'token_expires_at' => $this->clock->now() + (int) ($data['expires_in'] ?? 60 * 86400),
        ]);
    }

    protected function isAuthError(HttpResponse $response): bool
    {
        $error = $response->json()['error'] ?? null;
        if (!is_array($error)) {
            return false;
        }
        $code = (int) ($error['code'] ?? 0);
        // 102 and 190: session or token invalid. 10 and 200 to 299: permission missing.
        return in_array($code, [10, 102, 190], true) || ($code >= 200 && $code <= 299);
    }

    protected function classify(HttpResponse $response, bool $finalCreate, Credentials $credentials): Outcome
    {
        $error = $response->json()['error'] ?? null;
        if (!$response->failed() && is_array($error) && in_array((int) ($error['code'] ?? 0), [4, 17, 32, 613], true)) {
            return Outcome::retryable($this->message($response, $credentials), $response->status, $this->resetTime($response), true);
        }
        return parent::classify($response, $finalCreate, $credentials);
    }

    /**
     * @param array<string, string> $headers
     */
    private function createContainer(string $user, array $headers, Draft $draft, ?MediaRef $media, Credentials $credentials): string|Outcome
    {
        $body = ['media_type' => 'TEXT', 'text' => $draft->text];
        if ($media !== null) {
            $body['media_type'] = 'IMAGE';
            $body['image_url'] = $media->url;
            if ($media->alt !== '') {
                $body['alt_text'] = $media->alt;
            }
        }
        $response = $this->http->request(Network::THREADS, 'container', 'POST', self::API . '/' . rawurlencode($user) . '/threads', $headers, $body, true);
        if ($response->success()) {
            $id = self::str($response->json()['id'] ?? '');
            if ($id !== '') {
                return $id;
            }
            return Outcome::retryable('container without an id', $response->status);
        }
        // The container step never publishes anything: every failure is "before send".
        $outcome = $this->classify($response, false, $credentials);
        return $outcome->is(Outcome::UNCERTAIN) ? Outcome::retryable($outcome->message, $outcome->httpStatus) : $outcome;
    }

    /**
     * Poll a container until it is finished, failed or the wait runs out.
     *
     * @param array<string, string> $headers
     */
    private function waitFor(string $container, array $headers, Credentials $credentials, Deadline $deadline): string
    {
        $wait = $deadline->capped(self::POLL_WAIT);
        while (true) {
            $response = $this->http->request(Network::THREADS, 'poll', 'GET', self::API . '/' . rawurlencode($container) . '?fields=status,error_message', $headers);
            if ($response->errorKind === HttpResponse::BLOCKED) {
                return 'BLOCKED';
            }
            $status = strtoupper(self::str($response->json()['status'] ?? ''));
            if ($response->success() && in_array($status, ['FINISHED', 'PUBLISHED'], true)) {
                return 'FINISHED';
            }
            if (in_array($status, ['ERROR', 'EXPIRED'], true)) {
                return $status;
            }
            if ($wait->remaining() < self::POLL_STEP) {
                return 'TIMED OUT';
            }
            $this->sleeper->sleep(self::POLL_STEP);
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private function permalink(string $id, array $headers): string
    {
        $response = $this->http->request(Network::THREADS, 'read', 'GET', self::API . '/' . rawurlencode($id) . '?fields=permalink', $headers);
        return $response->success() ? self::str($response->json()['permalink'] ?? '') : '';
    }
}
