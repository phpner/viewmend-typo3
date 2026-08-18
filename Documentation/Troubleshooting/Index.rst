..  include:: /Includes.rst.txt

..  _troubleshooting:

Troubleshooting
===============

Queue does not move
-------------------

Run ``viewmend:status`` and confirm cron/Scheduler uses the deployment user and
the same environment variables as the web process. A waiting queue with no
recent worker run appears as a System Reports warning.

No event for an edit
--------------------

Check site binding, routing, allowed ``doktype``/``CType``, language, workspace,
scheduling, hidden/deleted state and anonymous frontend groups. Saving a draft
or an edit whose public fingerprint does not change correctly produces no event.

Permanent failure
-----------------

Open the delivery in Activity. Fix authentication, integration or validation
first, then retry. ``duplicate`` is a successful terminal state.

Encrypted token cannot be read
------------------------------

The TYPO3 system encryption key changed or is missing. Enter the token again or
provide ``VIEWMEND_API_TOKEN``. The old token cannot and should not be displayed.
