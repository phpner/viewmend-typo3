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
