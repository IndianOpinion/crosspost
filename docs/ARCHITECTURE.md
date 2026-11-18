# crosspost: architecture

This document describes how the plugin is built and records every decision, including the rulings on the PRD's open questions (section 13). The behaviour itself is specified in [PRD.md](PRD.md).

## Modules

| Path | Role |
|---|---|
| `crosspost.php` | Plugin header, loads Composer or `src/autoload.php`, calls `Plugin::boot()`. |
| `src/Plugin.php` | Hooks: status transitions, cron events, meta registration, REST, admin, WP-CLI, activation. |
| `src/Crosspost.php` | The service: job creation, gates, manual actions (send, cancel, post now, backfill), verify, preview, switches, status. |
| `src/Queue/Runner.php` | One processing run: lock, sweep, reconcile, send, apply outcomes, schedule the next run. |
| `src/Adapter/` | `Adapter` interface, `AbstractAdapter` (classification, length assertion), one class per network. |
| `src/Http/` | `HttpClient` over `wp_remote_request` (kill switch, no redirects on writes, transport error split), multipart builder. |
| `src/Text/` | Counting rules, X weighted count, graphemes, plain text, hashtags, links, normalisation for matching, the template renderer. |
| `src/Media/` | Image choice, preparation (sizes, re-encoding, metadata stripping), public URL check. |
| `src/Store/` | `Store` interface, `WpdbStore`, `Job`, `LogEntry`, `AccountRecord`, `Schema`. |
| `src/Settings.php`, `CredentialStore.php`, `Review.php` | Options: settings (autoloaded), credentials and preview confirmations (not autoloaded). |
| `src/Logger.php`, `Notifier.php`, `Support/Scrubber.php` | Log rows, admin notices and email, secret scrubbing. |
| `src/Admin/` | Admin actions, OAuth flows, settings page, tools page, post panel, REST routes, notices. |
| `src/Cli/Command.php` | `wp crosspost`. |
| `assets/editor.js` | Block editor panel, plain script with no build step. |
| `uninstall.php`, `src/Uninstaller.php` | Local cleanup. |

## Data flow

```
transition_post_status ──> Crosspost::onTransition
    (any network on? post type? first publish?)
        └─> createJob per network: eligibility, compose (text, image, card), creation holds
              └─> Store::insertJob (unique post_id, network) + log + one scheduled event

crosspost_run (WP-Cron) or `wp crosspost run`
    └─> Runner::run
          schema ok? kill switch off? any network on? lock?
          sweep: stale sending -> uncertain/unknown; old queued -> stale
          reconcile: uncertain jobs per network -> posted | absent (same-key resend) | still uncertain | never landed
          send: per network, oldest due job: re-check post, gates, refresh tokens, mark sending, image, publish, apply outcome
          schedule next run at the earliest next attempt time, or nothing
```

## Tables

- `{prefix}crosspost_jobs`: one row per (post, network), unique key `post_network`. Indexes `due (network, state, next_attempt_at)`, `sent (network, sent_at)`, `posted (network, posted_at)`, `updated (updated_at)`. Times are Unix seconds in `bigint` columns.
- `{prefix}crosspost_log`: one row per attempt or event; `job_id` is NULL for network-level events.
- `{prefix}crosspost_accounts`: one row per (network, account id): display name, status (`ok` or `attention`), reason, cooldown, last notification, last post.

## Decisions

### Rulings on the open questions

1. **X endpoints and quota.** Ruling: the v2 create-post endpoint only (`POST /2/tweets`). No media upload and no timeline listing, so X posts are text only and X is never reconciled. An X post whose final call has an unknown outcome (timeout, 5xx, a run that died mid-call) goes to a dedicated job state, `unknown`, and is never retried without a person pressing "Resend" or running `wp crosspost send`. Reason: reads are metered or unavailable on many access tiers, and a duplicate cannot be removed. `unknown` counts against the cap, like `uncertain`. The "who am I" endpoint (`GET /2/users/me`) is used only by an explicit credential check. PRD acceptance tests 31 and 33 are applied to Threads for listings; for X the tests assert that three timeouts make no listing call and stay `unknown` until a person resends.
2. **X weighted counting.** Ruling: written directly from the published rules (configuration version 3: ranges 0 to 4351, 8192 to 8205, 8208 to 8223, 8242 to 8247 weigh 1, everything else 2, links 23, emoji sequences 2, NFC first, limit 280), with fixture tests in `tests/Text/CountingTest.php`. The README notes the rules may drift. Emoji are detected by code point blocks rather than the installed ICU or PCRE version, so the count does not change with the host.
3. **Accounts.** Ruling: one account per network in 0.1.0. The jobs table carries `network` and `account_id`; the accounts table carries `network` and `account_id` with a unique key on both. The jobs unique key stays (post, network); a later version can widen it to (post, network, account).
4. **Bluesky authentication.** Ruling: app password only. Values that do not have the app password shape are refused.
5. **Threads images when the site is not public.** Ruling: text only. The public URL check refuses non-HTTPS URLs, URLs with credentials, local host names and hosts that resolve to private, loopback, link-local or reserved addresses; the post then goes out as text.
6. **Cap unit.** Ruling: posts per rolling day, counting `sending`, `uncertain`, `unknown` and `posted` jobs by the time their final call started.
7. **First post review after a pause.** Ruling: it applies again after 90 days without a post on that network (setting `first_post_pause_days`).
8. **Block editor panel.** Ruling: a plain script (`assets/editor.js`) using `PluginDocumentSettingPanel` from the global `wp` object, no build step. The classic meta box is registered with `__back_compat_meta_box`, so it shows only in the classic editor.

### Other decisions

- **Final names.** The adapter interface keeps the PRD's names. `publish()` also takes a `Deadline` (Threads polls container status before publishing), and `refresh()` takes a `$force` flag used for the single refresh after an auth error. Reconciliation can return a sixth outcome, `absent`: the post provably does not exist and may be resent with the same key. Only idempotent networks act on it.
- **Global PHP functions.** `crosspost_queue()`, `crosspost_preview()`, `crosspost_status()` and `crosspost_kill_switch()` are global functions with the plugin prefix, so site code can call them without importing a namespace.
- **"Dormant" versus "network off".** With every network off the transition hook returns at once: no rows and no events (lesson 15). With at least one network on, networks that are off get a `skipped` row with reason `network off` (case 8).
- **Mastodon idempotency window.** Mastodon keeps idempotency keys for about an hour. Inside 55 minutes of the first uncertain attempt, an uncertain job is resent with the same key; after that, the account's 40 most recent statuses are matched by normalised text (links compared by `href`, hashtags and mentions by their text). This needs the `read:statuses` scope.
- **Bluesky conflicts.** A create call that fails because our own record key exists is treated as uncertain; the next reconciliation reads the record back and marks it posted.
- **Threads containers.** The container step is "before send": every failure there is retryable. The container id and its creation time are stored on the job and reused on a retry within 23 hours. A text container is also polled until it is ready.
- **Bluesky cards.** A link card is built from local data when the network's image mode is "link card", when the post has no image, or when the image cannot be sent as an image (for example no alt text); the image is then uploaded as the card thumbnail. The site's own pages are never fetched.
- **Retries.** The backoff list is the number of retries: one try plus five retries by default, then `retries exhausted`. A `Retry-After` or rate-limit reset header wins when later. A 429 also puts the whole network into a cooldown until the reset time.
- **Auth errors.** One forced refresh and one retry of the refused call (it created nothing); if that fails too the job is held with `auth`, the account is marked `attention`, and one notice and one email (when an address is set) go out per network per day. The network stays blocked until a successful verify.
- **Image failure with "retry".** Only failures that come from the network (an upload error) are retried. Local reasons (animated GIF, SVG, no alt text, no editor) always fall back to text, since a retry cannot change them.
- **Held jobs that wait for an event** (preview needed, first post review, auth, kill switch, network off) get a next attempt time at their stale deadline. The event (confirming the preview, a verify, the kill switch off, a network switched on, a release) wakes them at once. This keeps a run from waking every minute for jobs that cannot move.
- **Kill switch.** Checked by the HTTP client before every request, so no code path can make an outbound call while it is on, plus before every job in a run. A call blocked mid-run leaves the job exactly as it was. While the switch is on no run event is scheduled.
- **Lock.** An `INSERT IGNORE` of a row in the options table, value `expiry:token`. An expired lock is replaced with a compare-and-swap `UPDATE`; release deletes only our own token.
- **Compare-and-swap updates.** Job updates name the states they expect, so two processes cannot both move a job out of `pending`. `wpdb::query()`'s returned row count is used to tell.
- **Schema.** A version option guards `dbDelta`; after it, `SHOW COLUMNS` lists the real columns and missing ones are added with `ALTER TABLE`. Missing columns are stored in an option, shown in the settings screen, `wp crosspost status` and an admin notice, and the runner refuses to send.
- **Text frozen at creation.** The text, image id, language and card are stored on the job. "Post now" after a cancel rebuilds the same row, so the unique key stays the once-per-network guarantee.
- **Counting.** Threads counts code points (conservative for emoji sequences). Bluesky and Mastodon count grapheme clusters; Mastodon counts every link as the instance's reserved URL length. With `intl`, graphemes come from ICU's break iterator; without it, PCRE `\X` is used and Indic conjuncts joined by a virama are merged, which matches ICU on the test cases.
- **Language.** Bluesky takes a BCP 47 tag (`hi-IN`), Mastodon an ISO 639 code (`hi`). Threads and X get none: their create calls document no language field.
- **Credentials as constants.** Only values that do not rotate. X refresh tokens, the Threads long-lived token and Bluesky sessions are always stored, since a refresh must write them. Constants are never copied into the database.
- **Verification.** A fingerprint of the identity fields (client id, app id, service and identifier, instance and token) is stored with the verified account. Changing any of them, or completing a new authorisation, requires a new verify. A different account gets a new account row, so the first post review applies again.
- **Logging.** Every message passes the scrubber with every stored secret known to it. Errors are also written to the PHP error log, scrubbed, when `WP_DEBUG_LOG` is on.
- **No runtime dependencies.** No model call in this plugin, so the `llm-json-php` exception does not apply.

### Tokens outside the Authorization header

Every API call sends tokens in an `Authorization` header except these documented endpoints that only accept query parameters:

- Threads token refresh: `GET https://graph.threads.net/refresh_access_token?grant_type=th_refresh_token&access_token=...`.
- Threads long-lived token exchange: `GET https://graph.threads.net/access_token?grant_type=th_exchange_token&client_secret=...&access_token=...`.

Token exchanges for X, Threads (short-lived) and Mastodon send codes and secrets in the POST body. URLs are never written to the log.

## Testing

- PHPUnit 10 with a stub layer for WordPress in `tests/stubs/`; every HTTP call is answered through `pre_http_request` and an unscripted call fails the test.
- `tests/Support/MemoryStore.php` implements the same store contract in memory for the behaviour tests.
- `tests/Integration/WpdbStoreTest.php` runs the real SQL against MySQL (CI starts a MySQL 8 service; locally it needs `CROSSPOST_TEST_DB_*`).
- The base test case fails any test whose log rows contain one of the suite's test secrets.

## Acceptance test map

| PRD test | Where |
|---|---|
| 1 to 14 | `tests/Feature/JobCreationTest.php` |
| 15 to 21, 35 to 40, 47, 69, 70 | `tests/Feature/RunnerTest.php` |
| 22 to 28 | `tests/Feature/OutcomeTest.php` |
| 29 to 34 | `tests/Feature/ReconcileTest.php` (X variant per ruling 1) |
| 41 to 46 | `tests/Text/RendererTest.php`, `tests/Text/CountingTest.php` |
| 48 | `JobCreationTest::testUtmParametersOnlyWhenTurnedOnForThatNetwork` |
| 49, 51 | `tests/Unit/BlueskyFacetsTest.php` |
| 50, 53 to 60 | `tests/Feature/ImageTest.php` |
| 52, 65, 67, 68, 76 | `tests/Feature/AdminTest.php` |
| 61 to 64, 66, 71, 72 | `tests/Feature/CredentialsTest.php` |
| 73, 74 | `tests/Feature/SchemaTest.php`, `tests/Integration/WpdbStoreTest.php` |
| 75 | `tests/Unit/ScrubberTest.php` and the log check in `tests/TestCase.php` |
