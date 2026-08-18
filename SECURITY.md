# Security policy

## Supported versions

Security fixes are provided for the latest released `1.x` version on supported TYPO3/PHP branches.

## Reporting a vulnerability

Do not open a public issue for a suspected vulnerability. Email [support@viewmend.com](mailto:support@viewmend.com) with:

- the affected extension and TYPO3 versions;
- a clear reproduction or proof of concept;
- impact and any known exploitation conditions;
- whether the report may be shared with TYPO3 or dependency maintainers.

Do not include real API tokens, production content, editor data or unrelated personal data. ViewMend will acknowledge a complete report, coordinate remediation and credit the reporter when requested and safe.

## Security design

- Network delivery never runs in the content-save path.
- Tokens come from the environment or authenticated encryption tied to the TYPO3 system key.
- Tokens and common credential formats are redacted from logs and Activity.
- Backend mutation routes are POST-only and the module is restricted to TYPO3 system maintainers.
- Delivery claims use leases and compare-and-swap updates; stable event IDs make retries duplicate-safe.
- No frontend endpoint, cookie, request collector or content-body export is registered.
