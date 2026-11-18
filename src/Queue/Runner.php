<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Queue;

use IndianOpinion\Crosspost\Adapter\Adapter;
use IndianOpinion\Crosspost\Crosspost;
use IndianOpinion\Crosspost\Exception\CrosspostException;
use IndianOpinion\Crosspost\Network;
use IndianOpinion\Crosspost\Notifier;
use IndianOpinion\Crosspost\Settings;
use IndianOpinion\Crosspost\Store\AccountRecord;
use IndianOpinion\Crosspost\Store\Job;
use IndianOpinion\Crosspost\Support\Deadline;
use IndianOpinion\Crosspost\Value\Credentials;
use IndianOpinion\Crosspost\Value\Draft;
use IndianOpinion\Crosspost\Value\LinkCard;
use IndianOpinion\Crosspost\Value\Limits;
use IndianOpinion\Crosspost\Value\MediaFile;
use IndianOpinion\Crosspost\Value\MediaRef;
use IndianOpinion\Crosspost\Value\Outcome;

/**
 * One processing run: lock, sweep, reconcile, send, schedule the next run.
 */
final class Runner
{
    /** @var list<int> */
    public const BACKOFF = [300, 900, 3600, 14400, 43200];
    public const UNCERTAIN_RECHECK = 300;
    public const IMAGE_WAIT = 30;

    private RunReport $report;

    /** @var array<string, Credentials> */
    private array $creds = [];

    /** @var array<string, bool> */
    private array $forcedRefresh = [];

    private Deadline $deadline;

    public function __construct(private Crosspost $app)
    {
        $this->report = new RunReport();
        $this->deadline = Deadline::in($app->clock, 45);
    }

    public function run(?string $only = null, ?int $limit = null): RunReport
    {
        $this->report = new RunReport();
        $app = $this->app;
        $settings = $app->settings();
        if ($only !== null) {
            Network::assertValid($only);
        }

        $missing = $app->missingColumns();
        if ($missing !== []) {
            $app->logger->event(null, 0, '', 'schema', 'sending refused, missing columns: ' . implode(', ', $missing));
            $app->notifier->notice('schema', 'Crosspost: sending is stopped because database columns are missing: ' . implode(', ', $missing) . '.');
            $this->report->stop(Job::R_SCHEMA);
            return $this->report;
        }
        if ($settings->killSwitch()) {
            $this->report->stop(Job::R_KILL_SWITCH);
            return $this->report;
        }
        if (!$settings->anyEnabled()) {
            $app->syncEvents();
            $this->report->stop('every network is off');
            return $this->report;
        }

        $now = $app->clock->now();
        $token = $app->store->acquireLock(Crosspost::LOCK, $now, max(60, $settings->int('lock_ttl')));
        if ($token === null) {
            $this->report->locked = true;
            return $this->report;
        }
        try {
            $this->deadline = Deadline::in($app->clock, max(5, $settings->int('run_budget')));
            $this->sweep($settings);
            $this->reconcile($settings, $only);
            if (!$this->report->stopped) {
                $this->sendDue($settings, $only, $limit ?? max(1, $settings->int('jobs_per_run')));
            }
        } finally {
            $app->store->releaseLock(Crosspost::LOCK, $token);
        }

        if ($this->report->stopReason !== Job::R_KILL_SWITCH) {
            $wake = $app->store->nextWake();
            if ($wake !== null) {
                $at = max($wake, $app->clock->now() + 30);
                $this->report->nextRun = $at;
                $app->schedule($at);
            }
        }
        return $this->report;
    }

    /**
     * Stuck "sending" jobs become uncertain (never pending); queued jobs past
     * the maximum queue age become stale.
     */
    private function sweep(Settings $settings): void
    {
        $app = $this->app;
        $now = $app->clock->now();
        $staleBefore = $now - max(60, $settings->int('stale_sending'));
        foreach ($app->store->jobs(['state' => Job::SENDING], 500) as $job) {
            if ($job->sentAt >= $staleBefore) {
                continue;
            }
            $this->markUncertain($job, 'no result recorded: the run stopped during the call', Job::SENDING);
        }
        $expired = $now - max(60, $settings->int('max_queue_age'));
        foreach ($app->store->jobs(['state' => Job::QUEUED], 500) as $job) {
            if ($job->createdAt >= $expired) {
                continue;
            }
            $from = $job->state;
            $job->state = Job::SKIPPED;
            $job->reason = Job::R_STALE;
            $job->updatedAt = $now;
            if ($app->store->updateJob($job, [$from])) {
                $app->logger->event($job->id, $job->postId, $job->network, 'skipped', Job::R_STALE);
                $this->report->skipped++;
            }
        }
    }

    private function reconcile(Settings $settings, ?string $only): void
    {
        $app = $this->app;
        foreach (Network::ALL as $network) {
            if ($only !== null && $network !== $only) {
                continue;
            }
            $jobs = $app->store->jobs(['state' => Job::UNCERTAIN, 'network' => $network], 100);
            if ($jobs === []) {
                continue;
            }
            $settings = $app->settings();
            if ($settings->killSwitch()) {
                $this->report->stop(Job::R_KILL_SWITCH);
                return;
            }
            if (!$settings->enabled($network)) {
                continue;
            }
            $now = $app->clock->now();
            $window = max(60, $settings->int('reconcile_window'));
            $open = [];
            foreach ($jobs as $job) {
                $since = $job->firstSentAt > 0 ? $job->firstSentAt : $job->sentAt;
                if ($now - $since > $window) {
                    // Never resent automatically: a late landing would be a duplicate.
                    $this->finish($job, Job::FAILED, Job::R_NEVER_LANDED, 'failed', [Job::UNCERTAIN]);
                    do_action('crosspost_failed', $job->id, $job->postId, $job->network, Job::R_NEVER_LANDED);
                    continue;
                }
                $open[$job->id] = $job;
            }
            if ($open === []) {
                continue;
            }
            // A network that needs attention or is cooling down after a 429
            // gets no call of any kind, listings and same-key resends included.
            $account = $app->accountRecord($network);
            if ($account !== null && ($account->status === AccountRecord::ATTENTION || $account->cooldownUntil > $now)) {
                foreach ($open as $job) {
                    $job->nextAttemptAt = max($now + self::UNCERTAIN_RECHECK, $account->cooldownUntil);
                    $job->updatedAt = $now;
                    $app->store->updateJob($job, [Job::UNCERTAIN]);
                }
                continue;
            }
            $adapter = $app->adapter($network);
            $creds = $this->credentials($adapter, $settings, false);
            if ($creds === null) {
                continue;
            }
            $drafts = [];
            foreach ($open as $job) {
                $drafts[] = $this->draft($job, $settings, null);
            }
            try {
                $outcomes = $adapter->reconcile($drafts, $creds);
            } catch (CrosspostException $e) {
                $message = $app->logger->scrub($e->getMessage());
                $outcomes = [];
                foreach ($open as $id => $job) {
                    $outcomes[$id] = Outcome::uncertain($message);
                }
            }
            foreach ($open as $id => $job) {
                $outcome = $outcomes[$id] ?? Outcome::uncertain('no answer');
                if ($outcome->is(Outcome::POSTED)) {
                    $this->posted($job, $outcome, 'reconciled', [Job::UNCERTAIN]);
                    $this->report->reconciled++;
                } elseif ($outcome->is(Outcome::ABSENT) && $adapter->limits()->idempotent()) {
                    // Same key: the network cannot make a second post.
                    if ($this->deadline->expired()) {
                        $this->recheckLater($job, 'resend waits for the next run');
                        continue;
                    }
                    $app->logger->event($job->id, $job->postId, $network, 'reconciled', 'not found; resending with the same key');
                    $this->send($job, $adapter, $settings, true);
                    if ($this->report->stopped) {
                        return;
                    }
                } else {
                    $this->recheckLater($job, $outcome->message);
                }
            }
        }
    }

    private function sendDue(Settings $settings, ?string $only, int $limit): void
    {
        $app = $this->app;
        $now = $app->clock->now();
        // Networks in order of their oldest due job.
        $order = [];
        foreach (Network::ALL as $network) {
            if ($only !== null && $network !== $only) {
                continue;
            }
            $due = $app->store->dueJobs($network, $now, 1);
            if ($due !== []) {
                $order[$network] = $due[0]->createdAt;
            }
        }
        asort($order);
        $perNetwork = max(1, $settings->int('per_network_per_run'));
        foreach (array_keys($order) as $network) {
            if ($limit <= 0) {
                return;
            }
            $sentHere = 0;
            foreach ($app->store->dueJobs($network, $now, 20) as $job) {
                if ($sentHere >= $perNetwork || $limit <= 0) {
                    break;
                }
                if ($this->deadline->expired()) {
                    $this->report->stop('run budget');
                    return;
                }
                // Read the switches again before every job.
                $settings = $app->settings();
                if ($settings->killSwitch()) {
                    $this->report->stop(Job::R_KILL_SWITCH);
                    return;
                }
                $reason = $this->ineligible($job, $settings);
                if ($reason !== '') {
                    $this->finish($job, Job::SKIPPED, $reason, 'skipped', Job::QUEUED);
                    continue;
                }
                $gate = $app->gate($job, $settings);
                if ($gate !== null) {
                    $this->hold($job, $gate[0], $gate[1]);
                    continue;
                }
                $this->send($job, $app->adapter($network), $settings, false);
                $sentHere++;
                $limit--;
                if ($this->report->stopped) {
                    return;
                }
            }
        }
    }

    /**
     * The re-check right before sending: the post must still exist, be
     * published, not password-protected, not opted out, and pass the veto.
     */
    private function ineligible(Job $job, Settings $settings): string
    {
        $post = get_post($job->postId);
        if (!$post instanceof \WP_Post) {
            return Job::R_POST_GONE;
        }
        return $this->app->eligibility($post, $job->network, $settings, Crosspost::MODE_BACKFILL);
    }

    /**
     * One attempt. $resend is a same-key resend of an uncertain job.
     */
    private function send(Job $job, Adapter $adapter, Settings $settings, bool $resend): void
    {
        $app = $this->app;
        $network = $job->network;
        $limits = $adapter->limits();

        $creds = $this->credentials($adapter, $settings, false);
        if ($creds === null) {
            if ($this->report->stopped) {
                return;
            }
            $this->hold($job, Job::R_AUTH, $app->clock->now() + max(60, $settings->int('max_queue_age')));
            return;
        }
        $count = $adapter->count($job->text);
        if ($count > $limits->textLimit || trim($job->text) === '') {
            $this->finish($job, Job::FAILED, Job::R_TOO_LONG, 'failed', [$job->state]);
            do_action('crosspost_failed', $job->id, $job->postId, $network, Job::R_TOO_LONG);
            return;
        }

        $before = clone $job;
        $now = $app->clock->now();
        $job->state = Job::SENDING;
        $job->attempts++;
        $job->sentAt = $now;
        $job->updatedAt = $now;
        if (!$app->store->updateJob($job, [$before->state])) {
            return;
        }
        $app->logger->event($job->id, $job->postId, $network, 'attempt', sprintf('attempt %d%s', $job->attempts, $resend ? ' (same-key resend)' : ''));
        $this->report->sent++;

        [$file, $media, $asCard, $imageFailure] = $this->media($job, $adapter, $creds, $settings);
        if ($imageFailure !== null) {
            $app->images->cleanup($file);
            $this->apply($job, $before, $imageFailure, $limits);
            return;
        }

        $draft = $this->draft($job, $settings, $asCard ? 'card' : null);
        $start = $app->clock->micros();
        $outcome = $this->publish($adapter, $draft, $media, $creds, $settings);
        $app->images->cleanup($file);
        if ($outcome->is(Outcome::POSTED) || $outcome->httpStatus > 0) {
            $app->logger->event($job->id, $job->postId, $network, 'attempt', 'response', $outcome->httpStatus, (int) round(($app->clock->micros() - $start) / 1000));
        }
        $this->apply($job, $before, $outcome, $limits);
    }

    private function publish(Adapter $adapter, Draft $draft, ?MediaRef $media, Credentials $creds, Settings $settings): Outcome
    {
        try {
            $outcome = $adapter->publish($draft, $media, $creds, $this->deadline);
        } catch (CrosspostException $e) {
            return Outcome::auth($this->app->logger->scrub($e->getMessage()));
        }
        if ($outcome->is(Outcome::AUTH) && !($this->forcedRefresh[$adapter->id()] ?? false)) {
            // Refresh once; the refused call created nothing, so one retry is safe.
            $fresh = $this->credentials($adapter, $settings, true);
            if ($fresh !== null) {
                try {
                    return $adapter->publish($draft, $media, $fresh, $this->deadline);
                } catch (CrosspostException $e) {
                    return Outcome::auth($this->app->logger->scrub($e->getMessage()));
                }
            }
        }
        return $outcome;
    }

    /**
     * Prepare the image. Returns the local file (for cleanup), the network
     * reference, whether Bluesky should use a link card, and an outcome
     * when the image failure policy says "retry".
     *
     * @return array{0: ?MediaFile, 1: ?MediaRef, 2: bool, 3: ?Outcome}
     */
    private function media(Job $job, Adapter $adapter, Credentials $creds, Settings $settings): array
    {
        $app = $this->app;
        $network = $job->network;
        $limits = $adapter->limits();
        $mode = $settings->imageMode($network);
        $isBluesky = $network === Network::BLUESKY;
        $asCard = $isBluesky && ($mode === Settings::IMAGE_CARD || $job->attachmentId === 0);
        if ($job->attachmentId === 0 || !$limits->acceptsImages() || $mode === Settings::IMAGE_NONE) {
            return [null, null, $asCard, null];
        }
        if ($network === Network::THREADS && ($job->meta['container_id'] ?? '') !== '') {
            // A stored container already holds the image.
            return [null, null, false, null];
        }
        $retry = $settings->imageFailure($network) === Settings::ON_IMAGE_FAIL_RETRY;

        $prepared = $app->images->prepare($job->attachmentId, $limits, $network, $settings->requireAlt($network) && !$asCard);
        if (is_string($prepared) && $isBluesky && !$asCard) {
            // The image cannot go as an image; try it as the card thumbnail.
            $app->logger->event($job->id, $job->postId, $network, 'image_fallback', $prepared . '; using a link card');
            $asCard = true;
            $prepared = $app->images->prepare($job->attachmentId, $limits, $network, false);
        }
        if (is_string($prepared)) {
            $app->logger->event($job->id, $job->postId, $network, 'image_fallback', $prepared . '; text only');
            return [null, null, $asCard, null];
        }
        $ref = $adapter->prepareMedia($prepared, $creds, $this->deadline->capped(self::IMAGE_WAIT));
        if ($ref->ok()) {
            return [$prepared, $ref, $asCard, null];
        }
        $failure = $ref->failure ?? Outcome::retryable('image failed');
        if ($failure->blocked) {
            return [$prepared, null, $asCard, $failure];
        }
        if ($retry && !$failure->is(Outcome::PERMANENT)) {
            return [$prepared, null, $asCard, Outcome::retryable('image: ' . $failure->message, $failure->httpStatus, $failure->retryAfter, $failure->cooldown)];
        }
        if ($failure->is(Outcome::AUTH)) {
            return [$prepared, null, $asCard, $failure];
        }
        $app->logger->event($job->id, $job->postId, $network, 'image_fallback', $failure->message . '; text only', $failure->httpStatus);
        return [$prepared, null, $asCard, null];
    }

    private function draft(Job $job, Settings $settings, ?string $style): Draft
    {
        $card = null;
        $url = (string) ($job->meta['card_url'] ?? '');
        if ($job->network === Network::BLUESKY && $style === 'card' && $url !== '') {
            $card = new LinkCard($url, (string) ($job->meta['card_title'] ?? ''), (string) ($job->meta['card_description'] ?? ''));
        }
        return new Draft(
            $job->id,
            $job->postId,
            $job->text,
            (string) ($job->meta['language'] ?? ''),
            $job->idempotencyKey,
            $card,
            $settings->visibility($job->network),
            $settings->imageFailure($job->network) === Settings::ON_IMAGE_FAIL_TEXT,
            $job->meta,
            $job->firstSentAt,
        );
    }

    /**
     * Credentials for a network, refreshed when the adapter says so. A
     * refresh failure marks the network as needing attention.
     */
    private function credentials(Adapter $adapter, Settings $settings, bool $force): ?Credentials
    {
        $network = $adapter->id();
        if (!$force && isset($this->creds[$network])) {
            return $this->creds[$network];
        }
        $app = $this->app;
        $creds = $app->credentials->get($network);
        if ($force) {
            $this->forcedRefresh[$network] = true;
        }
        try {
            $fresh = $adapter->refresh($creds, $force);
        } catch (CrosspostException $e) {
            if ($app->settings()->killSwitch()) {
                $this->report->stop(Job::R_KILL_SWITCH);
                return null;
            }
            $app->logger->event(null, 0, $network, 'auth', 'refresh failed: ' . $e->getMessage());
            $app->notifier->attention($network, $creds->get('verified_account'), 'token refresh failed', $settings);
            return null;
        }
        if ($fresh !== null) {
            // One write, before the next call uses the new tokens.
            $app->credentials->save($fresh);
            $app->logger->event(null, 0, $network, 'refresh', 'tokens refreshed');
            $creds = $fresh;
        }
        return $this->creds[$network] = $creds;
    }

    private function apply(Job $job, Job $before, Outcome $outcome, Limits $limits): void
    {
        $app = $this->app;
        $now = $app->clock->now();
        $network = $job->network;
        foreach ($outcome->meta as $key => $value) {
            if (in_array($key, ['container_id', 'container_at', 'image_note'], true)) {
                $job->meta[$key] = $value;
            }
        }

        if ($outcome->blocked) {
            // The kill switch stopped the call: leave the job as it was.
            $app->store->updateJob($before, [Job::SENDING]);
            $this->report->sent--;
            $this->report->stop(Job::R_KILL_SWITCH);
            return;
        }

        switch ($outcome->kind) {
            case Outcome::POSTED:
                $this->posted($job, $outcome, 'posted', [Job::SENDING]);
                return;

            case Outcome::UNCERTAIN:
                if ($job->firstSentAt === 0) {
                    $job->firstSentAt = $job->sentAt;
                }
                $this->markUncertain($job, $outcome->message, Job::SENDING, $outcome->httpStatus, $limits);
                return;

            case Outcome::PERMANENT:
                $this->finish($job, Job::FAILED, $outcome->message !== '' ? $outcome->message : 'refused', 'failed', [Job::SENDING], $outcome->httpStatus);
                $this->report->failed++;
                do_action('crosspost_failed', $job->id, $job->postId, $network, $job->reason);
                return;

            case Outcome::AUTH:
                $job->state = Job::HELD;
                $job->reason = Job::R_AUTH;
                $job->nextAttemptAt = $job->createdAt + max(60, $app->settings()->int('max_queue_age'));
                $job->updatedAt = $now;
                $app->store->updateJob($job, [Job::SENDING]);
                $app->logger->event($job->id, $job->postId, $network, 'auth', $outcome->message, $outcome->httpStatus);
                $app->notifier->attention($network, $app->credentials->get($network)->get('verified_account'), $outcome->message !== '' ? $outcome->message : 'credentials refused', $app->settings());
                $this->report->held++;
                return;

            default:
                $this->retry($job, $outcome);
        }
    }

    private function retry(Job $job, Outcome $outcome): void
    {
        $app = $this->app;
        $now = $app->clock->now();
        $network = $job->network;
        if ($outcome->cooldown) {
            $until = max($outcome->retryAfter, $now + 60);
            $creds = $app->credentials->get($network);
            $account = $app->accountRecord($network) ?? Notifier::fresh($network, $creds->get('verified_account'), $now);
            $account->cooldownUntil = max($account->cooldownUntil, $until);
            $app->store->saveAccount($account);
            $app->logger->event(null, 0, $network, 'cooldown', 'no calls until ' . gmdate('Y-m-d H:i:s', $until) . ' UTC', $outcome->httpStatus);
        }
        $backoff = apply_filters('crosspost_backoff', self::BACKOFF);
        $backoff = is_array($backoff) && $backoff !== [] ? array_values(array_map('intval', $backoff)) : self::BACKOFF;
        if ($job->attempts > count($backoff)) {
            $this->finish($job, Job::FAILED, Job::R_RETRIES, 'failed', [Job::SENDING], $outcome->httpStatus);
            $this->report->failed++;
            do_action('crosspost_failed', $job->id, $job->postId, $network, Job::R_RETRIES);
            return;
        }
        $delay = $backoff[max(0, $job->attempts - 1)];
        $job->state = Job::PENDING;
        $job->reason = $outcome->message;
        $job->nextAttemptAt = max($now + max(0, $delay), $outcome->retryAfter);
        $job->updatedAt = $now;
        $app->store->updateJob($job, [Job::SENDING]);
        $app->logger->event($job->id, $job->postId, $network, 'retry', $outcome->message . ' (next try ' . gmdate('H:i', $job->nextAttemptAt) . ' UTC)', $outcome->httpStatus);
    }

    /**
     * @param list<string> $from
     */
    private function posted(Job $job, Outcome $outcome, string $event, array $from): void
    {
        $app = $this->app;
        $now = $app->clock->now();
        $job->state = Job::POSTED;
        $job->reason = '';
        $job->remoteId = $outcome->remoteId;
        $job->remoteUrl = esc_url_raw($outcome->remoteUrl);
        $job->postedAt = $now;
        $job->updatedAt = $now;
        if (!$app->store->updateJob($job, $from)) {
            return;
        }
        $creds = $app->credentials->get($job->network);
        $accountId = $creds->get('verified_account');
        if ($accountId !== '') {
            $account = $app->store->account($job->network, $accountId) ?? Notifier::fresh($job->network, $accountId, $now);
            $firstReviewed = $app->needsFirstPostReview($job->network, $app->settings());
            $account->lastPostedAt = $now;
            $app->store->saveAccount($account);
            if ($firstReviewed) {
                // The reviewed first post is live: jobs that waited behind it may go.
                $app->wake($job->network, [Job::R_FIRST_POST]);
            }
        }
        $app->logger->event($job->id, $job->postId, $job->network, $event, 'posted ' . $job->remoteId, $outcome->httpStatus);
        $this->report->posted++;
        do_action('crosspost_posted', $job->id, $job->postId, $job->network, $job->remoteId, $job->remoteUrl);
    }

    private function markUncertain(Job $job, string $message, string $from, int $status = 0, ?Limits $limits = null): void
    {
        $app = $this->app;
        $limits ??= $app->adapter($job->network)->limits();
        $now = $app->clock->now();
        if ($job->firstSentAt === 0) {
            $job->firstSentAt = $job->sentAt > 0 ? $job->sentAt : $now;
        }
        if ($limits->reconcile === Limits::RECONCILE_NONE) {
            $job->state = Job::UNKNOWN;
            $job->reason = 'outcome unknown; a person decides';
            $event = 'unknown';
        } else {
            $job->state = Job::UNCERTAIN;
            $job->reason = 'outcome unknown; checking';
            $job->nextAttemptAt = $now + self::UNCERTAIN_RECHECK;
            $event = 'uncertain';
        }
        $job->updatedAt = $now;
        if ($app->store->updateJob($job, [$from])) {
            $app->logger->event($job->id, $job->postId, $job->network, $event, $message, $status);
            do_action('crosspost_uncertain', $job->id, $job->postId, $job->network);
        }
    }

    private function recheckLater(Job $job, string $message): void
    {
        $job->nextAttemptAt = $this->app->clock->now() + self::UNCERTAIN_RECHECK;
        $job->updatedAt = $this->app->clock->now();
        $this->app->store->updateJob($job, [Job::UNCERTAIN]);
        $this->app->logger->event($job->id, $job->postId, $job->network, 'uncertain', $message);
    }

    private function hold(Job $job, string $reason, int $next): void
    {
        $changed = $job->state !== Job::HELD || $job->reason !== $reason;
        $from = $job->state;
        $job->state = Job::HELD;
        $job->reason = $reason;
        $job->nextAttemptAt = $next;
        $job->updatedAt = $this->app->clock->now();
        if ($this->app->store->updateJob($job, [$from]) && $changed) {
            $this->app->logger->event($job->id, $job->postId, $job->network, 'held', $reason);
            $this->report->held++;
        }
    }

    /**
     * @param list<string> $from
     */
    private function finish(Job $job, string $state, string $reason, string $event, array $from, int $status = 0): void
    {
        $job->state = $state;
        $job->reason = $this->app->logger->scrub($reason);
        $job->updatedAt = $this->app->clock->now();
        if ($this->app->store->updateJob($job, $from)) {
            $this->app->logger->event($job->id, $job->postId, $job->network, $event, $reason, $status);
        }
    }
}
