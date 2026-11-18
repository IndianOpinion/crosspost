<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost;

use IndianOpinion\Crosspost\Store\AccountRecord;
use IndianOpinion\Crosspost\Store\Store;
use IndianOpinion\Crosspost\Support\Clock;

/**
 * Marks a network as needing attention and tells a person, at most once per
 * network per day.
 */
final class Notifier
{
    public const NOTICES_OPTION = 'crosspost_notices';
    public const INTERVAL = 86400;

    public function __construct(private Store $store, private Clock $clock, private Logger $logger)
    {
    }

    public function attention(string $network, string $accountId, string $reason, Settings $settings): void
    {
        $now = $this->clock->now();
        $account = $this->store->account($network, $accountId) ?? self::fresh($network, $accountId, $now);
        $account->status = AccountRecord::ATTENTION;
        $account->reason = $this->logger->scrub($reason);
        $notify = $account->notifiedAt <= $now - self::INTERVAL;
        if ($notify) {
            $account->notifiedAt = $now;
        }
        $this->store->saveAccount($account);
        do_action('crosspost_network_needs_attention', $network, $account->reason);
        if (!$notify) {
            return;
        }
        $this->notice($network, sprintf('Crosspost: %s needs attention: %s. Reconnect it in Settings > Crosspost.', Network::label($network), $account->reason));
        $email = $settings->string('notify_email');
        if ($email !== '' && is_email($email)) {
            wp_mail(
                $email,
                sprintf('[%s] Crosspost: %s needs attention', wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES), Network::label($network)),
                sprintf("%s stopped posting: %s\n\nReconnect it in Settings > Crosspost. Nothing will be sent to it until then.\n", Network::label($network), $account->reason),
            );
        }
    }

    public function clear(string $network, string $accountId): void
    {
        $account = $this->store->account($network, $accountId);
        if ($account !== null && $account->status !== AccountRecord::OK) {
            $account->status = AccountRecord::OK;
            $account->reason = '';
            $this->store->saveAccount($account);
        }
        $notices = $this->notices();
        unset($notices[$network]);
        update_option(self::NOTICES_OPTION, $notices, false);
    }

    /**
     * One notice per key, replaced in place, so the list never grows.
     */
    public function notice(string $key, string $message): void
    {
        $notices = $this->notices();
        $notices[$key] = ['message' => $this->logger->scrub($message), 'time' => $this->clock->now()];
        update_option(self::NOTICES_OPTION, $notices, false);
    }

    public function dismiss(string $key): void
    {
        $notices = $this->notices();
        unset($notices[$key]);
        update_option(self::NOTICES_OPTION, $notices, false);
    }

    /**
     * @return array<string, array{message: string, time: int}>
     */
    public function notices(): array
    {
        $stored = get_option(self::NOTICES_OPTION, []);
        $out = [];
        foreach (is_array($stored) ? $stored : [] as $key => $notice) {
            if (is_string($key) && is_array($notice) && isset($notice['message']) && is_string($notice['message'])) {
                $out[$key] = ['message' => $notice['message'], 'time' => (int) ($notice['time'] ?? 0)];
            }
        }
        return $out;
    }

    public static function fresh(string $network, string $accountId, int $now): AccountRecord
    {
        $account = new AccountRecord();
        $account->network = $network;
        $account->accountId = $accountId;
        $account->connectedAt = $now;
        return $account;
    }
}
