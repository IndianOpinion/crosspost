<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Adapter;

use IndianOpinion\Crosspost\Exception\CredentialsException;
use IndianOpinion\Crosspost\Http\HttpResponse;
use IndianOpinion\Crosspost\Network;
use IndianOpinion\Crosspost\Support\Deadline;
use IndianOpinion\Crosspost\Text\Counter;
use IndianOpinion\Crosspost\Text\Graphemes;
use IndianOpinion\Crosspost\Text\Urls;
use IndianOpinion\Crosspost\Value\Account;
use IndianOpinion\Crosspost\Value\Credentials;
use IndianOpinion\Crosspost\Value\Draft;
use IndianOpinion\Crosspost\Value\Limits;
use IndianOpinion\Crosspost\Value\MediaFile;
use IndianOpinion\Crosspost\Value\MediaRef;
use IndianOpinion\Crosspost\Value\Outcome;

/**
 * Bluesky, through the AT Protocol's documented XRPC endpoints.
 *
 * The record key is chosen at job creation and sent on the create call, so
 * an uncertain post is checked by reading that key back, and a resend with
 * the same key cannot make a second post.
 *
 * Credential fields: service, identifier, app_password, and the cached
 * session: access_jwt, refresh_jwt, did, access_exp.
 */
final class BlueskyAdapter extends AbstractAdapter
{
    public const DEFAULT_SERVICE = 'https://bsky.social';
    public const COLLECTION = 'app.bsky.feed.post';
    public const APP_PASSWORD_PATTERN = '/^[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}$/';
    public const REFRESH_MARGIN = 300;
    public const MAX_TAG_GRAPHEMES = 64;

    public function id(): string
    {
        return Network::BLUESKY;
    }

    public function limits(): Limits
    {
        return new Limits(
            300,
            Counter::GRAPHEMES,
            Limits::TRANSPORT_UPLOAD,
            1000000,
            2000,
            2000,
            ['image/jpeg', 'image/png', 'image/webp'],
            2000,
            Limits::RECONCILE_RECORD_KEY,
        );
    }

    public function verify(Credentials $credentials): Account
    {
        if (!$credentials->has('access_jwt')) {
            throw new CredentialsException('Bluesky has no session; refresh first.');
        }
        $response = $this->http->request(Network::BLUESKY, 'verify', 'GET', $this->service($credentials) . '/xrpc/com.atproto.server.getSession', self::bearer($credentials->get('access_jwt')));
        if (!$response->success()) {
            throw new CredentialsException($this->message($response, $credentials));
        }
        $data = $response->json();
        $did = self::str($data['did'] ?? '');
        if ($did === '') {
            throw new CredentialsException('Bluesky returned no account id.');
        }
        return new Account($did, self::str($data['handle'] ?? $did));
    }

    public function prepareMedia(MediaFile $file, Credentials $credentials, Deadline $deadline): MediaRef
    {
        $bytes = file_get_contents($file->path);
        if ($bytes === false) {
            return MediaRef::failed(Outcome::permanent('image file could not be read'));
        }
        $response = $this->http->request(
            Network::BLUESKY,
            'upload',
            'POST',
            $this->service($credentials) . '/xrpc/com.atproto.repo.uploadBlob',
            self::bearer($credentials->get('access_jwt')) + ['Content-Type' => $file->mimeType],
            $bytes,
            true,
        );
        if ($response->success()) {
            $blob = $response->json()['blob'] ?? null;
            if (is_array($blob)) {
                /** @var array<string, mixed> $blob */
                return MediaRef::ready('', $blob, '', $file->alt, $file->width, $file->height);
            }
            return MediaRef::failed(Outcome::retryable('upload returned no blob', $response->status));
        }
        $outcome = $this->classify($response, false, $credentials);
        return MediaRef::failed($outcome);
    }

    public function publish(Draft $draft, ?MediaRef $media, Credentials $credentials, Deadline $deadline): Outcome
    {
        $refused = $this->lengthOutcome($draft);
        if ($refused !== null) {
            return $refused;
        }
        $did = $credentials->get('did');
        if ($did === '') {
            return Outcome::auth('no session');
        }
        $record = [
            '$type' => self::COLLECTION,
            'text' => $draft->text,
            'createdAt' => gmdate('Y-m-d\TH:i:s.000\Z', $this->clock->now()),
        ];
        if ($draft->language !== '') {
            $record['langs'] = [$draft->language];
        }
        $facets = self::facets($draft->text);
        if ($facets !== []) {
            $record['facets'] = $facets;
        }
        $embed = $this->embed($draft, $media);
        if ($embed !== null) {
            $record['embed'] = $embed;
        }

        $response = $this->http->request(
            Network::BLUESKY,
            'create',
            'POST',
            $this->service($credentials) . '/xrpc/com.atproto.repo.createRecord',
            self::bearer($credentials->get('access_jwt')) + ['Content-Type' => 'application/json'],
            self::encode([
                'repo' => $did,
                'collection' => self::COLLECTION,
                'rkey' => $draft->idempotencyKey,
                'validate' => true,
                'record' => $record,
            ]),
            true,
        );
        if ($response->success()) {
            $uri = self::str($response->json()['uri'] ?? '');
            if ($uri === '') {
                return Outcome::uncertain('success without a record', $response->status);
            }
            return Outcome::posted($uri, $this->postUrl($did, $draft->idempotencyKey), $response->status);
        }
        // A conflict on our own record key means an earlier attempt landed;
        // reconciliation reads it back.
        if ($response->status === 400 && str_contains(strtolower($response->body), 'already exists')) {
            return Outcome::uncertain('record key already exists', $response->status);
        }
        return $this->classify($response, true, $credentials);
    }

    public function reconcile(array $uncertain, Credentials $credentials): array
    {
        $out = [];
        $did = $credentials->get('did');
        foreach ($uncertain as $draft) {
            $url = $this->service($credentials) . '/xrpc/com.atproto.repo.getRecord?' . http_build_query([
                'repo' => $did,
                'collection' => self::COLLECTION,
                'rkey' => $draft->idempotencyKey,
            ]);
            $response = $this->http->request(Network::BLUESKY, 'read', 'GET', $url, self::bearer($credentials->get('access_jwt')));
            if ($response->success()) {
                $uri = self::str($response->json()['uri'] ?? '');
                $out[$draft->jobId] = Outcome::posted($uri, $this->postUrl($did, $draft->idempotencyKey), $response->status);
                continue;
            }
            $error = self::str($response->json()['error'] ?? '');
            if (!$response->failed() && $response->status === 400 && $error === 'RecordNotFound') {
                $out[$draft->jobId] = Outcome::absent();
                continue;
            }
            $out[$draft->jobId] = Outcome::uncertain('record check failed: ' . $this->message($response, $credentials), $response->status);
        }
        return $out;
    }

    /**
     * Reuse the cached session while the access token is fresh; refresh it
     * when it is close to expiry; create a new session only when refresh
     * fails, because session creation is rate-limited.
     */
    public function refresh(Credentials $credentials, bool $force = false): ?Credentials
    {
        $exp = $credentials->int('access_exp');
        if (!$force && $credentials->has('access_jwt') && $exp - $this->clock->now() > self::REFRESH_MARGIN) {
            return null;
        }
        if ($credentials->has('refresh_jwt')) {
            $response = $this->http->request(
                Network::BLUESKY,
                'refresh',
                'POST',
                $this->service($credentials) . '/xrpc/com.atproto.server.refreshSession',
                self::bearer($credentials->get('refresh_jwt')),
                null,
                true,
            );
            if ($response->errorKind === HttpResponse::BLOCKED) {
                throw new CredentialsException('kill switch');
            }
            $session = $this->session($response);
            if ($session !== null) {
                return $credentials->with($session);
            }
        }
        $identifier = $credentials->get('identifier');
        $password = $credentials->get('app_password');
        if ($identifier === '' || $password === '') {
            throw new CredentialsException('Bluesky identifier or app password missing.');
        }
        if (preg_match(self::APP_PASSWORD_PATTERN, $password) !== 1) {
            throw new CredentialsException('Bluesky needs an app password (xxxx-xxxx-xxxx-xxxx), never the account password.');
        }
        $response = $this->http->request(
            Network::BLUESKY,
            'session',
            'POST',
            $this->service($credentials) . '/xrpc/com.atproto.server.createSession',
            ['Content-Type' => 'application/json'],
            self::encode(['identifier' => $identifier, 'password' => $password]),
            true,
        );
        $session = $this->session($response);
        if ($session === null) {
            throw new CredentialsException('Bluesky session could not be created: ' . $this->message($response, $credentials));
        }
        return $credentials->with($session);
    }

    /**
     * Rich-text facets for links and hashtags, with UTF-8 byte offsets
     * computed on the exact string sent.
     *
     * @return list<array<string, mixed>>
     */
    public static function facets(string $text): array
    {
        $facets = [];
        foreach (Urls::find($text) as $link) {
            $facets[] = [
                'index' => ['byteStart' => $link['start'], 'byteEnd' => $link['end']],
                'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => $link['url']]],
            ];
        }
        if (preg_match_all('/(?<=^|\s)#([^\s#]+)/u', $text, $m, PREG_OFFSET_CAPTURE) !== false) {
            foreach ($m[1] as [$tag, $offset]) {
                $tag = preg_replace('/\p{P}+$/u', '', $tag) ?? $tag;
                if ($tag === '' || preg_match('/^\p{N}+$/u', $tag) === 1 || Graphemes::count($tag) > self::MAX_TAG_GRAPHEMES) {
                    continue;
                }
                $start = $offset - 1;
                $facets[] = [
                    'index' => ['byteStart' => $start, 'byteEnd' => $offset + strlen($tag)],
                    'features' => [['$type' => 'app.bsky.richtext.facet#tag', 'tag' => $tag]],
                ];
            }
        }
        usort($facets, static fn (array $a, array $b): int => $a['index']['byteStart'] <=> $b['index']['byteStart']);
        return $facets;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function embed(Draft $draft, ?MediaRef $media): ?array
    {
        $blob = $media !== null && $media->ok() ? $media->payload : [];
        if ($draft->card === null && $blob !== []) {
            /** @var MediaRef $media */
            $image = ['alt' => $media->alt, 'image' => $blob];
            if ($media->width > 0 && $media->height > 0) {
                $image['aspectRatio'] = ['width' => $media->width, 'height' => $media->height];
            }
            return ['$type' => 'app.bsky.embed.images', 'images' => [$image]];
        }
        if ($draft->card !== null) {
            $external = [
                'uri' => $draft->card->url,
                'title' => $draft->card->title,
                'description' => $draft->card->description,
            ];
            if ($blob !== []) {
                $external['thumb'] = $blob;
            }
            return ['$type' => 'app.bsky.embed.external', 'external' => $external];
        }
        return null;
    }

    /**
     * @return array<string, string|int>|null
     */
    private function session(HttpResponse $response): ?array
    {
        if (!$response->success()) {
            return null;
        }
        $data = $response->json();
        $access = self::str($data['accessJwt'] ?? '');
        $refresh = self::str($data['refreshJwt'] ?? '');
        $did = self::str($data['did'] ?? '');
        if ($access === '' || $did === '') {
            return null;
        }
        return [
            'access_jwt' => $access,
            'refresh_jwt' => $refresh,
            'did' => $did,
            'access_exp' => self::jwtExpiry($access, $this->clock->now()),
        ];
    }

    /**
     * Expiry from a JWT payload; a short default when it cannot be read.
     */
    public static function jwtExpiry(string $jwt, int $now): int
    {
        $parts = explode('.', $jwt);
        if (count($parts) === 3) {
            $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
            if (is_string($json)) {
                try {
                    $payload = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $payload = null;
                }
                if (is_array($payload) && isset($payload['exp']) && is_numeric($payload['exp'])) {
                    return (int) $payload['exp'];
                }
            }
        }
        return $now + 600;
    }

    protected function isAuthError(HttpResponse $response): bool
    {
        if ($response->status !== 400) {
            return false;
        }
        $error = self::str($response->json()['error'] ?? '');
        return in_array($error, ['ExpiredToken', 'InvalidToken', 'AuthenticationRequired', 'AccountTakedown'], true);
    }

    private function service(Credentials $credentials): string
    {
        $service = rtrim($credentials->get('service'), '/');
        return $service === '' ? self::DEFAULT_SERVICE : $service;
    }

    private function postUrl(string $did, string $rkey): string
    {
        return 'https://bsky.app/profile/' . rawurlencode($did) . '/post/' . rawurlencode($rkey);
    }
}
