<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Value;

/**
 * A network's credentials and tokens.
 *
 * The values never leave this object as text: string casts, var_dump,
 * print_r and json_encode all show "[redacted]". Code that needs a value
 * asks for it by name with get().
 */
final class Credentials implements \JsonSerializable
{
    /**
     * Field names whose values are not secret (shown as-is in no place,
     * but safe to keep out of the scrubber's secret list).
     */
    private const PUBLIC_FIELDS = ['instance', 'service', 'account_id', 'did', 'token_expires_at', 'access_exp', 'user_id', 'identifier', 'client_id', 'app_id', 'verified_account', 'verified_name', 'verified_at', 'verified_fp', 'client_instance'];

    /**
     * @param array<string, string|int> $values
     * @param array<string, bool> $fromConstant field name => true when set by a constant
     */
    public function __construct(
        private string $network,
        private array $values,
        private array $fromConstant = [],
    ) {
    }

    public function network(): string
    {
        return $this->network;
    }

    public function get(string $field): string
    {
        $value = $this->values[$field] ?? '';
        return is_string($value) ? $value : (string) $value;
    }

    public function int(string $field): int
    {
        $value = $this->values[$field] ?? 0;
        return is_numeric($value) ? (int) $value : 0;
    }

    public function has(string $field): bool
    {
        return $this->get($field) !== '';
    }

    public function fromConstant(string $field): bool
    {
        return $this->fromConstant[$field] ?? false;
    }

    /**
     * @param array<string, string|int> $changes
     */
    public function with(array $changes): self
    {
        return new self($this->network, array_merge($this->values, $changes), $this->fromConstant);
    }

    /**
     * Values to store in the credentials option. Constants are never
     * copied into the database.
     *
     * @return array<string, string|int>
     */
    public function storable(): array
    {
        $out = [];
        foreach ($this->values as $field => $value) {
            if (!($this->fromConstant[$field] ?? false)) {
                $out[$field] = $value;
            }
        }
        return $out;
    }

    /**
     * Every secret value, for the scrubber.
     *
     * @return list<string>
     */
    public function secrets(): array
    {
        $out = [];
        foreach ($this->values as $field => $value) {
            if (in_array($field, self::PUBLIC_FIELDS, true) || !is_string($value) || $value === '') {
                continue;
            }
            $out[] = $value;
        }
        return $out;
    }

    /**
     * Presence only, for status screens.
     *
     * @return array<string, bool>
     */
    public function presence(): array
    {
        $out = [];
        foreach ($this->values as $field => $value) {
            $out[$field] = $value !== '' && $value !== 0;
        }
        return $out;
    }

    public function __toString(): string
    {
        return '[redacted]';
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['network' => $this->network, 'values' => '[redacted]'];
    }

    public function jsonSerialize(): string
    {
        return '[redacted]';
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('Credentials are never serialised.');
    }
}
