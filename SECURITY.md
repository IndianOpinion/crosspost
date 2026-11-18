# Security policy

## Reporting a vulnerability

Email hello@indianopinion.org with a description, the affected version and steps to reproduce. Please do not open a public issue for a vulnerability.

We acknowledge reports within 3 working days and aim to ship a fix or a mitigation within 30 days. We will credit you in the release notes unless you ask us not to.

## Supported versions

Only the latest minor release receives security fixes.

## What counts

Of particular interest for this plugin:

- any path that leaks a token or app password (log rows, notices, REST, WP-CLI output, error messages);
- any admin action or REST route reachable without the right capability and nonce;
- any way to make the plugin post twice, post when switched off, or call an edit or delete endpoint;
- server-side request forgery through the image URL handling.
