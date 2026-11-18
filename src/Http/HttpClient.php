<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Http;

use IndianOpinion\Crosspost\Support\Clock;

/**
 * The only way this plugin talks to a network. Wraps the WordPress HTTP API.
 *
 * - Checks the kill switch before every request, so no code path can make
 *   an outbound call while it is on.
 * - Never follows redirects on write calls, whatever a filter returns.
 * - Splits transport errors into "did not reach the server" and "may have
 *   reached the server", which decides retry versus uncertain.
 */
final class HttpClient
{
    /** @var \Closure(): bool */
    private \Closure $killSwitch;

    /**
     * @param callable(): bool $killSwitch
     */
    public function __construct(callable $killSwitch, private Clock $clock, private int $timeout = 15)
    {
        $this->killSwitch = \Closure::fromCallable($killSwitch);
    }

    /**
     * @param array<string, string> $headers
     * @param string|array<string, mixed>|null $body
     */
    public function request(
        string $network,
        string $purpose,
        string $method,
        string $url,
        array $headers = [],
        string|array|null $body = null,
        bool $write = false,
    ): HttpResponse {
        if (($this->killSwitch)()) {
            return new HttpResponse(0, [], '', HttpResponse::BLOCKED, 'kill switch');
        }

        $args = [
            'method' => $method,
            'timeout' => $this->timeout,
            'redirection' => $write ? 0 : 3,
            'headers' => $headers + ['Accept' => 'application/json'],
            'user-agent' => 'crosspost/' . (defined('CROSSPOST_VERSION') ? (string) constant('CROSSPOST_VERSION') : '0') . '; WordPress',
            'sslverify' => true,
        ];
        if ($body !== null) {
            $args['body'] = $body;
        }
        $filtered = apply_filters('crosspost_http_args', $args, $network, $purpose);
        if (is_array($filtered)) {
            $args = array_merge($args, $filtered);
        }
        if ($write) {
            $args['redirection'] = 0;
        }
        $args['method'] = $method;

        $start = $this->clock->micros();
        $raw = wp_remote_request($url, $args);
        $duration = (int) max(0, round(($this->clock->micros() - $start) / 1000));

        if (is_wp_error($raw)) {
            $message = $raw->get_error_message();
            return new HttpResponse(0, [], '', self::classify($message), $message, $duration);
        }

        $status = (int) wp_remote_retrieve_response_code($raw);
        $body = (string) wp_remote_retrieve_body($raw);
        return new HttpResponse($status, self::headers($raw), $body, HttpResponse::OK, '', $duration);
    }

    /**
     * Decide whether a transport error happened before the request could
     * reach the server. Anything we cannot prove was "before" is "read",
     * which is the safe side: it never causes a duplicate.
     */
    public static function classify(string $message): string
    {
        if (preg_match('/cURL error (\d+)/', $message, $m) === 1) {
            $code = (int) $m[1];
            // 5 proxy resolve, 6 host resolve, 7 connect, 35 TLS handshake,
            // 51/60 certificate, 58/59 local cert or cipher, 83 issuer, 90/91 pinning and status.
            if (in_array($code, [5, 6, 7, 35, 51, 53, 54, 58, 59, 60, 66, 77, 80, 83, 90, 91], true)) {
                return HttpResponse::CONNECT;
            }
            if ($code === 28 && preg_match('/(Connection|Resolving) timed out/i', $message) === 1) {
                return HttpResponse::CONNECT;
            }
            return HttpResponse::READ;
        }
        if (preg_match('/could not resolve|name or service not known|connection refused|ssl certificate|failed to connect/i', $message) === 1) {
            return HttpResponse::CONNECT;
        }
        return HttpResponse::READ;
    }

    /**
     * @param array<mixed> $response
     * @return array<string, string>
     */
    private static function headers(array $response): array
    {
        $raw = wp_remote_retrieve_headers($response);
        $out = [];
        if (is_iterable($raw)) {
            foreach ($raw as $name => $value) {
                if (is_array($value)) {
                    $value = implode(', ', array_map('strval', $value));
                }
                $out[strtolower((string) $name)] = is_scalar($value) ? (string) $value : '';
            }
        }
        return $out;
    }
}
