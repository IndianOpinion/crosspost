=== Crosspost ===
Contributors: indianopinion
Tags: social, mastodon, bluesky, threads, crosspost
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Announces each newly published post on X, Threads, Bluesky and Mastodon through their official APIs. Never deletes or edits a remote post.

== Description ==

Written from scratch; not derived from any third-party plugin.

When a post is published for the first time, crosspost queues one announcement per network and sends it outside the editor request, through each network's official, documented API and your own credentials.

* One announcement per post per network, ever. Edits never post again.
* Per-network templates, counted by each network's own rules.
* Images resized to fit each network, with alt text, and a text-only fallback.
* Daily cap per network over a rolling day, spacing, and a kill switch.
* Every network is off by default. Switching one on needs verified credentials and a confirmed preview.
* The first post on a new network waits for a person to read it.
* A call whose outcome is unknown is checked, never blindly retried.
* A log of every attempt, with secrets scrubbed.
* WP-CLI commands for status, previews, the queue and backfill.

The plugin never edits, deletes or hides a remote post.

== Installation ==

1. Upload the `crosspost` directory to `wp-content/plugins/` and activate it.
2. Go to Settings > Crosspost, open a network tab and connect the account.
3. Verify the credentials, confirm the preview, and switch the network on.
4. If WP-Cron is disabled, run `wp crosspost run` from a system cron every few minutes.

== Frequently Asked Questions ==

= Which credentials do I need? =

X: an OAuth 2.0 app (client id). Threads: a Threads app (app id and secret). Bluesky: an app password, never the main password. Mastodon: an instance URL and a token, or connect to register an app.

= What happens on uninstall? =

Local data is removed: tables, options, transients, post meta and scheduled events. No call is made to any network and tokens are not revoked remotely; revoke the app's access in each network's settings.

= Does it send anything about my readers? =

No. It sends the post title, excerpt, link, image and alt text, which are already public. It stores the connected account's id and display name and the id of the administrator who confirmed each preview.

== Changelog ==

= 0.1.0 =
* First release.
