<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Media;

/**
 * Checks that a URL is one a network can fetch: HTTPS, on a host that
 * resolves only to public addresses.
 */
final class PublicUrl
{
    /** @var \Closure(string): list<string> */
    private \Closure $resolve;

    /**
     * @param (callable(string): list<string>)|null $resolve host to IP addresses
     */
    public function __construct(?callable $resolve = null)
    {
        $this->resolve = $resolve !== null
            ? \Closure::fromCallable($resolve)
            : static function (string $host): array {
                $v4 = gethostbynamel($host);
                $out = $v4 === false ? [] : $v4;
                if (function_exists('dns_get_record')) {
                    $records = dns_get_record($host, DNS_AAAA);
                    foreach (is_array($records) ? $records : [] as $record) {
                        if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                            $out[] = $record['ipv6'];
                        }
                    }
                }
                return $out;
            };
    }

    /**
     * Empty string when the URL is fine, otherwise the reason.
     */
    public function problem(string $url): string
    {
        if ($url === '') {
            return 'no public URL';
        }
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https') {
            return 'not HTTPS';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'URL carries credentials';
        }
        $host = strtolower(trim($parts['host'] ?? '', '[]'));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return 'host is not public';
        }
        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolve)($host);
        if ($addresses === []) {
            return 'host does not resolve';
        }
        foreach ($addresses as $ip) {
            if (!self::isPublic($ip)) {
                return 'host resolves to a private address';
            }
        }
        return '';
    }

    public static function isPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        // Ranges PHP's flags do not cover on every version.
        $blocked = ['100.64.0.0/10', '169.254.0.0/16', '127.0.0.0/8', '0.0.0.0/8', 'fe80::/10', 'fc00::/7', '::1/128', '::/128'];
        foreach ($blocked as $cidr) {
            if (self::inCidr($ip, $cidr)) {
                return false;
            }
        }
        return true;
    }

    private static function inCidr(string $ip, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $ipBin = inet_pton($ip);
        $netBin = inet_pton($net);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }
}
