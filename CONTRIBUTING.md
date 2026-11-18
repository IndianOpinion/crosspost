# Contributing

Thank you for helping. Short version: open an issue first for anything bigger than a small fix, keep pull requests focused, and keep `composer check` green.

## Setup

```sh
git clone https://github.com/IndianOpinion/crosspost.git
cd crosspost
composer install
```

PHP 8.1 or later, with `mbstring`. `intl` and `gd` are used by the tests when present.

## Tests

```sh
composer test      # PHPUnit; no WordPress install, no network
composer analyse   # PHPStan level 6 with WordPress stubs
composer lint      # PHPCS, PSR-12
composer check     # all three
```

The tests run against a small WordPress stub layer in `tests/stubs/`. Every HTTP call is answered by a scripted response through `pre_http_request`; a request without one fails the test. No test may reach a network.

The database store has integration tests in `tests/Integration/` that run against a real MySQL when these are set, and are skipped otherwise:

```sh
CROSSPOST_TEST_DB_HOST=127.0.0.1 CROSSPOST_TEST_DB_USER=root \
CROSSPOST_TEST_DB_PASSWORD=secret CROSSPOST_TEST_DB_NAME=crosspost_test \
vendor/bin/phpunit tests/Integration
```

Use a throwaway database: the tests drop and create tables.

## Style

- PSR-12, `declare(strict_types=1);` in every PHP file, PHP 8.1 syntax only.
- Typed exceptions, never `@`, never silent failure.
- Escape every output in admin screens; check capability and nonce on every action.
- Plain words, short sentences, British spelling in docs and messages.

## Pull requests

- One change per pull request, with a test that fails without it.
- Update `CHANGELOG.md` under "Unreleased".
- No secrets, account handles or real tokens in code, tests or fixtures.
- Never add a code path that edits, deletes or hides a remote post. The adapter interface has no such method on purpose.
- A sign-off line is not required.
