..  include:: /Includes.rst.txt

..  _operations:

Queue and operations
====================

Worker
------

Run once per minute through cron or TYPO3 Scheduler:

..  code-block:: bash

    vendor/bin/typo3 viewmend:process --limit=20 --reconcile-limit=100

The worker first recovers expired leases, then claims due rows atomically.
Transient failures use bounded backoff and jitter, and honour ``Retry-After``.
The same event ID and payload are reused on every attempt.

Deployment marker
-----------------

..  code-block:: bash

    vendor/bin/typo3 viewmend:deployment release-2026-08-18.1 \
      --title="Production deployment" \
      --reference="https://deployments.example.com/releases/2026-08-18.1" \
      --site=main \
      --site-url="https://www.example.com/"

The positional event ID is required and must be stable across deployment
retries. ``--site`` and ``--site-url`` only assert the saved binding. Every
``--page-url`` must remain inside that configured public site base.

Diagnostics and recovery
------------------------

..  code-block:: bash

    vendor/bin/typo3 viewmend:status
    vendor/bin/typo3 viewmend:retry 42
    vendor/bin/typo3 viewmend:retry 42 --run-now

Activity permits Run now for waiting rows, Retry for permanent failures and
Cancel for states in which cancellation is safe. Accepted, duplicate and
in-progress deliveries cannot be cancelled manually.

System Reports exposes connection, queue and worker freshness status without
revealing secrets.
