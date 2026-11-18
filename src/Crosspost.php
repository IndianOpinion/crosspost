<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost;

use IndianOpinion\Crosspost\Adapter\Adapter;
use IndianOpinion\Crosspost\Adapter\BlueskyAdapter;
use IndianOpinion\Crosspost\Adapter\MastodonAdapter;
use IndianOpinion\Crosspost\Adapter\ThreadsAdapter;
use IndianOpinion\Crosspost\Adapter\XAdapter;
use IndianOpinion\Crosspost\Exception\ConfigurationException;
use IndianOpinion\Crosspost\Http\HttpClient;
use IndianOpinion\Crosspost\Media\ImagePreparer;
use IndianOpinion\Crosspost\Media\ImageSelector;
use IndianOpinion\Crosspost\Media\PublicUrl;
use IndianOpinion\Crosspost\Queue\Runner;
use IndianOpinion\Crosspost\Queue\RunReport;
use IndianOpinion\Crosspost\Store\AccountRecord;
use IndianOpinion\Crosspost\Store\Job;
use IndianOpinion\Crosspost\Store\Store;
use IndianOpinion\Crosspost\Support\Clock;
use IndianOpinion\Crosspost\Support\Sleeper;
use IndianOpinion\Crosspost\Support\Tid;
use IndianOpinion\Crosspost\Text\Normaliser;
use IndianOpinion\Crosspost\Value\Account;

/**
 * The plugin's services and the operations every screen and command uses.
 */
final class Crosspost
{
    public const RUN_HOOK = 'crosspost_run';
    public const PRUNE_HOOK = 'crosspost_prune';
    public const LOCK = 'crosspost_lock';
    public const OPT_OUT_META = '_crosspost_opt_out';

    /** Job creation modes. */
    public const MODE_PUBLISH = 'publish';
    public const MODE_MANUAL = 'manual';
    public const MODE_BACKFILL = 'backfill';

    public readonly CredentialStore $credentials;
    public readonly Review $review;
    public readonly Logger $logger;
    public readonly Notifier $notifier;
    public readonly HttpClient $http;
    public readonly PostComposer $composer;
    public readonly ImagePreparer $images;

    /** @var array<string, Adapter>|null */
    private ?array $adapters = null;

    /** @var list<string>|null */
    private ?array $missingColumns = null;

    /** @var \Closure(): list<string> */
    private \Closure $schemaCheck;

    private PublicUrl $publicUrl;

    /**
     * @param (callable(): list<string>)|null $schemaCheck returns missing columns
     */
    public function __construct(
        public readonly Store $store,
        public readonly Clock $clock,
        public readonly Sleeper $sleeper,
        ?callable $schemaCheck = null,
        ?PublicUrl $publicUrl = null,
    ) {
        $this->credentials = new CredentialStore();
        $this->review = new Review();
        $this->logger = new Logger($store, $clock, fn (): array => $this->credentials->allSecrets());
        $this->notifier = new Notifier($store, $clock, $this->logger);
        $this->http = new HttpClient(static fn (): bool => Settings::load()->killSwitch(), $clock);
        $this->images = new ImagePreparer();
        $this->composer = new PostComposer(new ImageSelector(), $this->images);
        $this->schemaCheck = $schemaCheck !== null ? \Closure::fromCallable($schemaCheck) : static fn (): array => [];
        $this->publicUrl = $publicUrl ?? new PublicUrl();
    }

    public function settings(): Settings
    {
        return Settings::load();
    }

    /**
     * @return array<string, Adapter>
     */
    public function adapters(): array
    {
        if ($this->adapters !== null) {
            return $this->adapters;
        }
        $defaults = [
            Network::X => new XAdapter($this->http, $this->clock, $this->sleeper),
            Network::THREADS => new ThreadsAdapter($this->http, $this->clock, $this->sleeper, $this->publicUrl),
            Network::BLUESKY => new BlueskyAdapter($this->http, $this->clock, $this->sleeper),
            Network::MASTODON => new MastodonAdapter($this->http, $this->clock, $this->sleeper),
        ];
        $filtered = apply_filters('crosspost_adapters', $defaults);
        $adapters = $defaults;
        if (is_array($filtered)) {
            foreach ($filtered as $adapter) {
                // Replacing one of the four is supported; a fifth network is not.
                if ($adapter instanceof Adapter && Network::isValid($adapter->id())) {
                    $adapters[$adapter->id()] = $adapter;
                }
            }
        }
        return $this->adapters = $adapters;
    }

    public function adapter(string $network): Adapter
    {
        Network::assertValid($network);
        return $this->adapters()[$network];
    }

    /**
     * Missing database columns; sending is refused while any is missing.
     *
     * @return list<string>
     */
    public function missingColumns(): array
    {
        return $this->missingColumns ??= ($this->schemaCheck)();
    }

    public function resetSchemaCache(): void
    {
        $this->missingColumns = null;
    }

    // ------------------------------------------------------------------
    // Job creation
    // ------------------------------------------------------------------

    /**
     * The trigger: a status transition into "publish" from anything else.
     */
    public function onTransition(string $new, string $old, \WP_Post $post): void
    {
        if ($new !== 'publish') {
            if ($old === 'publish') {
                $this->cancelUnsent($post->ID, Job::R_UNPUBLISHED);
            }
            return;
        }
        if ($old === 'publish') {
            return;
        }
        $settings = $this->settings();
        // Dormant: with every network off, do nothing at all.
        if (!$settings->anyEnabled()) {
            return;
        }
        if (!in_array($post->post_type, $settings->postTypes(), true)) {
            return;
        }
        $created = false;
        foreach (Network::ALL as $network) {
            if ($this->store->jobFor($post->ID, $network) !== null) {
                continue;
            }
            $job = $this->createJob($post, $network, $settings, self::MODE_PUBLISH);
            $created = $created || ($job !== null && $job->state === Job::PENDING);
        }
        if ($created) {
            $this->schedule($this->clock->now() + max(0, $settings->int('first_delay')));
        }
    }

    /**
     * Create one job. Never calls a network.
     */
    public function createJob(\WP_Post $post, string $network, Settings $settings, string $mode, int $userId = 0): ?Job
    {
        $now = $this->clock->now();
        $job = new Job();
        $job->postId = $post->ID;
        $job->network = $network;
        $job->createdAt = $now;
        $job->updatedAt = $now;
        $job->nextAttemptAt = $now + ($mode === self::MODE_PUBLISH ? max(0, $settings->int('first_delay')) : 0);
        $job->releasedBy = $mode === self::MODE_MANUAL ? self::reviewer($userId) : 0;
        $creds = $this->credentials->get($network);
        $job->accountId = $creds->get('verified_account');

        $skip = $this->eligibility($post, $network, $settings, $mode);
        if ($skip === '' && !$settings->enabled($network)) {
            $skip = Job::R_NETWORK_OFF;
        }
        if ($skip !== '') {
            $job->state = Job::SKIPPED;
            $job->reason = $skip;
            return $this->insert($job, 'skipped', $skip);
        }

        $adapter = $this->adapter($network);
        $composition = $this->composer->compose($post, $adapter, $settings);
        $job->text = $composition->text;
        $job->textHash = Normaliser::hash($composition->text);
        $job->templateHash = $composition->templateHash;
        $job->attachmentId = $composition->attachmentId;
        $job->idempotencyKey = $network === Network::BLUESKY ? Tid::generate($this->clock->micros()) : '';
        $job->meta = array_filter([
            'language' => $composition->language,
            'card_url' => $composition->card->url ?? '',
            'card_title' => $composition->card->title ?? '',
            'card_description' => $composition->card->description ?? '',
        ], static fn (string $v): bool => $v !== '');

        if (!$composition->ok()) {
            $job->state = Job::FAILED;
            $job->reason = $composition->error;
            return $this->insert($job, 'failed', $composition->error);
        }

        $hold = $this->creationHold($network, $settings, $job);
        if ($hold !== '') {
            $job->state = Job::HELD;
            $job->reason = $hold;
            $job->nextAttemptAt = $this->eventWait($job, $settings);
        }
        return $this->insert($job, $hold === '' ? 'created' : 'held', $hold === '' ? $mode : $hold);
    }

    private function insert(Job $job, string $event, string $message): ?Job
    {
        $id = $this->store->insertJob($job);
        if ($id === null) {
            return null;
        }
        if ($job->network === Network::MASTODON) {
            // Stable for the life of the job, derived from its id.
            $job->idempotencyKey = self::idempotencyKey($id);
            $this->store->updateJob($job);
        }
        $this->logger->event($id, $job->postId, $job->network, $event, $message);
        do_action('crosspost_job_created', $id, $job->postId, $job->network);
        return $job;
    }

    public static function idempotencyKey(int $jobId): string
    {
        $site = function_exists('home_url') ? (string) home_url() : '';
        return 'crosspost-' . substr(hash('sha256', $site . '|' . $jobId), 0, 40);
    }

    /**
     * Reasons a post is not announced on a network. Empty when eligible.
     */
    public function eligibility(\WP_Post $post, string $network, Settings $settings, string $mode = self::MODE_PUBLISH): string
    {
        if ($post->post_status !== 'publish') {
            return Job::R_NOT_PUBLISHED;
        }
        if (!in_array($post->post_type, $settings->postTypes(), true)) {
            return Job::R_POST_TYPE;
        }
        if ($post->post_password !== '') {
            return Job::R_PASSWORD;
        }
        if (self::optedOut($post->ID)) {
            return Job::R_OPTED_OUT;
        }
        if ($mode === self::MODE_PUBLISH) {
            $published = strtotime($post->post_date_gmt . ' UTC');
            $maxAge = $settings->int('max_age');
            if ($published !== false && $post->post_date_gmt !== '0000-00-00 00:00:00' && $maxAge > 0 && $this->clock->now() - $published > $maxAge) {
                return Job::R_TOO_OLD;
            }
        }
        $veto = apply_filters('crosspost_should_post', true, $post, $network);
        if ($veto === false) {
            return 'vetoed by filter';
        }
        if (is_string($veto) && $veto !== '') {
            return sanitize_text_field($veto);
        }
        return '';
    }

    /**
     * The user id that may stand in for the first post review, or 0. Only
     * an administrator can: an author's "post now", a cron job or a CLI
     * call without a user still waits for the review.
     */
    public static function reviewer(int $userId): int
    {
        return $userId > 0 && user_can($userId, 'manage_options') ? $userId : 0;
    }

    public static function optedOut(int $postId): bool
    {
        return Settings::toBool(get_post_meta($postId, self::OPT_OUT_META, true), false);
    }

    /**
     * Holds that apply when a job is created: kill switch, preview, first
     * post review. Cap and spacing are decided at send time.
     */
    private function creationHold(string $network, Settings $settings, Job $job): string
    {
        if ($settings->killSwitch()) {
            return Job::R_KILL_SWITCH;
        }
        if (!$this->review->isConfirmed($network, $settings->outputHash($network))) {
            return Job::R_PREVIEW;
        }
        if ($job->releasedBy === 0 && $this->needsFirstPostReview($network, $settings)) {
            return Job::R_FIRST_POST;
        }
        return '';
    }

    /**
     * Gates checked right before a send. Returns [reason, next attempt
     * time] when the job must wait, or null when it may go.
     *
     * @return array{0: string, 1: int}|null
     */
    public function gate(Job $job, Settings $settings): ?array
    {
        $network = $job->network;
        $now = $this->clock->now();
        if ($settings->killSwitch()) {
            return [Job::R_KILL_SWITCH, $this->eventWait($job, $settings)];
        }
        if (!$settings->enabled($network)) {
            return [Job::R_NETWORK_OFF, $this->eventWait($job, $settings)];
        }
        if ($this->missingColumns() !== []) {
            return [Job::R_SCHEMA, $this->eventWait($job, $settings)];
        }
        $creds = $this->credentials->get($network);
        if (!$this->credentials->present($creds) || !$this->credentials->isVerified($creds)) {
            return [Job::R_NO_CREDENTIALS, $this->eventWait($job, $settings)];
        }
        $account = $this->accountRecord($network);
        if ($account !== null && $account->status === AccountRecord::ATTENTION) {
            return [Job::R_AUTH, $this->eventWait($job, $settings)];
        }
        if ($account !== null && $account->cooldownUntil > $now) {
            return [Job::R_COOLDOWN, $account->cooldownUntil];
        }
        if (!$this->review->isConfirmed($network, $settings->outputHash($network))) {
            return [Job::R_PREVIEW, $this->eventWait($job, $settings)];
        }
        if ($job->releasedBy === 0 && $this->needsFirstPostReview($network, $settings)) {
            return [Job::R_FIRST_POST, $this->eventWait($job, $settings)];
        }
        $cap = $settings->cap($network);
        if ($cap === 0) {
            return [Job::R_CAP, $this->eventWait($job, $settings)];
        }
        $since = $now - 86400;
        if ($this->store->countSent($network, $since) >= $cap) {
            $oldest = $this->store->oldestSent($network, $since) ?? $now;
            return [Job::R_CAP, $oldest + 86400];
        }
        $last = $this->store->lastSent($network);
        $spacing = $settings->spacing($network);
        if ($last !== null && $spacing > 0 && $last + $spacing > $now) {
            return [Job::R_SPACING, $last + $spacing];
        }
        return null;
    }

    /**
     * A held job that waits for an event (a person, a setting) is looked at
     * again when the event happens, or when it goes stale.
     */
    private function eventWait(Job $job, Settings $settings): int
    {
        return ($job->createdAt > 0 ? $job->createdAt : $this->clock->now()) + max(60, $settings->int('max_queue_age'));
    }

    /**
     * True until the connected account has a posted job, and again after
     * a long pause without one.
     */
    public function needsFirstPostReview(string $network, Settings $settings): bool
    {
        $account = $this->accountRecord($network);
        if ($account === null || $account->lastPostedAt === 0) {
            return true;
        }
        $pause = $settings->int('first_post_pause_days');
        return $pause > 0 && $this->clock->now() - $account->lastPostedAt > $pause * 86400;
    }

    public function accountRecord(string $network): ?AccountRecord
    {
        $id = $this->credentials->get($network)->get('verified_account');
        return $id === '' ? null : $this->store->account($network, $id);
    }

    // ------------------------------------------------------------------
    // Running
    // ------------------------------------------------------------------

    public function run(?string $network = null, ?int $limit = null): RunReport
    {
        return (new Runner($this))->run($network, $limit);
    }

    /**
     * Schedule one processing event at $at, unless one is already due
     * earlier. Never schedules while the kill switch is on.
     */
    public function schedule(int $at): void
    {
        if ($this->settings()->killSwitch()) {
            return;
        }
        $next = wp_next_scheduled(self::RUN_HOOK);
        if (is_int($next) && $next <= $at) {
            return;
        }
        if (is_int($next)) {
            wp_clear_scheduled_hook(self::RUN_HOOK);
        }
        wp_schedule_single_event(max($at, $this->clock->now()), self::RUN_HOOK);
    }

    /**
     * Make held jobs that wait for an event look again now.
     *
     * @param list<string> $reasons
     */
    public function wake(?string $network, array $reasons): void
    {
        $now = $this->clock->now();
        $woken = false;
        foreach ($network === null ? Network::ALL : [$network] as $net) {
            foreach ($this->store->jobs(['state' => Job::HELD, 'network' => $net], 500) as $job) {
                if (in_array($job->reason, $reasons, true)) {
                    $job->nextAttemptAt = $now;
                    $job->updatedAt = $now;
                    $this->store->updateJob($job, [Job::HELD]);
                    $woken = true;
                }
            }
        }
        if ($woken) {
            $this->schedule($now);
        }
    }

    // ------------------------------------------------------------------
    // Manual actions
    // ------------------------------------------------------------------

    /**
     * Release a held job, or resend a job a person decided to resend
     * ("never landed" or "unknown").
     */
    public function send(int $jobId, int $userId): Job
    {
        $job = $this->store->job($jobId);
        if ($job === null) {
            throw new ConfigurationException('No such job.');
        }
        $resend = $job->state === Job::UNKNOWN || ($job->state === Job::FAILED && $job->reason === Job::R_NEVER_LANDED);
        if ($job->state !== Job::HELD && !$resend) {
            throw new ConfigurationException(sprintf('A %s job cannot be sent.', $job->state));
        }
        $from = $job->state;
        $now = $this->clock->now();
        $job->state = Job::PENDING;
        $job->reason = $resend ? 'resend' : 'released';
        $job->releasedBy = max(1, $userId);
        $job->nextAttemptAt = $now;
        $job->updatedAt = $now;
        if ($resend) {
            // A fresh window: the earlier attempt is a person's call now.
            $job->createdAt = $now;
            $job->attempts = 0;
        }
        if (!$this->store->updateJob($job, [$from])) {
            throw new ConfigurationException('The job changed; reload and try again.');
        }
        $this->logger->event($job->id, $job->postId, $job->network, 'released', sprintf('%s by user %d', $resend ? 'resend' : 'release', $userId));
        $this->schedule($now);
        return $job;
    }

    public function cancel(int $jobId, int $userId, string $reason = ''): Job
    {
        $job = $this->store->job($jobId);
        if ($job === null) {
            throw new ConfigurationException('No such job.');
        }
        if (!$job->isQueued()) {
            throw new ConfigurationException(sprintf('A %s job cannot be cancelled.', $job->state));
        }
        $from = $job->state;
        $job->state = Job::CANCELLED;
        $job->reason = $reason !== '' ? $reason : sprintf('cancelled by user %d', $userId);
        $job->updatedAt = $this->clock->now();
        if (!$this->store->updateJob($job, [$from])) {
            throw new ConfigurationException('The job changed; reload and try again.');
        }
        $this->logger->event($job->id, $job->postId, $job->network, 'cancelled', $job->reason);
        return $job;
    }

    /**
     * Unpublish, trash, delete or opt-out: cancel what has not been sent.
     * Posted, sending and uncertain jobs are left alone.
     */
    public function cancelUnsent(int $postId, string $reason): void
    {
        foreach ($this->store->jobsForPost($postId) as $job) {
            if ($job->isQueued()) {
                $from = $job->state;
                $job->state = Job::CANCELLED;
                $job->reason = $reason;
                $job->updatedAt = $this->clock->now();
                if ($this->store->updateJob($job, [$from])) {
                    $this->logger->event($job->id, $postId, $job->network, 'cancelled', $reason);
                }
            }
        }
    }

    public function setOptOut(int $postId, bool $optOut): void
    {
        if ($optOut) {
            update_post_meta($postId, self::OPT_OUT_META, '1');
            $this->cancelUnsent($postId, Job::R_OPTED_OUT);
        } else {
            delete_post_meta($postId, self::OPT_OUT_META);
        }
    }

    /**
     * Manual "post now": every rule of job creation applies, plus the cap
     * at send time. Allowed when the post has no job on the network, or
     * only one that was cancelled, skipped or refused.
     */
    public function postNow(int $postId, string $network, int $userId): Job
    {
        Network::assertValid($network);
        $post = get_post($postId);
        if (!$post instanceof \WP_Post) {
            throw new ConfigurationException('No such post.');
        }
        $settings = $this->settings();
        $existing = $this->store->jobFor($postId, $network);
        if ($existing !== null) {
            $replaceable = in_array($existing->state, [Job::CANCELLED, Job::SKIPPED], true)
                || ($existing->state === Job::FAILED && $existing->reason !== Job::R_NEVER_LANDED);
            if (!$replaceable) {
                throw new ConfigurationException(sprintf('This post already has a %s job on %s.', $existing->state, Network::label($network)));
            }
        }
        $skip = $this->eligibility($post, $network, $settings, self::MODE_MANUAL);
        if ($skip === '' && !$settings->enabled($network)) {
            $skip = Job::R_NETWORK_OFF;
        }
        if ($skip !== '') {
            throw new ConfigurationException('Not sent: ' . $skip . '.');
        }
        if ($existing === null) {
            $job = $this->createJob($post, $network, $settings, self::MODE_MANUAL, $userId);
            if ($job === null) {
                throw new ConfigurationException('A job for this post and network already exists.');
            }
        } else {
            $job = $this->rebuild($existing, $post, $settings, $userId);
        }
        if ($job->state === Job::FAILED) {
            throw new ConfigurationException('Not sent: ' . $job->reason . '.');
        }
        $this->schedule($this->clock->now());
        return $job;
    }

    /**
     * Reuse the (post, network) row for a manual resend after a cancel:
     * the unique key stays the "once per network" guarantee.
     */
    private function rebuild(Job $job, \WP_Post $post, Settings $settings, int $userId): Job
    {
        $from = $job->state;
        $composition = $this->composer->compose($post, $this->adapter($job->network), $settings);
        $now = $this->clock->now();
        $job->text = $composition->text;
        $job->textHash = Normaliser::hash($composition->text);
        $job->templateHash = $composition->templateHash;
        $job->attachmentId = $composition->attachmentId;
        $job->attempts = 0;
        $job->remoteId = '';
        $job->remoteUrl = '';
        $job->sentAt = 0;
        $job->firstSentAt = 0;
        $job->releasedBy = self::reviewer($userId);
        $job->createdAt = $now;
        $job->updatedAt = $now;
        $job->nextAttemptAt = $now;
        $job->accountId = $this->credentials->get($job->network)->get('verified_account');
        $job->meta = array_filter([
            'language' => $composition->language,
            'card_url' => $composition->card->url ?? '',
            'card_title' => $composition->card->title ?? '',
            'card_description' => $composition->card->description ?? '',
        ], static fn (string $v): bool => $v !== '');
        if ($job->network === Network::BLUESKY) {
            $job->idempotencyKey = Tid::generate($this->clock->micros());
        }
        if (!$composition->ok()) {
            $job->state = Job::FAILED;
            $job->reason = $composition->error;
        } else {
            $hold = $this->creationHold($job->network, $settings, $job);
            $job->state = $hold === '' ? Job::PENDING : Job::HELD;
            $job->reason = $hold === '' ? 'post now' : $hold;
        }
        if (!$this->store->updateJob($job, [$from])) {
            throw new ConfigurationException('The job changed; reload and try again.');
        }
        $this->logger->event($job->id, $job->postId, $job->network, $job->state === Job::FAILED ? 'failed' : 'created', 'post now by user ' . $userId);
        return $job;
    }

    /**
     * Jobs for already-published posts that never had one on $network.
     * Without $apply nothing is written.
     *
     * @return list<array{post_id: int, title: string, state: string, reason: string}>
     */
    public function backfill(int $since, string $network, int $limit, bool $apply): array
    {
        Network::assertValid($network);
        $settings = $this->settings();
        $posts = get_posts([
            'post_type' => $settings->postTypes(),
            'post_status' => 'publish',
            'date_query' => [['after' => gmdate('Y-m-d H:i:s', $since), 'column' => 'post_date_gmt', 'inclusive' => true]],
            'orderby' => 'date',
            'order' => 'ASC',
            'posts_per_page' => max(1, min(500, $limit * 4)),
            'no_found_rows' => true,
            'suppress_filters' => false,
        ]);
        $out = [];
        $queued = false;
        foreach ($posts as $post) {
            if (count($out) >= $limit) {
                break;
            }
            if (!$post instanceof \WP_Post || $this->store->jobFor($post->ID, $network) !== null) {
                continue;
            }
            $reason = $this->eligibility($post, $network, $settings, self::MODE_BACKFILL);
            if (!$apply) {
                $out[] = ['post_id' => $post->ID, 'title' => get_the_title($post), 'state' => $reason === '' ? 'would queue' : 'would skip', 'reason' => $reason];
                continue;
            }
            $job = $this->createJob($post, $network, $settings, self::MODE_BACKFILL);
            if ($job !== null) {
                $queued = $queued || $job->state === Job::PENDING;
                $out[] = ['post_id' => $post->ID, 'title' => get_the_title($post), 'state' => $job->state, 'reason' => $job->reason];
            }
        }
        if ($queued) {
            $this->schedule($this->clock->now());
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Connecting, previewing, switching
    // ------------------------------------------------------------------

    /**
     * Refresh where needed, call the network's "who am I", record the
     * account. Read-only on the network side.
     */
    public function verify(string $network): Account
    {
        if ($this->settings()->killSwitch()) {
            throw new ConfigurationException('The kill switch is on: no outbound calls.');
        }
        $adapter = $this->adapter($network);
        $creds = $this->credentials->get($network);
        if (!$this->credentials->present($creds)) {
            throw new ConfigurationException('Credentials are missing.');
        }
        $previous = $creds->get('verified_account');
        $fresh = $adapter->refresh($creds, $network === Network::MASTODON);
        if ($fresh !== null) {
            $this->credentials->save($fresh);
            $creds = $fresh;
        }
        $account = $adapter->verify($creds);
        $this->credentials->markVerified($creds, $account, $this->clock->now());
        $record = $this->store->account($network, $account->id) ?? Notifier::fresh($network, $account->id, $this->clock->now());
        $record->displayName = $account->name;
        $this->store->saveAccount($record);
        $this->notifier->clear($network, $account->id);
        $this->logger->event(null, 0, $network, 'verify', $previous !== '' && $previous !== $account->id ? 'a different account was connected' : 'credentials verified');
        $this->wake($network, [Job::R_AUTH, Job::R_NO_CREDENTIALS]);
        return $account;
    }

    /**
     * Preview against recent posts, or chosen ones. No network call.
     *
     * @param list<int> $postIds
     * @return list<array<string, mixed>>
     */
    public function networkPreview(string $network, array $postIds = []): array
    {
        $settings = $this->settings();
        if ($postIds === []) {
            $posts = get_posts([
                'post_type' => $settings->postTypes(),
                'post_status' => 'publish',
                'posts_per_page' => 3,
                'orderby' => 'date',
                'order' => 'DESC',
                'no_found_rows' => true,
            ]);
        } else {
            $posts = array_map('get_post', $postIds);
        }
        $out = [];
        foreach ($posts as $post) {
            if ($post instanceof \WP_Post) {
                $out[] = ['post_id' => $post->ID, 'title' => get_the_title($post)] + $this->composer->compose($post, $this->adapter($network), $settings)->toArray();
            }
        }
        return $out;
    }

    public function confirmPreview(string $network, int $userId): void
    {
        Network::assertValid($network);
        $hash = $this->settings()->outputHash($network);
        $this->review->confirm($network, $userId, $this->clock->now(), $hash);
        $this->logger->event(null, 0, $network, 'confirm', sprintf('preview confirmed by user %d', $userId));
        $this->wake($network, [Job::R_PREVIEW]);
    }

    /**
     * Why a network cannot be switched on; empty when it can.
     */
    public function cannotEnable(string $network): string
    {
        $creds = $this->credentials->get($network);
        if (!$this->credentials->present($creds)) {
            return 'connect the account first';
        }
        if (!$this->credentials->isVerified($creds)) {
            return 'verify the credentials first';
        }
        if (!$this->review->isConfirmed($network, $this->settings()->outputHash($network))) {
            return 'view and confirm the preview first';
        }
        return '';
    }

    /**
     * Switch a network on or off, enforcing the rules for "on".
     */
    public function setEnabled(string $network, bool $on): void
    {
        Network::assertValid($network);
        if ($on) {
            $why = $this->cannotEnable($network);
            if ($why !== '') {
                throw new ConfigurationException(sprintf('%s cannot be switched on: %s.', Network::label($network), $why));
            }
        }
        $stored = get_option(Settings::OPTION, []);
        $stored = Settings::sanitize(['networks' => [$network => ['enabled' => $on]]], is_array($stored) ? $stored : []);
        update_option(Settings::OPTION, $stored, true);
        $this->syncEvents();
        if ($on) {
            $this->wake($network, [Job::R_NETWORK_OFF]);
        }
    }

    public function setKillSwitch(bool $on): void
    {
        $stored = get_option(Settings::OPTION, []);
        $stored = Settings::sanitize(['kill_switch' => $on], is_array($stored) ? $stored : []);
        update_option(Settings::OPTION, $stored, true);
        $this->logger->event(null, 0, '', 'kill_switch', $on ? 'kill switch on' : 'kill switch off');
        if ($on) {
            wp_clear_scheduled_hook(self::RUN_HOOK);
        } else {
            $this->wake(null, [Job::R_KILL_SWITCH]);
        }
    }

    /**
     * With every network off, no event stays scheduled.
     */
    public function syncEvents(): void
    {
        $settings = $this->settings();
        if (!$settings->anyEnabled()) {
            wp_clear_scheduled_hook(self::RUN_HOOK);
            wp_clear_scheduled_hook(self::PRUNE_HOOK);
            return;
        }
        if (wp_next_scheduled(self::PRUNE_HOOK) === false) {
            wp_schedule_event($this->clock->now() + 3600, 'daily', self::PRUNE_HOOK);
        }
    }

    public function prune(): void
    {
        $settings = $this->settings();
        $now = $this->clock->now();
        $jobsBefore = $now - max(1, $settings->int('job_retention_days')) * 86400;
        $logBefore = $now - max(1, $settings->int('log_retention_days')) * 86400;
        for ($i = 0; $i < 50 && $this->store->pruneJobs($jobsBefore, 1000) === 1000; $i++) {
            // Batches of 1,000.
        }
        for ($i = 0; $i < 50 && $this->store->pruneLog($logBefore, 1000) === 1000; $i++) {
            // Batches of 1,000.
        }
    }

    // ------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function preview(int $postId, string $network): array
    {
        Network::assertValid($network);
        $post = get_post($postId);
        if (!$post instanceof \WP_Post) {
            throw new ConfigurationException('No such post.');
        }
        return $this->composer->compose($post, $this->adapter($network), $this->settings())->toArray();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function postStatus(int $postId): array
    {
        $out = [];
        foreach ($this->store->jobsForPost($postId) as $job) {
            $out[$job->network] = [
                'job' => $job->id,
                'state' => $job->state,
                'reason' => $job->reason,
                'remote_url' => $job->remoteUrl,
                'created_at' => $job->createdAt,
                'updated_at' => $job->updatedAt,
                'posted_at' => $job->postedAt,
                'next_attempt_at' => $job->nextAttemptAt,
            ];
        }
        return $out;
    }

    /**
     * Per-network health, for the status command and the settings screen.
     * Credentials are reported as present or absent only.
     *
     * @return array<string, array<string, mixed>>
     */
    public function networkStatus(): array
    {
        $settings = $this->settings();
        $now = $this->clock->now();
        $out = [];
        foreach (Network::ALL as $network) {
            $creds = $this->credentials->get($network);
            $account = $this->accountRecord($network);
            $since = $now - 86400;
            $used = $this->store->countSent($network, $since);
            $cap = $settings->cap($network);
            $oldest = $this->store->oldestSent($network, $since);
            $due = $this->store->dueJobs($network, $now, 1);
            $lastError = $this->store->log(['network' => $network, 'event' => 'failed'], 1);
            $review = $this->review->get($network);
            $out[$network] = [
                'enabled' => $settings->enabled($network),
                'kill_switch' => $settings->killSwitch(),
                'credentials' => $this->credentials->present($creds),
                'verified' => $this->credentials->isVerified($creds),
                'account' => $creds->get('verified_name'),
                'status' => $account->status ?? '',
                'attention' => $account->reason ?? '',
                'cap' => $cap,
                'used' => $used,
                'remaining' => max(0, $cap - $used),
                'next_release' => $used >= $cap && $oldest !== null ? $oldest + 86400 : 0,
                'cooldown_until' => $account !== null && $account->cooldownUntil > $now ? $account->cooldownUntil : 0,
                'oldest_due_age' => $due === [] ? 0 : max(0, $now - $due[0]->createdAt),
                'last_error' => $lastError === [] ? '' : $lastError[0]->message,
                'preview_confirmed' => $this->review->isConfirmed($network, $settings->outputHash($network)),
                'preview_confirmed_by' => $review['user'],
                'first_post_review' => $this->needsFirstPostReview($network, $settings),
            ];
        }
        return $out;
    }
}
