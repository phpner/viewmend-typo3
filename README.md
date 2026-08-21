# ViewMend Site Tracker for TYPO3

[ViewMend](https://viewmend.com/) is a website quality and operations platform. Site Tracker monitors important public pages after content updates and deployments so teams can identify unexpected SEO, performance, content and technical changes.

ViewMend Site Tracker for TYPO3 is the official extension for [TYPO3 website change monitoring](https://viewmend.com/site-tracker). It records published content changes and deployment markers, stores each event locally, and delivers it asynchronously through the published `viewmend/sdk` contract. See the [integration guides](https://viewmend.com/guides/integrations) for the wider ViewMend ecosystem.

## Compatibility

| Component | Supported and verified |
|---|---|
| TYPO3 | 13.4 LTS and 14.3 |
| PHP | 8.3, 8.4 and 8.5 |
| ViewMend TYPO3 Core | `viewmend/typo3-core` 1.x |
| ViewMend SDK | `viewmend/sdk` 1.x |
| Database | MariaDB is the end-to-end and CI target; SQLite is used for focused repository tests |

TYPO3 12.4 is not supported because free community maintenance ended on 30 April 2026. PostgreSQL is not currently claimed in the extension test matrix.

## Quick start

```bash
composer require viewmend/typo3-site-tracker:^1.0
vendor/bin/typo3 extension:setup
```

Composer installs ViewMend TYPO3 Core automatically. Then open **ViewMend > Site Tracker**, select **Connection**, and save the Integration ID and API token. A single TYPO3 site is selected automatically; installations with multiple sites must choose which site to monitor. The environment defaults to Production and is available under **Advanced settings**. Select **Save + queue test event** to create a real local custom event; the form does not wait for the network.

Run the worker every minute:

```cron
* * * * * cd /path/to/typo3 && vendor/bin/typo3 viewmend:process --no-interaction
```

## What is tracked

The extension records a transition only after TYPO3 has a resolvable canonical public URL and an anonymously public live record for the configured site.

| Event | Behaviour |
|---|---|
| New public page or content element | Queued after the record becomes public |
| Public content update | Queued when the locally computed public-presentation fingerprint changes |
| Unpublish | Queued when a previously public record becomes hidden, expired or access-restricted |
| Delete | Queued when a previously public live record is removed |
| Workspace publication | Reconciled after TYPO3 confirms publication; ambiguous first observations become a baseline instead of a guessed event |
| Scheduled start/end | Reconciled by `viewmend:process` after the configured timestamp becomes effective |
| Full cache flush | Optional rule, off by default; partial cache clears are ignored |
| Deployment | Explicit CLI marker with a caller-supplied stable event ID |

Drafts, workspace versions, intermediate DataHandler calls, future records, expired records, frontend-group-restricted records and URLs outside the selected TYPO3 site binding are not sent. Translations keep their language-specific canonical URL and local observation. One extension configuration binds one TYPO3 site identifier and base URL, so multisite events cannot be mixed silently.

Automatic site-configuration and extension install/update/remove markers are intentionally not claimed. The supported public TYPO3 lifecycle APIs do not provide a single post-completion signal with enough information to distinguish all of those operations reliably across both supported branches.

## Delivery lifecycle

Events are inserted into the local database before any network work. The worker uses compare-and-swap leases so concurrent cron, Scheduler and CLI workers cannot claim the same delivery at the same time.

The Activity section exposes these states:

- `queued`
- `in_progress`
- `accepted`
- `duplicate`
- `retry_scheduled`
- `permanently_failed`
- `cancelled`

Transient network, rate-limit and server errors use bounded exponential backoff with jitter and honour `Retry-After`. Permanent API errors stop automatically. Expired worker leases are recovered safely. The original event ID and payload are retained across every attempt, so a delivery accepted before a worker crash is recognised as a duplicate by ViewMend rather than creating a second logical event.

Delivered history is retained for 30 days by default. Cleanup removes only `accepted` and `duplicate` deliveries older than the configured period; active, failed and cancelled records are preserved.

## Connection and secrets

The production API base is fixed by the official SDK:

```text
https://viewmend.com/api/v1
```

The recommended secret source is the process environment:

```dotenv
VIEWMEND_API_TOKEN=replace-with-the-real-token
```

Optional environment overrides are available for immutable deployments:

```dotenv
VIEWMEND_INTEGRATION_ID=integration-id
VIEWMEND_SITE_IDENTIFIER=main
VIEWMEND_SITE_URL=https://www.example.com/
VIEWMEND_ENVIRONMENT=production
```

Environment values override backend values. If a token is entered in the backend, it is encrypted using AES-256-GCM with key material derived from TYPO3 `SYS/encryptionKey`. The token is write-only: it is never displayed again, included in public configuration, payloads, Activity, status output or logs.

## Event rules

The Event rules section controls:

- global event capture;
- page and content-element tracking;
- publish, update, unpublish and delete transitions independently;
- allowed numeric page `doktype` values;
- allowed content element `CType` identifiers;
- optional full-cache-flush markers;
- delivered-history retention.

Default public types are page `doktype=1` and content types `text`, `textmedia` and `image`. Add project-specific public types explicitly. A custom CType's database fields may affect the local fingerprint, but their values are never copied into a ViewMend event.

## Deployment monitoring

Use a deployment-system identifier that remains identical when the same deployment command is retried:

```bash
vendor/bin/typo3 viewmend:deployment release-2026-08-18.1 \
  --title="Production deployment" \
  --reference="https://deployments.example.com/releases/2026-08-18.1" \
  --site=main \
  --site-url="https://www.example.com/" \
  --page-url="https://www.example.com/"
```

`--site` and `--site-url` are safety assertions; they cannot override the saved binding. Every `--page-url` must belong to that site base. The command only queues locally and returns standard success, invalid-input or failure exit codes.

## Operations commands

```bash
# Reconcile scheduled visibility and process up to 20 deliveries
vendor/bin/typo3 viewmend:process --limit=20 --reconcile-limit=100

# Readiness and safe delivery counts; no secret values
vendor/bin/typo3 viewmend:status

# Retry a permanently failed delivery
vendor/bin/typo3 viewmend:retry 42

# Retry and immediately run the deliberate network action
vendor/bin/typo3 viewmend:retry 42 --run-now
```

`viewmend:process` is registered as a schedulable Symfony command and can be selected in TYPO3 Scheduler. The System Reports status provider reports connection readiness, queue failures and a waiting queue whose worker has not run recently.

## Privacy and data minimisation

Outbound events contain only fields allowed by the public ViewMend SDK: stable event ID, event type, short operational title, occurrence time, configured public site URL, affected public page URLs, environment, changed-field categories and small operational metadata such as CMS, operation, record type, numeric record/language ID and site identifier.

The extension does **not** send content bodies, page or content titles, editor/user identities, email addresses, cookies, request data, arbitrary TypoScript, site configuration or token values. A SHA-256 fingerprint of the relevant local database record is stored only in TYPO3 to detect later changes; source field values are not placed in the delivery table.

Errors are length-bounded and redacted before they reach delivery history, operational state or logs. Activity shows the safe ViewMend response summary, never raw response bodies.

## Installation details

1. Require the Composer package; its shared ViewMend Dashboard dependency is installed automatically.
2. Run `vendor/bin/typo3 extension:setup` or the database schema analyser so the four `tx_viewmendsitetracker_*` tables exist.
3. Confirm that the TYPO3 site has an absolute public base URL and working routing for every enabled language.
4. Configure Connection, then Event rules.
5. Queue a test event and start the worker.
6. Confirm `accepted` or `duplicate` in Activity.

Only TYPO3 system maintainers can access the backend module. No frontend plugin, middleware, cookie or public endpoint is registered.

## Upgrade

Before upgrading, back up the database and review [CHANGELOG.md](CHANGELOG.md). Update through Composer, run `vendor/bin/typo3 extension:setup`, flush TYPO3 caches, then run `viewmend:status`. Existing queued payloads and stable event IDs are preserved.

## Uninstall

1. Disable capture in Event rules.
2. Run the worker until waiting deliveries are empty, or cancel them deliberately in Activity.
3. Remove the package with Composer.

TYPO3 does not silently delete extension data during Composer removal. The settings, observations, deliveries and attempts remain recoverable until an administrator explicitly removes the four extension tables through the database schema analyser or a reviewed database migration. Back up audit history before deleting it.

## Troubleshooting

### Events remain queued

Run `vendor/bin/typo3 viewmend:status`, then verify that cron or Scheduler runs `viewmend:process` as the same deployment user and with the same environment variables as TYPO3.

### Records do not create events

Confirm the selected site identifier/base URL, allowed `doktype`/`CType`, language routing, anonymous frontend access, scheduling and workspace publication state. A draft or unresolved URL is correctly ignored.

### Token cannot be stored

Configure a strong TYPO3 `SYS/encryptionKey`, or use `VIEWMEND_API_TOKEN`. Replacing the TYPO3 encryption key makes an existing encrypted token unreadable; enter it again after the key rotation.

### A delivery is permanently failed

Open Activity for the safe attempt history. Correct authentication, integration or event validation first, then use Retry. A `duplicate` result is successful and means ViewMend has already accepted that stable event ID.

## Development and verification

```bash
composer validate --strict
vendor/bin/phpcs
vendor/bin/phpstan analyse --no-progress --memory-limit=512M --debug
vendor/bin/phpunit --testsuite unit,integration,functional
docker compose up --build -d
```

The repository also provides a MariaDB CI suite, a reproducible TYPO3 Docker installation and DDEV bootstrap instructions in [Documentation/Development/Index.rst](Documentation/Development/Index.rst).

## Security and support

Report vulnerabilities privately according to [SECURITY.md](SECURITY.md). For product and integration support, email [support@viewmend.com](mailto:support@viewmend.com).

## License

ViewMend Site Tracker for TYPO3 is licensed under `GPL-2.0-or-later`. See [LICENSE](LICENSE).
