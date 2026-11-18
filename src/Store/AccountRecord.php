<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Store;

/**
 * Local state of one connected account: health, cooldown, last post.
 */
final class AccountRecord
{
    public const OK = 'ok';
    public const ATTENTION = 'attention';

    public int $id = 0;
    public string $network = '';
    public string $accountId = '';
    public string $displayName = '';
    public string $status = self::OK;
    public string $reason = '';
    public int $cooldownUntil = 0;
    public int $notifiedAt = 0;
    public int $connectedAt = 0;
    public int $lastPostedAt = 0;

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $a = new self();
        $a->id = (int) ($row['id'] ?? 0);
        $a->network = (string) ($row['network'] ?? '');
        $a->accountId = (string) ($row['account_id'] ?? '');
        $a->displayName = (string) ($row['display_name'] ?? '');
        $a->status = (string) ($row['status'] ?? self::OK);
        $a->reason = (string) ($row['reason'] ?? '');
        $a->cooldownUntil = (int) ($row['cooldown_until'] ?? 0);
        $a->notifiedAt = (int) ($row['notified_at'] ?? 0);
        $a->connectedAt = (int) ($row['connected_at'] ?? 0);
        $a->lastPostedAt = (int) ($row['last_posted_at'] ?? 0);
        return $a;
    }

    /**
     * @return array<string, string|int>
     */
    public function toRow(): array
    {
        return [
            'network' => $this->network,
            'account_id' => $this->accountId,
            'display_name' => $this->displayName,
            'status' => $this->status,
            'reason' => $this->reason,
            'cooldown_until' => $this->cooldownUntil,
            'notified_at' => $this->notifiedAt,
            'connected_at' => $this->connectedAt,
            'last_posted_at' => $this->lastPostedAt,
        ];
    }
}
