..  include:: /Includes.rst.txt

..  _events:

Confirmed event semantics
=========================

Public eligibility
------------------

A record is eligible only when it is live, not hidden/deleted, not a workspace
version, active according to ``starttime``/``endtime``, anonymously accessible,
of an allowed public type, inside the bound site's public page tree and
resolvable to an absolute HTTP(S) URL.

Lifecycle mapping
-----------------

* absent/non-public to public: publish;
* public with a changed local public-presentation fingerprint: update;
* public to non-public: unpublish;
* previously public and no longer present: delete.

DataHandler candidates are reconciled only after all operations complete.
Workspace records are additionally reconciled after
``AfterRecordPublishedEvent``. If that post-publish signal has no reliable
before-state, the extension stores a baseline rather than guessing an event.

Scheduling, translations and multisite
--------------------------------------

The worker revisits observed future start/end transitions. Language IDs and
language-specific router URLs remain separate. The selected site identifier
and base URL must both match; deliveries from a different site binding are
permanently stopped instead of being sent under the wrong integration.

Intentionally unsupported automatic events
-------------------------------------------

Automatic site-configuration and extension install/update/remove events are
not claimed. The supported public TYPO3 APIs do not offer one reliable,
post-completion, cross-version signal that distinguishes all required
operations. Deployment pipelines should use the explicit command instead.
