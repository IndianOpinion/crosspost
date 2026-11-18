<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost;

/**
 * Non-secret settings, in one autoloaded option.
 *
 * Every value is read with its default's type. A stored value of the wrong
 * type falls back to the default rather than leaking into the logic.
 */
final class Settings
{
    public const OPTION = 'crosspost_settings';
    public const KILL_CONSTANT = 'CROSSPOST_KILL_SWITCH';

    public const IMAGE_ATTACH = 'attach';
    public const IMAGE_CARD = 'card';
    public const IMAGE_NONE = 'none';

    public const ON_IMAGE_FAIL_TEXT = 'text';
    public const ON_IMAGE_FAIL_RETRY = 'retry';

    /** @var list<string> */
    public const VISIBILITIES = ['public', 'unlisted', 'private'];

    public const DEFAULT_TEMPLATE = "{title}\n\n{url}";

    /** @var array<string, mixed> */
    private array $values;

    /**
     * @param array<string, mixed> $values
     */
    public function __construct(array $values = [])
    {
        $this->values = $values;
    }

    public static function load(): self
    {
        $stored = get_option(self::OPTION, []);
        return new self(is_array($stored) ? $stored : []);
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        $networks = [];
        foreach (Network::ALL as $network) {
            $networks[$network] = self::networkDefaults($network);
        }
        return [
            'kill_switch' => false,
            'post_types' => ['post'],
            'max_age' => 86400,
            'first_delay' => 60,
            'jobs_per_run' => 4,
            'per_network_per_run' => 1,
            'run_budget' => 45,
            'max_queue_age' => 86400,
            'stale_sending' => 900,
            'reconcile_window' => 86400,
            'lock_ttl' => 600,
            'min_body' => 40,
            'log_retention_days' => 90,
            'job_retention_days' => 180,
            'notify_email' => '',
            'first_post_pause_days' => 90,
            'networks' => $networks,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function networkDefaults(string $network): array
    {
        return [
            'enabled' => false,
            'template' => self::DEFAULT_TEMPLATE,
            'image_mode' => $network === Network::X ? self::IMAGE_NONE : self::IMAGE_ATTACH,
            'require_alt' => true,
            'image_failure' => self::ON_IMAGE_FAIL_TEXT,
            'cap' => 10,
            'spacing' => 600,
            'utm' => false,
            'utm_campaign' => 'crosspost',
            'visibility' => 'public',
            'language' => '',
        ];
    }

    /**
     * The kill switch: the constant wins over the option.
     */
    public function killSwitch(): bool
    {
        if (self::killSwitchFromConstant()) {
            return (bool) constant(self::KILL_CONSTANT);
        }
        return $this->bool('kill_switch');
    }

    public static function killSwitchFromConstant(): bool
    {
        return defined(self::KILL_CONSTANT);
    }

    /**
     * @return list<string>
     */
    public function postTypes(): array
    {
        $types = $this->values['post_types'] ?? null;
        if (!is_array($types) || $types === []) {
            $types = ['post'];
        }
        $types = array_values(array_filter(array_map('strval', $types), static fn (string $t): bool => $t !== ''));
        $filtered = apply_filters('crosspost_post_types', $types);
        return is_array($filtered) ? array_values(array_map('strval', $filtered)) : $types;
    }

    public function int(string $key): int
    {
        $default = self::defaults()[$key] ?? 0;
        $value = $this->values[$key] ?? $default;
        if (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1)) {
            return (int) $value;
        }
        return is_int($default) ? $default : 0;
    }

    public function bool(string $key): bool
    {
        $value = $this->values[$key] ?? (self::defaults()[$key] ?? false);
        return self::toBool($value, (bool) (self::defaults()[$key] ?? false));
    }

    public function string(string $key): string
    {
        $value = $this->values[$key] ?? (self::defaults()[$key] ?? '');
        return is_string($value) ? $value : (string) (self::defaults()[$key] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    private function networkValues(string $network): array
    {
        $all = $this->values['networks'] ?? [];
        $own = is_array($all) && isset($all[$network]) && is_array($all[$network]) ? $all[$network] : [];
        return $own;
    }

    public function enabled(string $network): bool
    {
        return self::toBool($this->networkValues($network)['enabled'] ?? false, false);
    }

    public function anyEnabled(): bool
    {
        foreach (Network::ALL as $network) {
            if ($this->enabled($network)) {
                return true;
            }
        }
        return false;
    }

    public function template(string $network): string
    {
        $value = $this->networkValues($network)['template'] ?? null;
        return is_string($value) && trim($value) !== '' ? str_replace("\r\n", "\n", $value) : self::DEFAULT_TEMPLATE;
    }

    public function imageMode(string $network): string
    {
        if ($network === Network::X) {
            return self::IMAGE_NONE;
        }
        $value = $this->networkValues($network)['image_mode'] ?? null;
        $allowed = $network === Network::BLUESKY ? [self::IMAGE_ATTACH, self::IMAGE_CARD, self::IMAGE_NONE] : [self::IMAGE_ATTACH, self::IMAGE_NONE];
        return is_string($value) && in_array($value, $allowed, true) ? $value : (string) self::networkDefaults($network)['image_mode'];
    }

    public function requireAlt(string $network): bool
    {
        return self::toBool($this->networkValues($network)['require_alt'] ?? true, true);
    }

    public function imageFailure(string $network): string
    {
        $value = $this->networkValues($network)['image_failure'] ?? null;
        return $value === self::ON_IMAGE_FAIL_RETRY ? self::ON_IMAGE_FAIL_RETRY : self::ON_IMAGE_FAIL_TEXT;
    }

    /**
     * The daily cap, never above the network's hard ceiling.
     */
    public function cap(string $network): int
    {
        $value = $this->networkValues($network)['cap'] ?? 10;
        $cap = is_numeric($value) ? (int) $value : 10;
        $filtered = apply_filters('crosspost_daily_cap', $cap, $network);
        $cap = is_numeric($filtered) ? (int) $filtered : $cap;
        return max(0, min($cap, Network::ceiling($network)));
    }

    public function spacing(string $network): int
    {
        $value = $this->networkValues($network)['spacing'] ?? 600;
        return is_numeric($value) ? max(0, (int) $value) : 600;
    }

    public function utm(string $network): bool
    {
        return self::toBool($this->networkValues($network)['utm'] ?? false, false);
    }

    public function utmCampaign(string $network): string
    {
        $value = $this->networkValues($network)['utm_campaign'] ?? 'crosspost';
        return is_string($value) && $value !== '' ? $value : 'crosspost';
    }

    public function visibility(string $network): string
    {
        $value = $this->networkValues($network)['visibility'] ?? 'public';
        return is_string($value) && in_array($value, self::VISIBILITIES, true) ? $value : 'public';
    }

    public function language(string $network): string
    {
        $value = $this->networkValues($network)['language'] ?? '';
        return is_string($value) ? $value : '';
    }

    /**
     * Hash of every setting that changes what a network receives. A change
     * after the preview was confirmed puts the network back to review.
     */
    public function outputHash(string $network): string
    {
        return hash('sha256', (string) json_encode([
            $this->template($network),
            $this->imageMode($network),
            $this->requireAlt($network),
            $this->utm($network),
            $this->utmCampaign($network),
            $network === Network::MASTODON ? $this->visibility($network) : '',
            $this->language($network),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * Clean a submitted settings array, keeping $current for anything not
     * submitted. Caps are clamped to the ceiling; "direct" visibility is
     * refused.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $current
     * @return array<string, mixed>
     */
    public static function sanitize(array $input, array $current): array
    {
        $defaults = self::defaults();
        $out = array_merge($defaults, $current);
        foreach (['max_age', 'first_delay', 'jobs_per_run', 'run_budget', 'max_queue_age', 'stale_sending', 'reconcile_window', 'log_retention_days', 'job_retention_days', 'first_post_pause_days', 'min_body'] as $key) {
            if (isset($input[$key]) && is_numeric($input[$key])) {
                $out[$key] = max(0, (int) $input[$key]);
            }
        }
        $out['jobs_per_run'] = max(1, (int) $out['jobs_per_run']);
        $out['per_network_per_run'] = 1;
        if (array_key_exists('kill_switch', $input)) {
            $out['kill_switch'] = self::toBool($input['kill_switch'], false);
        }
        if (isset($input['post_types'])) {
            $types = is_array($input['post_types']) ? $input['post_types'] : explode(',', (string) $input['post_types']);
            $clean = [];
            foreach ($types as $type) {
                $type = sanitize_key((string) $type);
                if ($type !== '') {
                    $clean[] = $type;
                }
            }
            $out['post_types'] = $clean === [] ? ['post'] : array_values(array_unique($clean));
        }
        if (isset($input['notify_email'])) {
            $email = sanitize_email((string) $input['notify_email']);
            $out['notify_email'] = is_email($email) ? $email : '';
        }
        $networks = is_array($out['networks'] ?? null) ? $out['networks'] : [];
        $inputNetworks = is_array($input['networks'] ?? null) ? $input['networks'] : [];
        foreach (Network::ALL as $network) {
            $now = array_merge(self::networkDefaults($network), is_array($networks[$network] ?? null) ? $networks[$network] : []);
            $in = is_array($inputNetworks[$network] ?? null) ? $inputNetworks[$network] : [];
            if (array_key_exists('enabled', $in)) {
                $now['enabled'] = self::toBool($in['enabled'], false);
            }
            if (isset($in['template']) && is_string($in['template'])) {
                $template = str_replace("\r\n", "\n", wp_unslash($in['template']));
                $template = (string) preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/', '', $template);
                $now['template'] = trim($template) === '' ? self::DEFAULT_TEMPLATE : $template;
            }
            if (isset($in['image_mode']) && in_array($in['image_mode'], [self::IMAGE_ATTACH, self::IMAGE_CARD, self::IMAGE_NONE], true)) {
                $now['image_mode'] = $in['image_mode'] === self::IMAGE_CARD && $network !== Network::BLUESKY ? self::IMAGE_ATTACH : $in['image_mode'];
            }
            if ($network === Network::X) {
                $now['image_mode'] = self::IMAGE_NONE;
            }
            if (array_key_exists('require_alt', $in)) {
                $now['require_alt'] = self::toBool($in['require_alt'], true);
            }
            if (isset($in['image_failure'])) {
                $now['image_failure'] = $in['image_failure'] === self::ON_IMAGE_FAIL_RETRY ? self::ON_IMAGE_FAIL_RETRY : self::ON_IMAGE_FAIL_TEXT;
            }
            if (isset($in['cap']) && is_numeric($in['cap'])) {
                $now['cap'] = max(0, min((int) $in['cap'], Network::ceiling($network)));
            }
            if (isset($in['spacing']) && is_numeric($in['spacing'])) {
                $now['spacing'] = max(0, (int) $in['spacing']);
            }
            if (array_key_exists('utm', $in)) {
                $now['utm'] = self::toBool($in['utm'], false);
            }
            if (isset($in['utm_campaign']) && is_string($in['utm_campaign'])) {
                $campaign = sanitize_key($in['utm_campaign']);
                $now['utm_campaign'] = $campaign === '' ? 'crosspost' : $campaign;
            }
            if (isset($in['visibility']) && is_string($in['visibility']) && in_array($in['visibility'], self::VISIBILITIES, true)) {
                $now['visibility'] = $in['visibility'];
            }
            if (isset($in['language']) && is_string($in['language'])) {
                $lang = preg_replace('/[^A-Za-z0-9-]/', '', $in['language']) ?? '';
                $now['language'] = substr($lang, 0, 16);
            }
            $networks[$network] = $now;
        }
        $out['networks'] = $networks;
        return $out;
    }

    public static function toBool(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            $v = strtolower(trim($value));
            if (in_array($v, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($v, ['0', 'false', 'no', 'off', ''], true)) {
                return false;
            }
        }
        return $default;
    }
}
