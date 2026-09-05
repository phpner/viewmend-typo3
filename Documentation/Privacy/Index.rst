..  include:: /Includes.rst.txt

..  _privacy:

Privacy and security
====================

Outbound data is limited to stable event ID, event type, short operational
title, timestamp, configured public site URL, affected public page URLs,
environment, changed-field categories and small operational metadata.

The extension never sends content bodies or titles, editors/users, email
addresses, cookies, request payloads, arbitrary TypoScript/site configuration
or secrets. Local record field values are reduced to a SHA-256 fingerprint for
change detection and are not copied into delivery payloads.

Errors are redacted and length-bounded before storage or logging. Activity
stores only a safe parsed response summary, not the raw API response.

See ``SECURITY.md`` for private vulnerability reporting to
``support@viewmend.com``.

The Overview also reads monitoring data from ViewMend using the configured
integration and token. Dashboard responses are redacted and cached for five
minutes, with cache entries isolated by integration and credentials. Stored
resource requests are loaded only when a maintainer opens a category; those
responses are marked private and are not cached by the browser. Tokens and
upstream exception bodies are never passed to JavaScript. Refreshing the
Overview does not submit an event or start a check.
