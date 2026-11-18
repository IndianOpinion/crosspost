# crosspost: product requirements

Status: draft for build. Target release 0.1.0.

A WordPress plugin. When a post is published for the first time, it posts a
short announcement of that post to X, Threads, Bluesky and Mastodon, through
each network's official, documented API and the site owner's own
credentials. Each network has its own template, its own image handling, its
own daily cap and its own on and off switch. Every attempt goes through a
retry queue and lands in a log. The plugin never deletes or edits a post on a
remote network.

Written from scratch; not derived from any third-party plugin.

## 1. Problem

A publisher who wants each new article announced on four networks either
does it by hand (slow, forgotten at night, inconsistent) or wires up a naive
"on publish, call the API" hook. The naive hook goes wrong in predictable
ways:

- It fires on every save of a published post, so an edit to a typo posts the
  article again.
- It fires for scheduled posts, imports and bulk status changes all at once,
  so a backfill of old articles floods every network in a minute and trips
  the network's rate limit or spam checks.
- It runs inside the editor's save request. A slow network makes "Publish"
  hang; a network outage makes it fail or silently drop the post.
- A publish call that times out has often landed anyway. A blind retry posts
  the same text twice, and the duplicate stays up because nothing deletes it.
- Each network counts length differently (weighted characters, graphemes,
  links counted as a fixed length, an instance-specific limit). A template
  that fits one network is rejected by another, or is cut through the middle
  of a URL or an Indic syllable.
- Each network takes images differently (an upload, a public URL the network
  fetches, a byte cap far below the original file). An image failure often
  takes the whole post with it.
- Error messages from the APIs carry tokens and request bodies. Logged as
  they come, they leak credentials into the database and the debug log.
- Nobody sees what will be posted until it is live, and the first post on a
  new network is the one most likely to be wrong.

Measured in our own build of this feature: a two-step publish call that
timed out had in fact gone live, and only matching the text against the
account's recent posts told us so. Without that check, the retry would have
posted the same text twice.

## 2. Goals and non-goals

Goals:

1. Post once per network for each post's first publication, never on later
   saves, through a queue processed outside the editor request.
2. One adapter per network, each using only that network's official,
   documented API, behind one shared adapter interface.
3. Per-network templates with placeholders, length handling that follows
   each network's own counting rules, and a preview of the exact text.
4. Image handling per network: pick, resize, re-encode, upload or reference,
   with alt text, and a defined fallback to text only.
5. A retry queue with backoff, error classification and a "landed or not"
   reconciliation for uncertain outcomes, so a timeout never causes a
   duplicate.
6. A per-network daily cap, a per-network switch (off by default) and a
   global kill switch that stops every outbound call at once.
7. A preview of what will be posted, confirmed by an administrator, before
   the first post goes out on a network.
8. A log of every attempt that an operator can read at 2 a.m., with no
   secret in it.

Non-goals:

- Deleting, editing, hiding or replying to remote posts. There is no code
  path that does any of these. Unpublishing or deleting a local post does
  nothing remotely.
- Posting to any network other than the four named, or to any network
  through a scraper, a headless browser, an unofficial endpoint or a
  password other than an app password the network itself issues.
- Reading replies, mentions, follower counts or analytics.
- Threads of several posts, polls, video, audio or stories.
- Writing the announcement text with a model. The text comes from the
  template. A site may rewrite it through a filter (section 5.4).
- Scheduling announcements at chosen times of day beyond the cap and the
  spacing rules in section 4.6. Anything richer is the site's own code.
- Multi-account posting to the same network in 0.1.0 (one account per
  network). See open question 13.3.
- Shortening links through a third-party shortener.

## 3. Users and setting

- Installed by a site owner or developer on a self-hosted WordPress site
  that publishes articles and wants each one announced on some or all of the
  four networks.
- Configured by an administrator (`manage_options`). Editors and authors see
  a per-post panel: the preview, a "do not crosspost" box, and the per-post
  status. They cannot change credentials, caps or templates.
- Runs on ordinary PHP hosting. Outbound HTTPS to the four networks' API
  hosts must be allowed. Images for Threads must be reachable by the network
  over public HTTPS (section 4.8).
- Queue processing runs on WP-Cron, or on a real system cron that calls
  WP-CLI. Both are supported. Sites with `DISABLE_WP_CRON` must run the CLI
  command (section 5.6) on a schedule.
- Works as a normal plugin. It is not a must-use plugin, because it has
  admin screens and an uninstall routine.

## 4. Behaviour

### 4.1 Definitions

- **Network**: one of `x`, `threads`, `bluesky`, `mastodon`.
- **Adapter**: the class that talks to one network (section 5.2).
- **Job**: one planned announcement of one post on one network. At most one
  job exists per (post, network) pair, ever.
- **Attempt**: one try at sending a job. A job has one or more attempts.
- **Job states**: `pending` (waiting to send), `held` (waiting for a reason
  that may clear: cap, preview, network off, kill switch), `sending` (an
  attempt is in progress), `uncertain` (the final call's outcome is unknown),
  `posted` (live, remote id known), `failed` (gave up), `skipped` (chosen not
  to send, with a reason), `cancelled` (an editor or administrator stopped
  it before it was sent).
- **Rolling day**: the 24 hours before now. Caps are counted over a rolling
  day, not a calendar day, so midnight never releases a burst.

### 4.2 Which posts are announced

1. A job is created only when a post moves into `publish` from any status
   that is not `publish` (`draft`, `pending`, `future`, `auto-draft`,
   `private`, `new`). Saves of a post that is already published create
   nothing.
2. Scheduled posts are announced when they actually go live (`future` to
   `publish`), not when they are scheduled.
3. Only the post types in the allowed list are announced. Default: `post`.
4. Password-protected posts are never announced.
5. Posts whose publish date (GMT) is older than the maximum age (default
   24 hours) at the moment of publication are skipped with reason
   `too old`. This catches imports, backdated posts and status changes of
   old content.
6. A post with the per-post "do not crosspost" flag set is skipped with
   reason `opted out`. The flag can be set before or after publication;
   after publication it cancels any job not yet `sending`, `uncertain` or
   `posted`.
7. A post that already has a job for a network gets no second job for that
   network, whatever happens to it later: unpublish and republish, trash and
   restore, change of slug, change of title. The uniqueness is enforced in
   the database (section 6), not only in code.
8. A network that is off at the moment of publication still gets a job, in
   state `skipped` with reason `network off`, so the log explains why
   nothing went out. Turning the network on later does not revive old
   skipped jobs (backfill is a separate, explicit command, section 5.6).
9. Posts created by the REST API, XML-RPC, WP-CLI, the block editor, the
   classic editor or a feed importer are treated the same way. The trigger
   is the status transition, not the screen.
10. A filter (section 5.4) can veto any post, or any (post, network) pair,
    with a reason. The reason is logged.
11. When a large number of posts go live at once (bulk edit, import), job
    creation is cheap and never calls a network. The cap and spacing rules
    in 4.6 decide what goes out.

### 4.3 Creating a job

12. On the transition, for each network the plugin renders the text from the
    template (section 4.7) and selects the image (section 4.8), then stores
    the job with the rendered text, the chosen image attachment id, the
    template version, and `pending` (or `held` / `skipped` as 4.2 and 4.6
    decide).
13. Rendering at creation time freezes what the editor previewed. A change
    to the post after creation does not change the queued text. An editor
    who wants new text cancels the job and uses "post now" from the post
    panel (an explicit, logged manual send, still subject to the cap and the
    "once per network" rule while no job has reached `posted`).
14. Before each attempt the plugin re-checks: the post still exists, is
    still `publish`, is not password-protected, has no opt-out flag, and the
    veto filter still passes. If any check fails the job becomes `skipped`
    with that reason. A post taken down in the minutes before its turn never
    goes out.
15. Job creation never blocks the publish request on a network call. It
    writes rows and schedules a single processing event a short delay ahead
    (default 60 seconds), so a quick unpublish after an accidental publish
    still wins.

### 4.4 Processing the queue

16. One processing run takes a lock (an atomic insert of a lock row with an
    expiry, default 10 minutes). If the lock is held and not expired, the
    run exits. An expired lock is replaced.
17. A run first reconciles `uncertain` jobs (4.5), then sends due jobs.
18. Due jobs are `pending` jobs whose next attempt time has passed, oldest
    first, at most one job per network per run by default (spacing), and at
    most a run-wide limit (default 4).
19. Before each network call the run checks the global kill switch and the
    network's switch again. If either is off, it stops at once and leaves
    the job unchanged.
20. Before each attempt the run checks the network's cap (4.6). A job that
    would exceed it becomes `held` with reason `cap` and a next attempt
    time equal to the moment the oldest counted post leaves the rolling day.
21. The job moves to `sending` and the attempt is logged before the first
    remote call. If PHP dies during the call, the job stays `sending`. The
    next run treats a `sending` job older than the stale threshold (default
    15 minutes) as `uncertain`, never as `pending`.
22. A run never sleeps between jobs of the same network. Image readiness
    waits (4.8) are bounded and counted against a per-run time budget
    (default 45 seconds). When the budget runs out the run stops and leaves
    the rest for the next run.
23. When the queue still has due jobs after a run, the run schedules the
    next one. When it is empty, no event is scheduled.

### 4.5 Outcomes, retries and reconciliation

Each attempt ends in exactly one of these outcomes, decided by the adapter
from the HTTP status, the response body and the transport error:

24. **Posted**: the network returned the new post's id. The job becomes
    `posted` with the remote id and, where the API returns one, the public
    URL. Never attempted again.
25. **Retryable before send**: the request provably did not create a post.
    DNS failure, connection refused, TLS failure, a timeout while connecting,
    HTTP 429, HTTP 5xx on a call that is not the final create call, or an
    image upload that failed. The job returns to `pending` with the next
    attempt time from the backoff schedule (default 5 minutes, 15 minutes,
    1 hour, 4 hours, 12 hours). A `Retry-After` header or a documented
    rate-limit reset header, when present, wins if it is later.
26. **Uncertain**: the final create call was sent and the outcome is
    unknown. A read timeout, a connection reset after the body was sent, or
    an HTTP 5xx from the final create call. The job becomes `uncertain`. It
    is never retried blindly.
27. **Permanent**: the network refused the content or the request in a way
    that will not change on retry. HTTP 400, 403 for content reasons, 413,
    422, a duplicate-content error, a text-length error. The job becomes
    `failed` with the reason. No retry.
28. **Auth**: HTTP 401, an expired or revoked token, a refresh that failed.
    The job returns to `held` with reason `auth`. The network is marked
    `needs attention`, no further calls are made to it until an administrator
    reconnects, and one admin notice and (optionally) one email are raised
    per network per day, not per job.
29. After the last backoff step a job becomes `failed` with reason
    `retries exhausted`.
30. A job still `pending` or `held` past the maximum queue age (default
    24 hours after creation) becomes `skipped` with reason `stale`. An
    announcement a day late is worse than none.

Reconciliation of `uncertain` jobs, per adapter:

31. **Mastodon**: every create call carries an `Idempotency-Key` header
    derived from the job id. An `uncertain` job is resent with the same key;
    the server returns the original status instead of creating a second one.
    The key is stable for the life of the job. The server remembers keys
    for about an hour only, so the same-key resend is used within 55
    minutes of the first uncertain attempt. After that the job is
    reconciled as in 33: one listing of the account's 40 most recent
    statuses, matched by normalised text (links compared by their target),
    never a resend. This needs the `read:statuses` scope. (Builder's
    decision, accepted at review on 18 November 2026.)
32. **Bluesky**: the record key is chosen by the plugin at job creation (a
    timestamp identifier in the format the protocol defines) and sent on the
    create call. An `uncertain` job first reads that record key back; if it
    exists the job is `posted`; if not, the create call is resent with the
    same key, so a race produces a conflict, not a duplicate.
33. **Threads and X**: no idempotency key is available. The adapter lists
    the account's recent posts through the official API and matches by a
    hash of the normalised text (whitespace collapsed, entities decoded,
    links compared by their target). A match makes the job `posted` with
    the matched id. No match: the job stays `uncertain` and is checked again
    on the next run. After the reconciliation window (default 24 hours) with
    no match, the job becomes `failed` with reason `never landed`; it is not
    resent automatically, because a late landing after that point would be a
    duplicate. An administrator may resend it by hand from the log.
34. If the listing call itself fails, the job stays `uncertain` and the
    error is logged. Reconciliation never posts.
35. On X, where reading costs quota, the adapter makes at most one listing
    call per run, covering every uncertain X job at once.
36. A two-step network (Threads: create a container, then publish it) treats
    the container step as "before send" and the publish step as the final
    create call. A container id is stored on the attempt, so a retried
    publish of a still-valid container is possible where the API allows it.

### 4.6 Switches, caps and spacing

37. Every network is off by default. Turning a network on requires valid
    credentials (a successful "verify credentials" call, 5.2) and a confirmed
    preview (4.10).
38. The global kill switch stops every outbound call: sending, uploads,
    reconciliation listings and credential checks. Jobs created while it is
    on are `held` with reason `kill switch` and wait (subject to the stale
    rule in 30). The switch can be set in the settings screen, by WP-CLI, or
    by a constant in `wp-config.php`. The constant wins over the option and
    the settings screen shows that it is set by the constant.
39. Each network has a daily cap (default 10 posts per rolling day). Counted
    jobs are those `posted`, `sending` and `uncertain` in the rolling day.
    `uncertain` counts, because it may be live. Failed and skipped jobs do
    not count.
40. A cap of 0 means "hold everything" for that network without turning it
    off.
41. Each network has a minimum spacing between posts (default 10 minutes).
    A due job inside the spacing window waits; it is not counted as a
    failure.
42. A network-level cooldown follows a 429: the network gets no further
    calls until the reset time, whatever the job.
43. Caps and spacing apply to manual "post now" and to backfill as well.
    There is no way to bypass them except raising the setting.
44. The documented API limits of each network are higher than the defaults.
    The plugin also enforces a hard ceiling per network that the setting
    cannot exceed (section 7), set at or below the network's published
    posting limit for a single account.

### 4.7 Templates and text

45. Each network has its own template. Placeholders: `{title}`,
    `{excerpt}`, `{url}`, `{site_name}`, `{author}`, `{category}` (primary
    or first category), `{tags}` (tag names as hashtags), `{date}`. Unknown
    placeholders render as empty and raise a warning in the preview.
46. Default template for all four: `{title}` then a blank line then `{url}`.
47. Placeholder values are plain text: tags stripped, shortcodes removed,
    entities decoded, whitespace collapsed, control characters removed.
    Newlines in the template are kept. Three or more newlines collapse to
    two.
48. `{excerpt}` is the manual excerpt when present, otherwise the first
    sentences of the content up to the space available. It never includes a
    "read more" string.
49. `{tags}` turns each tag name into a hashtag: spaces and punctuation
    removed, letters and digits in any script kept, Unicode letters
    allowed. A tag that becomes empty or all digits is dropped. Hashtags are
    added in tag order until the space runs out; a hashtag is never cut.
50. Length is counted with the network's own rule, through the adapter:
    - X: the weighted count the network publishes (a link counts as a fixed
      length, characters outside the light-weight ranges count double),
      limit as published for a standard account.
    - Threads: characters, limit 500.
    - Bluesky: grapheme clusters, limit 300.
    - Mastodon: characters with every link counted as 23, limit read from
      the instance's configuration and cached for a day (default 500 when
      the instance does not say).
51. When the rendered text is too long, the plugin shortens the variable
    parts in this order: `{excerpt}`, then `{tags}` (drops whole hashtags),
    then `{title}`. It never cuts `{url}`, never cuts a link, never cuts
    inside a grapheme cluster (an Indic conjunct, an emoji with modifiers or
    a flag), and prefers a word boundary in the last 40 percent of the
    allowed span. A shortened part ends with "...".
52. A URL on its own line at the end of the template stays on its own line
    after shortening.
53. If the fixed parts alone (template literals plus the URL) leave less room
    than a minimum body (default 40 counted units), the job fails at
    creation with reason `template leaves no room`, and the preview shows
    the same error.
54. After building the final text the plugin counts it again with the
    network's rule. Any text that still breaks the limit is refused, not
    sent. The count is asserted at the point of sending, whoever calls.
55. Links: `{url}` is the post's permalink by default; a filter may replace
    it (for example with a tracked link). UTM parameters can be appended per
    network from the settings, off by default.
56. Bluesky needs links declared as rich-text facets with UTF-8 byte
    offsets. The adapter computes them from the final text. Byte offsets are
    computed on the exact UTF-8 string sent, never on a character count.
57. Bluesky shows no link preview unless the post carries one. When the post
    has no images, the adapter attaches an external link card built from
    local data: title, excerpt, URL and the featured image as the card's
    thumbnail (uploaded as a blob under the network's size cap). It never
    fetches the site's own page over HTTP to build the card.
58. Mentions and hashtags in the template are sent as plain text. The plugin
    does not resolve handles. Bluesky hashtags are declared as tag facets so
    they are clickable.
59. Language: where the API accepts a language tag (Bluesky, Mastodon,
    Threads where documented), the post language is the site locale, or a
    per-post override from a filter.
60. Mastodon visibility is a setting (default `public`); `direct` is not
    offered.

### 4.8 Images

61. The image for a job is chosen in this order: a per-post override chosen
    in the post panel, the featured image, the first image attachment in the
    content, none. A filter can change the choice.
62. Each network has an image mode: `attach` (default), `link card only`
    (Bluesky), or `none`.
63. One image per post in 0.1.0. Multiple images are a later version.
64. The adapter declares its limits: maximum bytes, maximum pixel edge,
    accepted MIME types, maximum alt text length, and whether the image must
    be uploaded or given as a public URL.
65. Image preparation picks the smallest registered size that is at least
    the network's preferred edge, or the original. If the file breaks the
    byte or edge limit, the plugin makes a resized and re-encoded JPEG copy
    with the WordPress image editor, stepping quality down (85, 75, 65) and
    then the edge down, until it fits. The copy is a temporary file, deleted
    after the attempt. The media library is never changed.
66. Animated GIF, SVG, AVIF where the network does not accept it, and any
    file the image editor cannot open are not sent. The job continues as
    text only (see 70).
67. Alt text comes from the attachment's alternative text, then its caption.
    It is trimmed to the network's alt limit at a grapheme boundary. An
    image with no alt text is sent without alt text only if the setting
    "require alt text" is off (default on: no alt text means text only, and
    the log says why).
68. Threads takes images by public URL, not by upload. The adapter refuses
    an image URL that is not public HTTPS, or whose host resolves to a
    private, loopback or link-local address, and falls back to text only. A
    Threads image container is polled for readiness with a bounded wait
    (default up to 30 seconds in steps of 5) and an error or expired state
    ends the image path.
69. Mastodon media upload may be asynchronous. The adapter polls the media
    id until it is processed, within the same bounded wait, and only then
    creates the status.
70. Image failure policy, per network (default `text only`): when the image
    path fails for any reason, the post goes out as text only and the log
    records the image error. The alternative, `retry`, treats an image
    failure as retryable before send (25). There is no option that drops
    the whole post because of an image.
71. EXIF and other metadata are stripped from re-encoded copies. Original
    files sent unchanged keep their metadata; the settings screen says so.

### 4.9 Credentials and connection

72. **X**: OAuth 2.0 authorisation code with PKCE, under the operator's own
    developer app (client id, optional client secret), scopes for writing
    posts, reading the account's own posts (for reconciliation), uploading
    media and offline access. The access token is refreshed with the refresh
    token before it expires. A refresh returns a new refresh token; the
    plugin stores the new pair in one write before using it.
73. **Threads**: the official Threads API with the operator's own app and a
    long-lived user token, obtained through the documented authorisation
    flow. The token is refreshed through the documented refresh endpoint
    when less than 7 days of life remain. A refresh failure marks the
    network `needs attention`.
74. **Bluesky**: the account's handle or identifier, the service URL
    (default the main public service, configurable for a self-hosted data
    server), and an app password created in the account's settings. Never
    the main account password: the settings screen says so and rejects a
    value that does not have the app password shape. Sessions are created
    sparingly (session creation is rate-limited on the network side): the
    access and refresh tokens are cached and refreshed, and a new session is
    created only when refresh fails. OAuth for Bluesky is open question 13.4.
75. **Mastodon**: the instance URL and an access token with only the scopes
    needed to write statuses and media and read the account's own statuses.
    The operator either pastes a token created in the instance's
    development settings, or runs the documented app registration and
    authorisation flow from the settings screen.
76. Every OAuth flow uses a state value bound to the current user and
    expiring in 10 minutes, and a redirect URL on the site's own admin.
77. "Verify credentials" calls each network's documented "who am I" or
    equivalent read endpoint and shows only the display name and the
    network's account id in the settings screen.
78. Credentials may be given as constants in `wp-config.php` instead of the
    settings screen. Constants win. The settings screen shows "set by
    constant" and never the value.

### 4.10 Preview and first-post confirmation

79. The post panel shows, for each network that is on, the exact text that
    would be sent, its counted length against the limit, the image that
    would be attached (or why none) and the alt text. It updates on demand
    (a "refresh preview" button), not on every keystroke.
80. The settings screen for each network has a "preview" step: it renders
    the template against the three most recent published posts (or chosen
    posts) with the network's real counting and image rules, and makes no
    network call except optional credential verification.
81. A network cannot be switched on until an administrator has viewed that
    preview and pressed "confirm". The confirmation records the user id,
    the time and a hash of the template and the network settings that
    affect output.
82. If the template or an output-affecting setting changes after
    confirmation, the network is put back to `held` for new jobs with reason
    `preview needed`, and the administrator must confirm the new preview.
    Jobs created before the change keep their frozen text.
83. Until the network's first job reaches `posted`, the first job is
    additionally held with reason `first post review` and is shown at the
    top of the settings screen with "send" and "cancel" buttons. Nothing
    goes out on a new network until a person has seen the actual first
    announcement. After the first `posted` job, later jobs flow without this
    step. Re-connecting a different account resets this.
84. Preview is available from WP-CLI as well (section 5.6) and needs no
    credentials.

### 4.11 Never edit, never delete

85. No adapter has a method to edit, delete, hide, unpublish or reply to a
    remote post, and the adapter interface does not declare one.
86. Unpublishing, trashing or deleting a local post cancels jobs that have
    not been sent and leaves posted ones alone. The post panel says "already
    posted on <network>; this plugin never removes remote posts".
87. Updating a published post never sends anything, and never changes a
    queued job's text (13).
88. Uninstall deletes local data only (section 6). It makes no remote call.

### 4.12 Failure modes summary

| Failure | Behaviour | Open or closed |
|---|---|---|
| Network down or timing out before send | Retry with backoff, then `failed` | Closed (nothing posted) |
| Final call times out | `uncertain`, reconcile, never blind retry | Closed against duplicates |
| 429 | Cooldown to the reset time, retry | Closed |
| Token expired or revoked | Refresh once; on failure hold the network, notify once a day | Closed |
| Content refused | `failed`, reason logged | Closed |
| Image fails | Text only (default) | Open for the text, closed for the image |
| Template leaves no room | `failed` at creation, shown in preview | Closed |
| Cap reached | `held` until room frees, or `stale` after the queue age | Closed |
| Kill switch on | No outbound calls at all; jobs held | Closed |
| WP-Cron not running | Jobs wait; settings screen warns when the oldest due job is over 30 minutes late | Closed |
| Lock left behind by a dead run | Replaced after its expiry | Recovers |
| PHP dies mid-call | `sending` becomes `uncertain` after the stale threshold | Closed against duplicates |
| Database table missing a column | Plugin refuses to send, shows the missing columns | Closed |
| Post taken down before its turn | Re-check skips it | Closed |

Fail closed is the rule for anything that could post twice, post the wrong
thing, or post when told not to. The only fail-open path is the image, where
the text still goes out without it.

## 5. Public interface

### 5.1 Plugin

- Plugin name "crosspost", slug `crosspost`, text domain `crosspost`.
- Namespace `IndianOpinion\Crosspost`.
- Requires PHP 8.1 and WordPress 6.4.
- No runtime Composer dependency. Network calls through the WordPress HTTP
  API (`wp_remote_request`), no SDKs.

### 5.2 Adapter interface

One interface, one implementation per network. Names are a proposal; the
builder may refine them and records the final names in ARCHITECTURE.md.

`interface Adapter`:

- `id(): string`: `x`, `threads`, `bluesky` or `mastodon`.
- `label(): string`: human name for screens.
- `limits(): Limits`: text limit, counting rule, image limits, alt limit,
  image transport (`upload` or `public_url`), whether idempotent creation
  is supported.
- `count(string $text): int`: length by the network's own rule.
- `verify(Credentials $c): Account`: read-only check; returns account id
  and display name.
- `prepareMedia(MediaFile $f, Credentials $c): MediaRef`: upload or
  register the image; may poll for readiness within a given deadline.
- `publish(Draft $d, ?MediaRef $m, Credentials $c): Outcome`: the only
  write. Takes the frozen text, language, the stable idempotency material
  (job id, record key) and returns an `Outcome`.
- `reconcile(array $uncertain, Credentials $c): array`: maps job ids to
  `Outcome` (posted with remote id, or still unknown). Never writes.
- `refresh(Credentials $c): ?Credentials`: refreshes tokens where the
  network uses them; returns the new set or null when nothing changed.

Value objects (immutable): `Limits`, `Credentials` (never serialised to a
log; `__debugInfo` and string casts redact), `Account`, `Draft`,
`MediaFile`, `MediaRef`, `Outcome` (kind: `posted`, `retryable`,
`uncertain`, `permanent`, `auth`; remote id; remote URL; retry-after; a
scrubbed message; HTTP status).

Typed exceptions: `ConfigurationException`, `CredentialsException`,
`TransportException`, `ContentException`. Adapters convert every
transport and API error into an `Outcome`; they throw only for programmer
errors and configuration errors.

### 5.3 PHP API (namespace functions)

- `crosspost_queue(int $postId, string $network, array $args = []): int`:
  create a job by hand (manual "post now"). Obeys every rule in 4.2, 4.6
  and 4.10. Returns the job id or throws.
- `crosspost_preview(int $postId, string $network): array`: the rendered
  text, counted length, limit, image and alt text, and warnings. No network
  call.
- `crosspost_status(int $postId): array`: per network, the job state,
  reason, remote URL and times.
- `crosspost_kill_switch(): bool`: true when every outbound call is stopped.

### 5.4 Filters

Every default has a filter. All are prefixed `crosspost_`.

- `crosspost_post_types` (array): announced post types.
- `crosspost_should_post` (bool|string, post, network): veto; a string is
  the logged reason.
- `crosspost_template` (string, post, network).
- `crosspost_placeholders` (array of name to value, post, network): add or
  change placeholder values.
- `crosspost_text` (string, post, network): final text before the length
  check. The check runs after this filter.
- `crosspost_url` (string, post, network).
- `crosspost_image` (attachment id or 0, post, network).
- `crosspost_alt_text` (string, attachment id, network).
- `crosspost_language` (string, post, network).
- `crosspost_backoff` (array of seconds).
- `crosspost_daily_cap` (int, network): capped by the hard ceiling.
- `crosspost_adapters` (array of `Adapter`): replace an adapter (for tests
  or a self-hosted service). Adding a fifth network through this filter is
  not supported in 0.1.0.
- `crosspost_http_args` (array, network, purpose): timeouts and headers;
  `redirection` stays 0 for write calls whatever the filter returns.

### 5.5 Actions

- `crosspost_job_created` (job id, post id, network)
- `crosspost_posted` (job id, post id, network, remote id, remote URL)
- `crosspost_failed` (job id, post id, network, reason)
- `crosspost_uncertain` (job id, post id, network)
- `crosspost_network_needs_attention` (network, reason)

### 5.6 WP-CLI

`wp crosspost <subcommand>`:

- `status`: kill switch, each network's switch, credentials present (yes or
  no only), cap used and remaining, next release time, cooldown, oldest
  due job, last error (scrubbed).
- `run [--network=<n>] [--limit=<n>]`: process the queue once (for system
  cron).
- `preview <post-id> [--network=<n>]`: print the text, counted length,
  image and alt text. Needs no credentials, writes nothing.
- `verify <network>`: read-only credential check.
- `queue [--state=<s>] [--network=<n>]`: list jobs.
- `send <job-id>`: release a held job (first post review, or a manual
  resend of a `failed` job after a "never landed" outcome). Asks for
  confirmation unless `--yes`.
- `cancel <job-id>`
- `kill-switch on|off`
- `backfill --since=<date> --network=<n> [--limit=<n>] [--dry-run]`: create
  jobs for already-published posts that never had a job on that network.
  Default `--dry-run` prints what would be queued. Subject to caps and the
  stale rule computed from the backfill time, not the publish time.
- `log [--network=<n>] [--post=<id>] [--since=<date>]`.

### 5.7 Admin

- Settings page under Settings > Crosspost: global kill switch, one tab per
  network (connect, verify, template, image mode, alt text rule, cap,
  spacing, language, visibility for Mastodon, preview and confirm).
- Queue and log page under Tools > Crosspost: filterable list of jobs and
  attempts, with "send", "cancel" and "resend" where the state allows.
- Post panel (block editor sidebar panel and classic meta box): per network
  preview, state, remote link, "do not crosspost", image override, "post
  now" when allowed.
- Every admin action checks capability and a nonce. Settings need
  `manage_options`. The post panel needs `edit_post` on that post; "post
  now" needs `publish_posts`.

## 6. Data

### 6.1 Tables

`{prefix}crosspost_jobs`: one row per (post, network).

- `id`, `post_id`, `network`, `state`, `reason`, `text` (frozen),
  `text_hash`, `attachment_id`, `template_hash`, `idempotency_key`
  (Mastodon key or Bluesky record key), `remote_id`, `remote_url`,
  `attempts`, `next_attempt_at`, `created_at`, `updated_at`, `posted_at`.
- Unique key on (`post_id`, `network`). This is the "once per network"
  guarantee.
- Indexes on (`network`, `state`, `next_attempt_at`) and (`network`,
  `posted_at`).

`{prefix}crosspost_log`: one row per attempt and per notable event.

- `id`, `job_id` (nullable for network-level events), `network`, `event`
  (`attempt`, `posted`, `retry`, `uncertain`, `reconciled`, `failed`,
  `skipped`, `held`, `cancelled`, `auth`, `cooldown`, `image_fallback`),
  `http_status`, `message` (scrubbed, at most 255 bytes), `duration_ms`,
  `created_at`.

### 6.2 Options

- One autoloaded option with non-secret settings (switches, caps, spacing,
  templates, modes).
- One non-autoloaded option per network with credentials and tokens.
  Never autoloaded, never exposed through the REST API's settings
  endpoint.
- One non-autoloaded option per network with the preview confirmation
  record and the "first post done" marker.
- Post meta: the opt-out flag and the image override. Registered with
  `auth_callback` requiring `edit_post`, shown in REST only to users who can
  edit.

### 6.3 Retention

- Jobs: kept 180 days after their last change, then pruned by a daily
  event. The unique key still protects posts older than that because job
  creation also refuses posts older than the maximum age (4.2, item 5).
- Log: 90 days, pruned daily in batches of 1,000 rows.
- Both settings.

### 6.4 Schema changes

- The schema version is stored in an option. On upgrade the plugin runs
  `dbDelta`, then lists the real columns and indexes and adds any missing
  one with a direct `ALTER TABLE`. If a column is still missing it refuses
  to send and shows the missing names in the settings screen and in
  `wp crosspost status`.

### 6.5 Uninstall

`uninstall.php` drops both tables, deletes every option and transient the
plugin created, deletes the post meta keys, and clears its scheduled
events. It makes no network call and does not revoke tokens remotely; the
readme tells the operator to revoke the app's access in each network.
Deactivation only clears scheduled events.

## 7. Configuration and sensible defaults

| Setting | Default | Notes |
|---|---|---|
| Kill switch | off (posting allowed once a network is on) | Constant `CROSSPOST_KILL_SWITCH` wins |
| Network enabled | off for all four | Needs credentials and a confirmed preview |
| Post types | `post` | Filter |
| Maximum post age at publish | 24 hours | |
| Delay before first processing | 60 seconds | |
| Daily cap per network | 10 per rolling day | Hard ceiling per network: 50 |
| Minimum spacing per network | 10 minutes | |
| Jobs per run | 4, at most 1 per network | |
| Run time budget | 45 seconds | |
| Backoff | 5 min, 15 min, 1 h, 4 h, 12 h | Filter |
| Maximum queue age | 24 hours | |
| Stale `sending` threshold | 15 minutes | |
| Reconciliation window | 24 hours | |
| Template | `{title}` blank line `{url}` | Per network |
| Image mode | `attach` (Bluesky: `link card` when no image) | Per network |
| Require alt text | on | |
| Image failure policy | text only | Per network |
| UTM parameters | off | Per network |
| Mastodon visibility | `public` | `public`, `unlisted`, `private` |
| HTTP timeout | 15 seconds connect plus read | Filter |
| Log retention | 90 days | |
| Job retention | 180 days | |
| Notification email | empty (admin notice only) | |

Constants (all optional): `CROSSPOST_KILL_SWITCH`, and per network the
credential constants listed in the readme, for example
`CROSSPOST_MASTODON_TOKEN`.

## 8. Security and privacy

- Credentials are stored only in non-autoloaded options or constants. They
  never appear in a log row, an admin notice, an error message, the REST
  API, WP-CLI output, an exception message or a debug dump. `status` and
  the settings screen show presence as yes or no only.
- Every message from a network passes a scrubber before it is stored or
  shown: the current token values replaced with `[redacted]`, any
  `token=`, `access_token`, `secret`, `authorization` and `password` value
  replaced, any run of 40 or more token-like characters replaced, and the
  result cut to 255 bytes at a UTF-8 boundary.
- Tokens are sent in an `Authorization` header wherever the API accepts
  it, so they do not end up in proxy or server logs as query strings.
  Where an API only accepts a token as a parameter, it goes in the POST
  body, never in a GET query string, unless the API offers no other way;
  those cases are listed in ARCHITECTURE.md. Two such cases exist, both on
  Threads, whose documented endpoints take the token only as a GET query
  parameter: the long-lived token refresh, and the exchange of the
  short-lived token for a long-lived one (which also carries the app
  secret). Both go over HTTPS to the network's own API host, and the
  plugin never writes a request URL to its log. (Builder's decision,
  accepted at review on 18 November 2026.)
- Write calls do not follow redirects.
- Image fetches by URL are only for the site's own attachments. The plugin
  never fetches arbitrary URLs. The Threads public URL check (68) rejects
  private and loopback addresses so a misconfigured site does not hand the
  network an internal address.
- OAuth flows use PKCE where the network supports it, and a per-user state
  value. Redirect handlers check capability and nonce.
- Admin actions check capability and nonce; every output is escaped.
- The app password rule for Bluesky (74) and the minimum scopes for X,
  Threads and Mastodon are documented in the readme.
- Privacy: the plugin sends the post's title, excerpt, link, image and alt
  text, which are already public. It sends nothing about readers. It
  stores the network account id and display name of the connected account,
  and the user id of the administrator who confirmed a preview. The privacy
  policy text helper describes this.
- No telemetry.

## 9. Performance budget

- Publishing a post: at most a few database writes and one scheduled
  event. No network call. Under 20 ms added on typical hosting.
- Admin screens: no network call on load. Credential checks and previews
  run only when asked.
- A processing run: at most the run time budget (45 seconds by default)
  and at most one image preparation per network.
- Front end: nothing loaded. No script, no style, no query.
- The queue query uses the (`network`, `state`, `next_attempt_at`) index;
  the cap count uses (`network`, `posted_at`).

## 10. Compatibility

- PHP 8.1, 8.2, 8.3. WordPress 6.4 and later.
- `intl` is preferred for grapheme counting (Bluesky) and grapheme-safe
  cutting. Without it the plugin falls back to a PCRE `\X` based count,
  which is correct for every case in section 12; the settings screen notes
  which is in use.
- GD or Imagick, through the WordPress image editor, for resizing. Without
  either, images that need resizing are skipped (text only).
- Multisite: per site settings and tables; network activation creates the
  tables for each site on first use.
- Page caches and object caches: the plugin has no front-end output. The
  lock uses a database row, not the object cache, so it works with
  persistent object caches and with none.
- Hosts that block outbound HTTP: `verify` reports the transport error.
- Self-hosted Bluesky data servers and any Mastodon-compatible instance that
  implements the documented Mastodon API endpoints used here.

## 11. Observability

- The log page and `wp crosspost log` show every attempt: time, network,
  post, event, HTTP status, duration and scrubbed message.
- The post panel shows each network's state and a link to the remote post.
- `wp crosspost status` and the settings screen show, per network: on or
  off, kill switch, credentials present, account display name, cap used and
  remaining, time the next slot frees, cooldown until, oldest due job age,
  last error, and whether the first post review is pending.
- An admin notice appears (once per network per day) when a network needs
  attention, when WP-Cron is late by more than 30 minutes with due jobs, or
  when a table column is missing.
- Actions in 5.5 let a site wire its own monitoring.
- Errors are also written to the PHP error log, scrubbed, when `WP_DEBUG_LOG`
  is on.

## 12. Acceptance tests

Each test names the cases it covers. Network calls are made against a stub
HTTP layer (`pre_http_request`) that records requests and returns scripted
responses.

1. (1, 87) Publish a draft: one job per enabled network. Save the published
   post again: no new job, no request.
2. (2) Schedule a post; no job until the `future` to `publish` transition;
   then one job per network.
3. (3) A page and a custom post type not in the list create no jobs.
4. (4) A password-protected post is skipped.
5. (5) A post with a publish date 3 days old moving to `publish` is skipped
   with `too old`.
6. (6) Opt-out before publish: `opted out`. Opt-out after publish while
   pending: the job becomes `cancelled`.
7. (7) Unpublish and republish a posted post: no second job; the database
   rejects a duplicate insert.
8. (8) Network off at publish: job `skipped` with `network off`; turning it
   on later does not send it.
9. (9) Publish through the REST API and through WP-CLI: jobs created.
10. (10) The veto filter returning a string skips with that reason.
11. (11) Bulk publish 50 posts: 50 times n job rows, zero requests during the
    publish request.
12. (12, 13) Change the title after job creation: the queued text is
    unchanged.
13. (14) Trash a post between creation and its turn: `skipped`, no request.
14. (15) The publish request performs no HTTP call and schedules one event.
15. (16) Two runs started together: the second exits on the lock. An expired
    lock is replaced.
16. (17, 18) One run sends at most one job per network and at most the run
    limit, oldest first, after reconciling.
17. (19, 38) Turn the kill switch on during a run: no further request is
    made; the job is unchanged. With the constant set, the option cannot
    turn it off.
18. (20, 39) Cap of 2 with one `posted` and one `uncertain`: the next job is
    `held` with `cap` and the correct next time.
19. (21) A job left in `sending` for 20 minutes becomes `uncertain`, never
    `pending`.
20. (22) A slow image readiness poll stops at the run budget and leaves the
    remaining jobs for the next run.
21. (23) An empty queue schedules no further event.
22. (24) A success response makes the job `posted` with the remote id and
    URL.
23. (25) Connection refused, 429 with `Retry-After`, and a 500 on an upload
    each return the job to `pending` at the right time.
24. (26) A read timeout on the final create call makes the job `uncertain`
    and makes no second create call in that run.
25. (27) A 422 and a length error make the job `failed` without retry.
26. (28) A 401 holds the job, marks the network, stops further calls to it,
    and raises one notice per day.
27. (29) After five retryable failures the job is `failed` with
    `retries exhausted`.
28. (30) A job held by the cap for over 24 hours becomes `skipped` with
    `stale`.
29. (31) Mastodon: an uncertain job is resent with the same
    `Idempotency-Key`; the stub returns the original status; one status
    exists.
30. (32) Bluesky: an uncertain job finds its record key and becomes
    `posted`; when the key is absent the resend uses the same key.
31. (33) Threads and X: a listing that contains the same text marks the job
    `posted` with that id; a listing without it keeps it `uncertain`; after
    24 hours it becomes `failed` with `never landed`, with no create call.
32. (34) A failing listing call leaves the job `uncertain` and makes no
    create call.
33. (35) Three uncertain X jobs: one listing call.
34. (36) Threads: a container failure is retryable; a publish timeout is
    uncertain with the container id stored.
35. (37) A network cannot be turned on without credentials verified and a
    confirmed preview.
36. (40) A cap of 0 holds every job and sends nothing.
37. (41) Two due jobs on one network inside the spacing window: one goes,
    one waits.
38. (42) After a 429 with a reset time, no call to that network until then.
39. (43) "Post now" and backfill obey the cap.
40. (44) A cap set above the hard ceiling is stored as the ceiling.
41. (45, 46) Default template renders title, blank line, URL. An unknown
    placeholder renders empty and warns in the preview.
42. (47, 48) HTML, shortcodes, entities and control characters are removed
    from placeholder values; a manual excerpt wins.
43. (49) Tags "New Delhi", "2026" and a tag in Devanagari become
    `#NewDelhi` and a Devanagari hashtag; "2026" is dropped.
44. (50) Count tests per network: X weighted count with a link and CJK
    text; Threads 500 characters; Bluesky 300 graphemes with emoji and an
    Indic conjunct; Mastodon with a long link counted as 23 and an instance
    limit of 1,000 read from the instance configuration.
45. (51, 52) Over-long texts are shortened excerpt first, then hashtags,
    then title; the URL is intact and on its own last line; no grapheme is
    split; the cut is at a word boundary when one exists late enough.
46. (53) A template whose literals leave under 40 units fails at creation
    and in preview.
47. (54) A `crosspost_text` filter that makes the text too long: refused at
    send, not sent.
48. (55) UTM parameters appended only when turned on for that network.
49. (56) Bluesky facets for a link after Devanagari and emoji text have the
    correct UTF-8 byte offsets.
50. (57) Bluesky without an image attaches an external card with the
    thumbnail blob and makes no HTTP request to the site itself.
51. (58) Bluesky hashtags get tag facets.
52. (59, 60) Language tag sent where supported; Mastodon visibility sent;
    `direct` cannot be saved.
53. (61, 62, 63) Image choice order; mode `none` sends no image.
54. (64, 65) An oversized JPEG for Bluesky is re-encoded below the byte cap;
    the temporary file is deleted; the attachment is unchanged.
55. (66) An animated GIF goes text only.
56. (67) Missing alt text with "require alt text" on: text only, logged.
    Long alt text is cut at a grapheme boundary to the network limit.
57. (68) Threads with an image URL on a private address: text only. A
    container that stays in progress past the wait ends the image path.
58. (69) Mastodon async media: polled until processed, then the status is
    created with the media id.
59. (70) Image upload failure: text only by default; with `retry`, the job
    returns to `pending`.
60. (71) A re-encoded copy has no EXIF.
61. (72) X refresh: the new access and refresh tokens are stored in one
    write before the next call.
62. (73) Threads token with 5 days left is refreshed; a failed refresh marks
    the network.
63. (74) Bluesky: a value shaped like a main password is rejected; a cached
    session is reused; a new session only after refresh fails.
64. (75) Mastodon: an instance URL without HTTPS is rejected.
65. (76) An OAuth callback with a wrong or expired state is refused.
66. (77, 78) Verify shows only account id and name; a credential constant
    wins and its value is never printed.
67. (79, 84) The post panel and `wp crosspost preview` show the same text,
    count and image; the CLI preview makes no request.
68. (80, 81) The settings preview makes no write call; confirming records
    user, time and hash.
69. (82) Changing the template after confirmation holds new jobs with
    `preview needed`; existing jobs keep their text.
70. (83) The first job on a newly enabled network waits in
    `first post review`; after it is sent, the second flows without review.
71. (85) Reflection over every adapter: no public method other than those in
    5.2; no request in any test uses a delete or edit endpoint or method.
72. (86) Trashing a posted post makes no request.
73. (88) Uninstall drops the tables, options and post meta, clears events,
    and makes no request.
74. (6.4) With a column missing after `dbDelta`, the plugin adds it; if it
    still fails, sending is refused and the column is named.
75. (8) The scrubber removes a token from an error body, from a
    `token=` pair and from a long random string, and cuts to 255 bytes on a
    UTF-8 boundary. No log row in the whole suite contains a test token.
76. (5.7) Every admin action without a nonce or the capability is refused.

## 13. Open questions

For the builder to answer in ARCHITECTURE.md.

1. X endpoints and quota: which current official endpoints for creating a
   post, uploading media and listing the account's own posts are used, and
   how the plugin behaves on an access tier that does not allow reading
   (reconciliation impossible: does an uncertain X job go straight to
   `failed` with `never landed` after the window, or is X posting refused
   on such a tier?).
2. X weighted counting: implement the published counting rules directly, or
   ship the published configuration data as a fixture. Either way, which
   version of the rules is used and how it is kept current.
3. Multiple accounts per network: keep one account per network in 0.1.0
   (proposed), or design the jobs table for several now (the unique key
   would become post, network, account).
4. Bluesky authentication: app password only in 0.1.0 (proposed), or also
   the protocol's OAuth flow, which needs a public client metadata document
   on the site.
5. Threads image path when the site is not publicly reachable (staging,
   basic auth): text only (proposed), or an option to upload the image to a
   public location the operator names.
6. Cap unit: posts per rolling day (proposed) or network-specific units
   where a network meters calls differently from posts.
7. Whether the "first post review" (83) should also apply after a long
   pause (for example 30 days with no post on that network).
8. Block editor panel: a plain `PluginDocumentSettingPanel` built without a
   build step, or a small bundled script with a build step kept out of the
   runtime.

## 14. Lessons from production

Written as rules. Each one cost real time in a build of this feature.

1. Treat a timeout on the final publish call as "maybe live". Never retry
   it blindly. Use an idempotency key where the network has one, a
   self-chosen record key where it allows one, and otherwise match the text
   against the account's recent posts.
2. A run can die between "sent" and "recorded". Write the attempt row before
   the call, and treat a row stuck in `sending` past a short threshold as
   uncertain, not as unsent.
3. Give an uncertain post a fixed window. After it, mark it failed and free
   its cap slot, but do not resend it automatically: a late landing after a
   resend is a duplicate you cannot delete.
4. Count the cap over a rolling day, and count uncertain posts in it. A
   calendar-day cap lets a burst through at midnight, and leaving uncertain
   posts out lets the cap overshoot when the network was slow.
5. The documented cost of an operation and the real one can differ. Keep
   per-network costs and ceilings in one place, easy to change, and log
   what each call actually did.
6. Assert the length and content rules on the final string at the point of
   sending, whoever calls. A filter or a later code path will otherwise send
   text that the template step checked and approved.
7. Keep a trailing link on its own line when shortening. Cut the words
   before it, never the link, and never inside a grapheme.
8. If the fixed parts leave too little room, refuse the post rather than
   send a stub.
9. Never log a raw API error. Scrub tokens and token-shaped strings, then
   cut to a fixed size on a character boundary. Credentials go to the
   adapters only, never to a log, an option shown on screen, or a feed.
10. Report credentials as present or absent. Never show any part of the
    value, not even the last four characters.
11. Do not follow redirects on write calls.
12. Image containers that the network builds from a URL are asynchronous.
    Poll their status with a bounded wait, treat "error" and "expired" as
    final, and let the run move on when the wait runs out.
13. A network that takes images by URL needs a public HTTPS URL. Check for
    that before the call; a private or local URL fails slowly and opaquely
    on the network side.
14. Some features in the official APIs are thinner than in the apps (link
    stickers, link previews, rich text). Design for what the API offers and
    record what it does not; do not imitate an app feature by other means.
15. When posting is off, do nothing: read no credential file, schedule no
    event (clear any left by an earlier version), make no HTTP call, create
    no table rows. A dormant module must be cheap and silent.
16. A dry run or preview must need no credentials and write nothing, so it
    can be run safely on any site at any time.
17. `dbDelta` has silently skipped column additions. After every schema
    change, list the real columns and add any missing one directly; refuse
    to run if one is still missing.
18. A lock in the options table taken with an atomic insert, with an expiry,
    is enough to keep two cron runs from posting the same job. Replace an
    expired lock; never wait on one.
19. Re-check that the post is still eligible right before sending. A post
    held, unpublished or opted out after its job was created must never go
    out.
20. Post at most one item per network per run, spaced. Spreading posts out
    is kinder to the network's spam checks and to the audience, and it
    limits the damage of a bad template to one post.
21. Option values arrive with the wrong type more often than expected
    (strings for numbers, empty arrays). Read every setting with its
    default's type and fall back to the default when the stored value does
    not fit.
