<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost;

use IndianOpinion\Crosspost\Adapter\BlueskyAdapter;
use IndianOpinion\Crosspost\Exception\ConfigurationException;
use IndianOpinion\Crosspost\Exception\CredentialsException;
use IndianOpinion\Crosspost\Value\Account;
use IndianOpinion\Crosspost\Value\Credentials;

/**
 * Credentials per network: one non-autoloaded option each, with constants
 * in wp-config.php winning over stored values.
 *
 * Only values that do not rotate may be constants. Rotating tokens (X
 * refresh tokens, Threads long-lived tokens, Bluesky sessions) are always
 * stored, because the plugin must write the new value after a refresh.
 */
final class CredentialStore
{
    public const OPTION_PREFIX = 'crosspost_credentials_';

    /**
     * Field => constant name.
     *
     * @var array<string, array<string, string>>
     */
    public const CONSTANTS = [
        Network::X => [
            'client_id' => 'CROSSPOST_X_CLIENT_ID',
            'client_secret' => 'CROSSPOST_X_CLIENT_SECRET',
        ],
        Network::THREADS => [
            'app_id' => 'CROSSPOST_THREADS_APP_ID',
            'app_secret' => 'CROSSPOST_THREADS_APP_SECRET',
        ],
        Network::BLUESKY => [
            'service' => 'CROSSPOST_BLUESKY_SERVICE',
            'identifier' => 'CROSSPOST_BLUESKY_IDENTIFIER',
            'app_password' => 'CROSSPOST_BLUESKY_APP_PASSWORD',
        ],
        Network::MASTODON => [
            'instance' => 'CROSSPOST_MASTODON_INSTANCE',
            'access_token' => 'CROSSPOST_MASTODON_TOKEN',
        ],
    ];

    /**
     * Fields an operator may type into the settings screen.
     *
     * @var array<string, list<string>>
     */
    public const EDITABLE = [
        Network::X => ['client_id', 'client_secret'],
        Network::THREADS => ['app_id', 'app_secret'],
        Network::BLUESKY => ['service', 'identifier', 'app_password'],
        Network::MASTODON => ['instance', 'access_token'],
    ];

    /**
     * Fields that decide which account is connected. A change resets
     * verification and the first post review.
     *
     * @var array<string, list<string>>
     */
    public const IDENTITY = [
        Network::X => ['client_id'],
        Network::THREADS => ['app_id'],
        Network::BLUESKY => ['service', 'identifier'],
        Network::MASTODON => ['instance', 'access_token'],
    ];

    /** Fields that hold a usable credential, per network. */
    private const REQUIRED = [
        Network::X => ['access_token'],
        Network::THREADS => ['access_token'],
        Network::BLUESKY => ['identifier', 'app_password'],
        Network::MASTODON => ['instance', 'access_token'],
    ];

    public function get(string $network): Credentials
    {
        Network::assertValid($network);
        $stored = get_option(self::OPTION_PREFIX . $network, []);
        $values = [];
        foreach (is_array($stored) ? $stored : [] as $field => $value) {
            if (is_string($field) && (is_string($value) || is_int($value))) {
                $values[$field] = $value;
            }
        }
        $fromConstant = [];
        foreach (self::CONSTANTS[$network] as $field => $constant) {
            if (defined($constant)) {
                $value = constant($constant);
                if (is_string($value) && $value !== '') {
                    $values[$field] = $value;
                    $fromConstant[$field] = true;
                }
            }
        }
        return new Credentials($network, $values, $fromConstant);
    }

    /**
     * Store refreshed tokens in one write. Keeps verification.
     */
    public function save(Credentials $credentials): void
    {
        update_option(self::OPTION_PREFIX . $credentials->network(), $credentials->storable(), false);
    }

    /**
     * Replace operator-entered fields. Validates first; resets verification
     * when an identity field changed.
     *
     * @param array<string, string> $input
     */
    public function replace(string $network, array $input): Credentials
    {
        $current = $this->get($network);
        $changes = [];
        foreach (self::EDITABLE[$network] as $field) {
            if (!array_key_exists($field, $input) || $current->fromConstant($field)) {
                continue;
            }
            $value = trim($input[$field]);
            // An empty secret field in a form means "keep what is stored".
            if ($value === '' && in_array($field, ['client_secret', 'app_secret', 'app_password', 'access_token'], true)) {
                continue;
            }
            $changes[$field] = $value;
        }
        self::validate($network, $changes + $current->storable());
        $next = $current->with($changes);
        if ($this->fingerprint($next) !== $current->get('verified_fp')) {
            $next = $next->with(['verified_account' => '', 'verified_name' => '', 'verified_at' => 0, 'verified_fp' => '']);
            if ($network === Network::BLUESKY) {
                $next = $next->with(['access_jwt' => '', 'refresh_jwt' => '', 'did' => '', 'access_exp' => 0]);
            }
        }
        $this->save($next);
        return $this->get($network);
    }

    /**
     * Record a successful verify.
     */
    public function markVerified(Credentials $credentials, Account $account, int $now): Credentials
    {
        $next = $credentials->with([
            'verified_account' => $account->id,
            'verified_name' => $account->name,
            'verified_at' => $now,
            'verified_fp' => $this->fingerprint($credentials),
            'account_id' => $account->id,
        ]);
        if ($credentials->network() === Network::THREADS) {
            $next = $next->with(['user_id' => $account->id]);
        }
        $this->save($next);
        return $next;
    }

    public function isVerified(Credentials $credentials): bool
    {
        return $credentials->get('verified_account') !== ''
            && $credentials->get('verified_fp') === $this->fingerprint($credentials);
    }

    public function present(Credentials $credentials): bool
    {
        foreach (self::REQUIRED[$credentials->network()] ?? [] as $field) {
            if (!$credentials->has($field)) {
                return false;
            }
        }
        return true;
    }

    public function fingerprint(Credentials $credentials): string
    {
        $parts = [];
        foreach (self::IDENTITY[$credentials->network()] ?? [] as $field) {
            $parts[] = $credentials->get($field);
        }
        return hash('sha256', $credentials->network() . '|' . implode('|', $parts));
    }

    /**
     * Every secret across networks, for the scrubber.
     *
     * @return list<string>
     */
    public function allSecrets(): array
    {
        $out = [];
        foreach (Network::ALL as $network) {
            foreach ($this->get($network)->secrets() as $secret) {
                $out[] = $secret;
            }
        }
        return $out;
    }

    public function forget(string $network): void
    {
        delete_option(self::OPTION_PREFIX . $network);
    }

    /**
     * @param array<string, string|int> $values
     */
    public static function validate(string $network, array $values): void
    {
        if ($network === Network::BLUESKY) {
            $password = (string) ($values['app_password'] ?? '');
            if ($password !== '' && preg_match(BlueskyAdapter::APP_PASSWORD_PATTERN, $password) !== 1) {
                throw new CredentialsException('Use an app password from the account settings (four groups of four letters or digits), never the account password.');
            }
            $service = (string) ($values['service'] ?? '');
            if ($service !== '' && !self::isHttpsUrl($service)) {
                throw new ConfigurationException('The Bluesky service URL must use HTTPS.');
            }
        }
        if ($network === Network::MASTODON) {
            $instance = (string) ($values['instance'] ?? '');
            if ($instance !== '' && !self::isHttpsUrl($instance)) {
                throw new ConfigurationException('The Mastodon instance URL must use HTTPS.');
            }
        }
    }

    private static function isHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);
        return is_array($parts)
            && strtolower($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') !== ''
            && !isset($parts['user'])
            && !isset($parts['query']);
    }
}
