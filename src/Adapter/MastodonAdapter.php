<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Adapter;

use IndianOpinion\Crosspost\Exception\CredentialsException;
use IndianOpinion\Crosspost\Http\HttpResponse;
use IndianOpinion\Crosspost\Http\Multipart;
use IndianOpinion\Crosspost\Network;
use IndianOpinion\Crosspost\Support\Deadline;
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
 * Mastodon and compatible servers, through the documented REST API.
 *
 * Every create call carries an Idempotency-Key derived from the job. The
 * server remembers keys for about an hour, so inside that window an
 * uncertain job is resent with the same key; after it, the account's
 * recent statuses are matched by text instead.
 *
 * Credential fields: instance, access_token, client_id, client_secret,
 * account_id.
 */
final class MastodonAdapter extends AbstractAdapter
{
    public const DEFAULT_LIMIT = 500;
    public const URL_LENGTH = 23;
    public const CONFIG_TRANSIENT = 'crosspost_mastodon_instance';
    public const CONFIG_TTL = 86400;
    /** Resend with the same key only inside this window (server keeps keys about an hour). */
    public const IDEMPOTENCY_WINDOW = 3300;
    public const POLL_STEP = 5;
    public const POLL_WAIT = 30;

    public function id(): string
    {
        return Network::MASTODON;
    }

    public function limits(): Limits
    {
        $config = $this->config();
        return new Limits(
            $config['max_characters'],
            Counter::GRAPHEMES_LINKS,
            Limits::TRANSPORT_UPLOAD,
            $config['image_size_limit'],
            4096,
            2048,
            ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
            $config['description_limit'],
            Limits::RECONCILE_IDEMPOTENT,
        );
    }

    public function count(string $text): int
    {
        return Counter::count(Counter::GRAPHEMES_LINKS, $text, $this->config()['url_length']);
    }

    public function verify(Credentials $credentials): Account
    {
        $instance = self::instance($credentials);
        $response = $this->http->request(Network::MASTODON, 'verify', 'GET', $instance . '/api/v1/accounts/verify_credentials', self::bearer($credentials->get('access_token')));
        if (!$response->success()) {
            throw new CredentialsException($this->message($response, $credentials));
        }
        $data = $response->json();
        $id = self::str($data['id'] ?? '');
        if ($id === '') {
            throw new CredentialsException('Mastodon returned no account id.');
        }
        $name = self::str($data['display_name'] ?? '');
        return new Account($id, $name !== '' ? $name : self::str($data['username'] ?? $id));
    }

    public function prepareMedia(MediaFile $file, Credentials $credentials, Deadline $deadline): MediaRef
    {
        $bytes = file_get_contents($file->path);
        if ($bytes === false) {
            return MediaRef::failed(Outcome::permanent('image file could not be read'));
        }
        $instance = self::instance($credentials);
        $form = (new Multipart())->file('file', basename($file->path), $file->mimeType, $bytes);
        if ($file->alt !== '') {
            $form->field('description', $file->alt);
        }
        $headers = self::bearer($credentials->get('access_token'));
        $response = $this->http->request(
            Network::MASTODON,
            'upload',
            'POST',
            $instance . '/api/v2/media',
            $headers + ['Content-Type' => $form->contentType()],
            $form->body(),
            true,
        );
        if (!$response->success()) {
            return MediaRef::failed($this->classify($response, false, $credentials));
        }
        $data = $response->json();
        $id = self::str($data['id'] ?? '');
        if ($id === '') {
            return MediaRef::failed(Outcome::retryable('upload returned no media id', $response->status));
        }
        if ($response->status === 200 && self::str($data['url'] ?? '') !== '') {
            return MediaRef::ready($id, [], self::str($data['url']), $file->alt, $file->width, $file->height);
        }
        // 202: processing asynchronously. Poll within the bounded wait.
        $wait = $deadline->capped(self::POLL_WAIT);
        while (true) {
            if ($wait->remaining() < self::POLL_STEP) {
                return MediaRef::failed(Outcome::retryable('media still processing after the wait'));
            }
            $this->sleeper->sleep(self::POLL_STEP);
            $poll = $this->http->request(Network::MASTODON, 'poll', 'GET', $instance . '/api/v1/media/' . rawurlencode($id), $headers);
            if ($poll->errorKind === HttpResponse::BLOCKED) {
                return MediaRef::failed(Outcome::blocked());
            }
            if ($poll->status === 200 && self::str($poll->json()['url'] ?? '') !== '') {
                return MediaRef::ready($id, [], self::str($poll->json()['url']), $file->alt, $file->width, $file->height);
            }
            if ($poll->status !== 206 && !$poll->success()) {
                return MediaRef::failed($this->classify($poll, false, $credentials));
            }
        }
    }

    public function publish(Draft $draft, ?MediaRef $media, Credentials $credentials, Deadline $deadline): Outcome
    {
        $refused = $this->lengthOutcome($draft);
        if ($refused !== null) {
            return $refused;
        }
        $body = ['status' => $draft->text, 'visibility' => $draft->visibility !== '' ? $draft->visibility : 'public'];
        if ($draft->language !== '') {
            $body['language'] = $draft->language;
        }
        if ($media !== null && $media->ok() && $media->id !== '') {
            $body['media_ids'] = [$media->id];
        }
        $response = $this->http->request(
            Network::MASTODON,
            'create',
            'POST',
            self::instance($credentials) . '/api/v1/statuses',
            self::bearer($credentials->get('access_token')) + [
                'Content-Type' => 'application/json',
                'Idempotency-Key' => $draft->idempotencyKey,
            ],
            self::encode($body),
            true,
        );
        if ($response->success()) {
            $data = $response->json();
            $id = self::str($data['id'] ?? '');
            if ($id === '') {
                return Outcome::uncertain('success without a status id', $response->status);
            }
            return Outcome::posted($id, self::str($data['url'] ?? ''), $response->status);
        }
        return $this->classify($response, true, $credentials);
    }

    /**
     * Inside the idempotency window: "absent", which tells the runner to
     * resend with the same key (the server returns the original status).
     * After it: one listing of recent statuses, matched by text.
     */
    public function reconcile(array $uncertain, Credentials $credentials): array
    {
        $out = [];
        $late = [];
        foreach ($uncertain as $draft) {
            if ($draft->firstSentAt > 0 && $this->clock->now() - $draft->firstSentAt < self::IDEMPOTENCY_WINDOW) {
                $out[$draft->jobId] = Outcome::absent();
            } else {
                $late[] = $draft;
            }
        }
        if ($late === []) {
            return $out;
        }
        $account = $credentials->get('account_id');
        if ($account === '') {
            foreach ($late as $draft) {
                $out[$draft->jobId] = Outcome::uncertain('no account id to list statuses');
            }
            return $out;
        }
        $response = $this->http->request(
            Network::MASTODON,
            'list',
            'GET',
            self::instance($credentials) . '/api/v1/accounts/' . rawurlencode($account) . '/statuses?limit=40&exclude_replies=true&exclude_reblogs=true',
            self::bearer($credentials->get('access_token')),
        );
        if (!$response->success()) {
            $message = $this->message($response, $credentials);
            foreach ($late as $draft) {
                $out[$draft->jobId] = Outcome::uncertain('listing failed: ' . $message, $response->status);
            }
            return $out;
        }
        $byHash = [];
        foreach ($response->json() as $status) {
            if (is_array($status) && isset($status['content'], $status['id']) && is_string($status['content'])) {
                $byHash[Normaliser::hash($status['content'])] ??= [self::str($status['id']), self::str($status['url'] ?? '')];
            }
        }
        foreach ($late as $draft) {
            $match = $byHash[Normaliser::hash($draft->text)] ?? null;
            $out[$draft->jobId] = $match === null
                ? Outcome::uncertain('not found in recent statuses')
                : Outcome::posted($match[0], $match[1]);
        }
        return $out;
    }

    /**
     * Mastodon tokens do not expire on a schedule. This refreshes the
     * cached instance configuration (text and media limits) once a day.
     */
    public function refresh(Credentials $credentials, bool $force = false): ?Credentials
    {
        if (!$force && get_transient(self::CONFIG_TRANSIENT) !== false) {
            return null;
        }
        $instance = self::instance($credentials);
        $response = $this->http->request(Network::MASTODON, 'config', 'GET', $instance . '/api/v2/instance');
        if (!$response->success()) {
            $response = $this->http->request(Network::MASTODON, 'config', 'GET', $instance . '/api/v1/instance');
        }
        if ($response->success()) {
            set_transient(self::CONFIG_TRANSIENT, self::parseConfig($response->json()), self::CONFIG_TTL);
        }
        return null;
    }

    /**
     * @param array<mixed> $data
     * @return array{max_characters: int, url_length: int, image_size_limit: int, description_limit: int}
     */
    public static function parseConfig(array $data): array
    {
        $config = is_array($data['configuration'] ?? null) ? $data['configuration'] : [];
        $statuses = is_array($config['statuses'] ?? null) ? $config['statuses'] : [];
        $media = is_array($config['media_attachments'] ?? null) ? $config['media_attachments'] : [];
        $max = (int) ($statuses['max_characters'] ?? ($data['max_toot_chars'] ?? self::DEFAULT_LIMIT));
        return [
            'max_characters' => $max > 0 ? $max : self::DEFAULT_LIMIT,
            'url_length' => max(1, (int) ($statuses['characters_reserved_per_url'] ?? self::URL_LENGTH)),
            'image_size_limit' => max(1, (int) ($media['image_size_limit'] ?? 16 * 1024 * 1024)),
            'description_limit' => max(1, (int) ($media['description_limit'] ?? 1500)),
        ];
    }

    /**
     * @return array{max_characters: int, url_length: int, image_size_limit: int, description_limit: int}
     */
    private function config(): array
    {
        $cached = get_transient(self::CONFIG_TRANSIENT);
        if (is_array($cached) && isset($cached['max_characters'], $cached['url_length'], $cached['image_size_limit'], $cached['description_limit'])) {
            return [
                'max_characters' => (int) $cached['max_characters'],
                'url_length' => (int) $cached['url_length'],
                'image_size_limit' => (int) $cached['image_size_limit'],
                'description_limit' => (int) $cached['description_limit'],
            ];
        }
        return self::parseConfig([]);
    }

    private static function instance(Credentials $credentials): string
    {
        $instance = rtrim($credentials->get('instance'), '/');
        if (!str_starts_with($instance, 'https://')) {
            throw new CredentialsException('The Mastodon instance URL must use HTTPS.');
        }
        return $instance;
    }

    protected function isAuthError(HttpResponse $response): bool
    {
        return $response->status === 403 && str_contains(strtolower($response->body), 'token');
    }
}
