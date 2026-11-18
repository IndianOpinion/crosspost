<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Media;

/**
 * Checks that a URL is one a network can fetch: HTTPS, on a host that
 * resolves only to public addresses.
 */
final class PublicUrl
{
    /**
     * Special-purpose ranges a network cannot reach, beyond PHP's private
     * and reserved flags.
     *
     * @var list<string>
     */
    private const BLOCKED = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '::/128', '::1/128', '100::/64', '2001::/23', '2001:db8::/32', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

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
        // An IPv6 address that carries an IPv4 one (mapped, compatible,
        // NAT64, 6to4) is judged by the IPv4 address inside it.
        $embedded = self::embeddedIpv4($ip);
        if ($embedded !== null) {
            return self::isPublic($embedded);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        // Ranges PHP's flags do not cover on every version.
        foreach (self::BLOCKED as $cidr) {
            if (self::inCidr($ip, $cidr)) {
                return false;
            }
        }
        return true;
    }

    /**
     * The IPv4 address inside an IPv6 one, or null.
     */
    private static function embeddedIpv4(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return null;
        }
        $bin = inet_pton($ip);
        if ($bin === false || strlen($bin) !== 16) {
            return null;
        }
        $v4 = null;
        if (self::inCidr($ip, '::ffff:0:0/96') || self::inCidr($ip, '64:ff9b::/96') || self::inCidr($ip, '64:ff9b:1::/48')) {
            $v4 = substr($bin, 12, 4);
        } elseif (self::inCidr($ip, '::/96') && substr($bin, 12, 4) !== "\0\0\0\0" && substr($bin, 12, 4) !== "\0\0\0\1") {
            $v4 = substr($bin, 12, 4);
        } elseif (self::inCidr($ip, '2002::/16')) {
            $v4 = substr($bin, 2, 4);
        }
        if ($v4 === null) {
            return null;
        }
        $text = inet_ntop($v4);
        return $text === false ? null : $text;
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
