# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-11-18

### Added

- Announce a post's first publication on X, Threads, Bluesky and Mastodon through each network's official API, one adapter per network behind one interface.
- Per-network templates with placeholders, length handling by each network's own counting rule, and shortening that never cuts a link or a grapheme.
- Image handling per network: selection, resizing and re-encoding to a temporary copy, alt text, upload or public URL, Bluesky link cards, text-only fallback.
- Retry queue with backoff, error classification, and reconciliation of uncertain posts (idempotency key, record key, or listing); X posts with an unknown outcome wait for a person.
- Per-network daily cap over a rolling day, spacing, 429 cooldown, per-network switch (off by default) and a global kill switch, also as a constant.
- Preview confirmation before a network can be switched on, and a first post review that applies again after 90 days without a post.
- Scrubbed log of every attempt, admin notices, settings, queue and log screens, block editor panel and classic meta box, REST routes for the panel, WP-CLI commands.
- Schema check that adds columns `dbDelta` skipped and refuses to send while any is missing.
- Uninstall that removes local data only.

[Unreleased]: https://github.com/IndianOpinion/crosspost/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/IndianOpinion/crosspost/releases/tag/v0.1.0
