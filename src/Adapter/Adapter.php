<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Adapter;

use IndianOpinion\Crosspost\Support\Deadline;
use IndianOpinion\Crosspost\Value\Account;
use IndianOpinion\Crosspost\Value\Credentials;
use IndianOpinion\Crosspost\Value\Draft;
use IndianOpinion\Crosspost\Value\Limits;
use IndianOpinion\Crosspost\Value\MediaFile;
use IndianOpinion\Crosspost\Value\MediaRef;
use IndianOpinion\Crosspost\Value\Outcome;

/**
 * One network. publish() is the only write.
 *
 * There is deliberately no method to edit, delete, hide or reply to a
 * remote post, and none may be added.
 */
interface Adapter
{
    /** "x", "threads", "bluesky" or "mastodon". */
    public function id(): string;

    /** Human name for screens. */
    public function label(): string;

    /** What the network accepts. Never makes a network call. */
    public function limits(): Limits;

    /** Length of $text by the network's own rule. */
    public function count(string $text): int;

    /**
     * Read-only "who am I" check.
     *
     * @throws \IndianOpinion\Crosspost\Exception\CredentialsException when the network refuses the credentials
     */
    public function verify(Credentials $credentials): Account;

    /**
     * Upload or register an image. May poll for readiness until $deadline.
     * Failures come back as MediaRef::failed(), never as an exception.
     */
    public function prepareMedia(MediaFile $file, Credentials $credentials, Deadline $deadline): MediaRef;

    /**
     * The only write. Sends the frozen text with the job's stable
     * idempotency material. Every transport and API error is an Outcome.
     */
    public function publish(Draft $draft, ?MediaRef $media, Credentials $credentials, Deadline $deadline): Outcome;

    /**
     * Decide whether uncertain jobs landed. Never writes.
     *
     * @param list<Draft> $uncertain
     * @return array<int, Outcome> job id => posted, absent or uncertain
     */
    public function reconcile(array $uncertain, Credentials $credentials): array;

    /**
     * Refresh tokens or sessions when they are close to expiry, or always
     * when $force is true. Returns the new set, or null when nothing changed.
     *
     * @throws \IndianOpinion\Crosspost\Exception\CredentialsException when the refresh fails
     */
    public function refresh(Credentials $credentials, bool $force = false): ?Credentials;
}
