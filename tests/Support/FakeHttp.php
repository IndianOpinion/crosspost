<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Tests\Support;

use IndianOpinion\Crosspost\Tests\Stubs\State;

/**
 * Scripted responses through pre_http_request, matched in order by method
 * and a URL pattern. A route can answer once or every time.
 */
final class FakeHttp
{
    /** @var list<array{method: string, pattern: string, response: mixed, times: int}> */
    private array $routes = [];

    public function __construct()
    {
        add_filter('pre_http_request', function ($pre, array $args, string $url) {
            $method = (string) ($args['method'] ?? 'GET');
            foreach ($this->routes as $i => $route) {
                if ($route['method'] !== $method || preg_match($route['pattern'], $url) !== 1) {
                    continue;
                }
                if ($route['times'] === 1) {
                    array_splice($this->routes, $i, 1);
                } elseif ($route['times'] > 1) {
                    $this->routes[$i]['times']--;
                }
                $response = $route['response'];
                return is_callable($response) ? $response($args, $url) : $response;
            }
            throw new \LogicException('No scripted response for ' . $method . ' ' . $url);
        }, 10, 3);
    }

    /**
     * @param mixed $response a response array, a WP_Error or a callable
     */
    public function on(string $method, string $pattern, mixed $response, int $times = 1): self
    {
        $this->routes[] = ['method' => $method, 'pattern' => $pattern, 'response' => $response, 'times' => $times];
        return $this;
    }

    /**
     * @param array<mixed>|string $body
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public static function json(int $status, array|string $body = [], array $headers = []): array
    {
        return [
            'response' => ['code' => $status, 'message' => ''],
            'headers' => $headers,
            'body' => is_string($body) ? $body : (string) json_encode($body),
        ];
    }

    public static function error(string $message): \WP_Error
    {
        return new \WP_Error('http_request_failed', $message);
    }

    /**
     * @return list<array{method: string, url: string, headers: array<string, string>, body: mixed, args: array<string, mixed>}>
     */
    public static function requests(string $pattern = '/./', ?string $method = null): array
    {
        return array_values(array_filter(State::$requests, static fn (array $r): bool => preg_match($pattern, $r['url']) === 1 && ($method === null || $r['method'] === $method)));
    }
}
