# Contributing

Thank you for improving ViewMend Site Tracker for TYPO3.

## Development requirements

- PHP 8.3–8.5
- Composer 2
- TYPO3 13.4 or 14.3
- Docker with Compose for the end-to-end scenario

Install dependencies with `composer install`. Keep changes compatible with both TYPO3 branches and use public TYPO3 and `viewmend/sdk` APIs.

## Quality checks

Run the focused checks before proposing a change:

```bash
composer validate --strict
vendor/bin/phpcs
vendor/bin/phpstan analyse --no-progress --memory-limit=512M --debug
vendor/bin/phpunit --testsuite unit,integration,functional
```

For persistence or lifecycle changes, also run the MariaDB/Docker scenario documented in `Documentation/Development/Index.rst`.

## Engineering rules

- Preserve asynchronous, local-first delivery.
- Never add network I/O to DataHandler save hooks.
- Never include content, user/editor data, request data or secrets in outbound events.
- Treat workspaces, translations, scheduled visibility and multisite binding explicitly.
- Add a regression test for state-machine, retry, redaction or lifecycle changes.
- Send events through the published ViewMend SDK. Dashboard reads use TYPO3's RequestFactory through DashboardHttpTransport with a fixed API origin, bounded responses and redirects disabled.
- Keep public compatibility claims limited to the tested matrix.

Security reports belong at `support@viewmend.com`, not in public issues.
