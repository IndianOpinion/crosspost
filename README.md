# crosspost

Posts to X, Threads, Bluesky and Mastodon when a post is published, through their official APIs, with per-network templates, image handling, a retry queue and a log. Never deletes or edits a remote post.

Written from scratch; not derived from any third-party plugin.

A naive "on publish, call the API" hook posts again on every edit, floods networks during imports, hangs the editor, and turns a timeout into a duplicate you cannot delete. crosspost queues one announcement per post per network, sends it outside the editor request with caps and spacing, and treats any call whose outcome is unknown as "maybe live" rather than retrying it blindly.

Measured result: in our own build of this feature, a two-step publish call that timed out had in fact gone live; only matching the text against the account's recent posts showed it. Without that check the retry would have posted twice.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

## Requirements

- PHP 8.1 or later, WordPress 6.4 or later.
- Outbound HTTPS to the networks' API hosts.
- Recommended: `intl` (grapheme counting; a PCRE fallback is used without it), GD or Imagick (resizing).
- WP-Cron, or a system cron running `wp crosspost run`.

## Install

With Composer (from the repository, until it is on Packagist):

```sh
composer require indianopinion/crosspost
```

Composer installs it as a `wordpress-plugin`; activate it in Plugins.

Without Composer: download a release, unzip it to `wp-content/plugins/crosspost`, activate. The plugin loads its own autoloader (`src/autoload.php`) when there is no `vendor/` directory. Nothing is needed at runtime from Composer.

As a must-use plugin: copy the directory to `wp-content/mu-plugins/crosspost` and add the loader stub from `examples/mu-plugin-loader.php`. Must-use plugins never run activation or uninstall hooks: tables are created on first use, and to remove the data run `wp eval '\IndianOpinion\Crosspost\Uninstaller::run();'` before deleting the files.

## Quick start

1. Settings > Crosspost > a network tab. Enter the app credentials (or set constants, see `examples/wp-config-constants.php`).
2. Press "Connect" (X, Threads, Mastodon) or save an app password (Bluesky), then "Verify credentials".
3. Read the preview of your three latest posts and press "Confirm this preview".
4. Press "Switch on".
5. Publish a post. The first announcement on a new network waits in "first post review" at the top of the General tab. Read it, press "Send". Later posts flow on their own.

Check it works:

```sh
wp crosspost status
wp crosspost preview 123
wp crosspost queue --state=held
wp crosspost log --since="1 hour ago"
```

## Configuration

Settings > Crosspost. Every value also has a filter (see "Hooks").

| Setting | Default | Notes |
|---|---|---|
| Kill switch | off | Stops every outbound call. Constant `CROSSPOST_KILL_SWITCH` wins. |
| Network on | off for all four | Needs verified credentials and a confirmed preview. |
| Post types | `post` | `crosspost_post_types` |
| Maximum post age at publish | 24 hours | Older posts are skipped as `too old` (imports, backdating). |
| Delay before the first run | 60 seconds | A quick unpublish still wins. |
| Daily cap | 10 per rolling day | Hard ceiling 50 per network. 0 holds everything. |
| Minimum spacing | 10 minutes | Per network. |
| Jobs per run | 4, at most 1 per network | |
| Run time budget | 45 seconds | |
| Backoff | 5 min, 15 min, 1 h, 4 h, 12 h | `crosspost_backoff`; then `retries exhausted`. |
| Maximum queue age | 24 hours | Unsent jobs older than this are skipped as `stale`. |
| Stale "sending" threshold | 15 minutes | Then `uncertain` (or `unknown` on X). |
| Reconciliation window | 24 hours | Then `never landed`; never resent automatically. |
| First post review again after | 90 days without a post | |
| Template | `{title}` blank line `{url}` | Per network. |
| Image | attach (X: text only) | Bluesky also "link card only". |
| Require alt text | on | No alt text means text only. |
| When the image fails | post as text only | Or "retry later". |
| UTM parameters | off | Per network, with a campaign name. |
| Mastodon visibility | public | public, unlisted, private. |
| Language | site language | Bluesky and Mastodon. |
| Log / job retention | 90 / 180 days | |
| Notification email | empty | Admin notice only when empty. |

Placeholders: `{title}`, `{excerpt}`, `{url}`, `{site_name}`, `{author}`, `{category}`, `{tags}` (hashtags), `{date}`, plus any you add with `crosspost_placeholders`.

Credential constants (optional): `CROSSPOST_X_CLIENT_ID`, `CROSSPOST_X_CLIENT_SECRET`, `CROSSPOST_THREADS_APP_ID`, `CROSSPOST_THREADS_APP_SECRET`, `CROSSPOST_BLUESKY_SERVICE`, `CROSSPOST_BLUESKY_IDENTIFIER`, `CROSSPOST_BLUESKY_APP_PASSWORD`, `CROSSPOST_MASTODON_INSTANCE`, `CROSSPOST_MASTODON_TOKEN`. Rotating tokens are always stored in the database, because the plugin must write the new value after a refresh.

### Connecting each network

- **X**: create an app in the X developer portal, OAuth 2.0, type "Web App", redirect URL `https://your-site/wp-admin/admin-post.php?action=crosspost_oauth_callback`. Scopes requested: `tweet.read tweet.write users.read offline.access`. X posts are text only in this version.
- **Threads**: a Threads app in the Meta developer dashboard with the same redirect URL. Scopes: `threads_basic,threads_content_publish`. Images are fetched by Threads from your site, so they must be on public HTTPS.
- **Bluesky**: create an app password in the account's settings (four groups of four characters). Never the main password; the settings screen refuses anything that does not look like an app password. A self-hosted data server can be set as the service URL.
- **Mastodon**: either paste a token from the instance's development settings (scopes `read:accounts read:statuses write:statuses write:media`), or press "Connect", which registers this site as an app on the instance. The instance URL must be HTTPS.

## How it works

1. A status transition into `publish` (from anything but `publish`) creates one job per network: rendered text, chosen image, frozen. Saves of a published post create nothing. Each (post, network) pair gets at most one job, ever; the database enforces it.
2. Publishing writes rows and schedules one event a minute ahead. No network call happens in the editor request.
3. A run takes a lock (an atomic insert in the options table, with an expiry), turns stuck `sending` jobs into `uncertain`, reconciles uncertain jobs, then sends at most one due job per network.
4. Before each send it checks again: kill switch, network switch, credentials, cooldown, preview confirmation, first post review, cap, spacing, and that the post is still published and not opted out.
5. The job is marked `sending` and logged before the first remote call. The adapter returns one outcome: posted, retryable, uncertain, permanent or auth.
6. Uncertain jobs are checked without posting again: Mastodon resends with the same `Idempotency-Key` within the server's key window, then matches recent statuses; Bluesky reads back the record key chosen at creation, and resends with the same key only when it is provably absent; Threads matches the account's recent posts by normalised text. X is never checked automatically: an X post whose outcome is unknown becomes `unknown` and waits for a person to resend it.

Job states: `pending`, `held` (cap, spacing, cooldown, kill switch, network off, auth, preview needed, first post review), `sending`, `uncertain`, `unknown`, `posted`, `failed`, `skipped`, `cancelled`.

Length is counted by each network's rule: X weighted count (links 23, emoji and characters outside the light ranges 2), Threads 500 code points, Bluesky 300 graphemes, Mastodon graphemes with links as 23 and the instance's own limit. When text is too long the excerpt is shortened first, then whole hashtags dropped, then the title cut. The URL is never cut. X's counting rules are written from the published rules (configuration version 3) in `src/Text/XWeightedCount.php`, with fixture tests; X may change them, so check the fixtures if X starts refusing posts as too long.

## Hooks

Filters: `crosspost_post_types`, `crosspost_should_post`, `crosspost_template`, `crosspost_placeholders`, `crosspost_text`, `crosspost_url`, `crosspost_image`, `crosspost_alt_text`, `crosspost_language`, `crosspost_backoff`, `crosspost_daily_cap`, `crosspost_adapters`, `crosspost_http_args`.

Actions: `crosspost_job_created`, `crosspost_posted`, `crosspost_failed`, `crosspost_uncertain`, `crosspost_network_needs_attention`.

PHP functions: `crosspost_queue($postId, $network)`, `crosspost_preview($postId, $network)`, `crosspost_status($postId)`, `crosspost_kill_switch()`.

WP-CLI: `wp crosspost status | run | preview | verify | queue | send | cancel | kill-switch | backfill | log`. `backfill` prints what it would queue unless given `--apply`.

See `examples/filters.php`.

## Limitations

- One account per network. The tables carry the network and account id so several can come later.
- One image per post. No threads of posts, polls, video or stories.
- X posts are text only and an uncertain X post needs a person (it uses the create-post endpoint only).
- Threads takes no language tag; Threads images need a public HTTPS URL, otherwise the post goes out as text only.
- Bluesky uses app passwords only, not the protocol's OAuth flow.
- Only `http` and `https` links are recognised for counting; bare domains count as text.
- The plugin never edits, deletes or hides a remote post. Unpublishing a local post does nothing remotely.

## FAQ

**Will editing a published post post it again?** No. Only the first transition into `publish` creates a job, and a (post, network) pair can only ever have one.

**I published 200 old posts by mistake.** Posts older than 24 hours at publication are skipped as `too old`. For the rest, the cap and spacing apply, and jobs older than 24 hours go stale. Turn on the kill switch first if in doubt.

**A job says `unknown` or `never landed`.** The final call may have succeeded. Look at the account. If the post is not there, use "Resend" (Tools > Crosspost) or `wp crosspost send <id>`.

**Nothing is being sent.** Run `wp crosspost status`. Look for the kill switch, a network that is off or needs attention, a pending first post review, the cap, or a late WP-Cron (an admin notice appears after 30 minutes).

**Where are my tokens?** In non-autoloaded options, or in constants. They never appear in the log, notices, REST or CLI output. Uninstalling deletes them locally; revoke the app's access in each network as well.

## Uninstall

Deleting the plugin from the Plugins screen drops its three tables and deletes its options, transients, post meta and scheduled events. It makes no network call and does not revoke tokens remotely: revoke the app's access in each network's own settings. Deactivating only clears scheduled events.

## Licence

MIT. Copyright (c) 2026 Indian Opinion. See [LICENSE](LICENSE).
