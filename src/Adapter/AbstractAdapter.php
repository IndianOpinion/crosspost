<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Adapter;

use IndianOpinion\Crosspost\Http\HttpClient;
use IndianOpinion\Crosspost\Http\HttpResponse;
use IndianOpinion\Crosspost\Network;
use IndianOpinion\Crosspost\Support\Clock;
use IndianOpinion\Crosspost\Support\Scrubber;
use IndianOpinion\Crosspost\Support\Sleeper;
use IndianOpinion\Crosspost\Text\Counter;
use IndianOpinion\Crosspost\Value\Credentials;
use IndianOpinion\Crosspost\Value\Draft;
use IndianOpinion\Crosspost\Value\Outcome;

/**
 * Shared plumbing: HTTP, outcome classification, the length assertion.
 */
abstract class AbstractAdapter implements Adapter
{
    public function __construct(
        protected HttpClient $http,
        protected Clock $clock,
        protected Sleeper $sleeper,
    ) {
    }

    public function label(): string
    {
        return Network::label($this->id());
    }

    public function count(string $text): int
    {
        $limits = $this->limits();
        return Counter::count($limits->countingRule, $text);
    }

    /**
     * The length rule is asserted on the exact string at the point of
     * sending, whoever calls.
     */
    protected function lengthOutcome(Draft $draft): ?Outcome
    {
        $limit = $this->limits()->textLimit;
        $count = $this->count($draft->text);
        if (trim($draft->text) === '') {
            return Outcome::permanent('text is empty');
        }
        if ($count > $limit) {
            return Outcome::permanent(sprintf('text too long: %d of %d', $count, $limit));
        }
        return null;
    }

    /**
     * Map a response to an outcome.
     *
     * @param bool $finalCreate true for the call that creates the post
     */
    protected function classify(HttpResponse $response, bool $finalCreate, Credentials $credentials): Outcome
    {
        $message = $this->message($response, $credentials);
        if ($response->errorKind === HttpResponse::BLOCKED) {
            return Outcome::blocked();
        }
        if ($response->errorKind === HttpResponse::CONNECT) {
            return Outcome::retryable($message);
        }
        if ($response->errorKind === HttpResponse::READ) {
            return $finalCreate ? Outcome::uncertain($message) : Outcome::retryable($message);
        }
        $status = $response->status;
        if ($status === 429) {
            return Outcome::retryable($message, $status, $this->resetTime($response), true);
        }
        if ($status === 401 || $this->isAuthError($response)) {
            return Outcome::auth($message, $status);
        }
        if ($status >= 500) {
            return $finalCreate ? Outcome::uncertain($message, $status) : Outcome::retryable($message, $status, $this->retryAfter($response));
        }
        if ($status >= 400) {
            return Outcome::permanent($message, $status);
        }
        if ($status >= 300) {
            // Write calls do not follow redirects; a redirect is a misconfiguration.
            return Outcome::permanent('unexpected redirect ' . $status, $status);
        }
        return Outcome::permanent('unexpected response', $status);
    }

    /**
     * Network-specific auth errors carried in a 400 or 403 body.
     */
    protected function isAuthError(HttpResponse $response): bool
    {
        return false;
    }

    /**
     * Rate-limit reset time as a Unix timestamp; 0 when unknown.
     */
    protected function resetTime(HttpResponse $response): int
    {
        $retry = $this->retryAfter($response);
        $reset = 0;
        foreach (['x-rate-limit-reset', 'ratelimit-reset', 'x-ratelimit-reset'] as $name) {
            $value = $response->header($name);
            if ($value === '') {
                continue;
            }
            if (ctype_digit($value)) {
                $n = (int) $value;
                // Some networks send seconds until reset, others an epoch.
                $reset = $n > 1000000000 ? $n : $this->clock->now() + $n;
            } else {
                $time = strtotime($value);
                $reset = $time === false ? 0 : $time;
            }
            break;
        }
        return max($retry, $reset);
    }

    protected function retryAfter(HttpResponse $response): int
    {
        $value = $response->header('retry-after');
        if ($value === '') {
            return 0;
        }
        if (ctype_digit($value)) {
            return $this->clock->now() + (int) $value;
        }
        $time = strtotime($value);
        return $time === false ? 0 : $time;
    }

    /**
     * A short, scrubbed description of the response for the log.
     */
    protected function message(HttpResponse $response, Credentials $credentials): string
    {
        $scrubber = new Scrubber($credentials->secrets());
        if ($response->failed()) {
            return $scrubber->scrub($response->errorMessage);
        }
        $data = $response->json();
        $parts = [];
        foreach (['error', 'error_description', 'message', 'detail', 'title'] as $key) {
            $value = $data[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $parts[] = $value;
            } elseif (is_array($value) && isset($value['message']) && is_string($value['message'])) {
                $parts[] = $value['message'];
            }
        }
        if (isset($data['errors']) && is_array($data['errors'])) {
            foreach ($data['errors'] as $error) {
                if (is_array($error)) {
                    foreach (['message', 'detail'] as $key) {
                        if (isset($error[$key]) && is_string($error[$key])) {
                            $parts[] = $error[$key];
                            break;
                        }
                    }
                }
            }
        }
        $text = $parts === [] ? 'HTTP ' . $response->status : implode(': ', array_unique($parts));
        return $scrubber->scrub($text);
    }

    /**
     * @return array<string, string>
     */
    protected static function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    /**
     * @param array<mixed> $data
     */
    protected static function encode(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    protected static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
