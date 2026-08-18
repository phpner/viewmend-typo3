..  include:: /Includes.rst.txt

..  _configuration:

Configuration
=============

Connection
----------

The module binds one ViewMend integration to one TYPO3 site identifier and its
canonical configured base URL. The API base is fixed to
``https://viewmend.com/api/v1`` by ``viewmend/sdk``.

The preferred token source is the environment:

..  code-block:: dotenv

    VIEWMEND_API_TOKEN=replace-with-the-real-token

Immutable deployments can also provide ``VIEWMEND_INTEGRATION_ID``,
``VIEWMEND_SITE_IDENTIFIER``, ``VIEWMEND_SITE_URL`` and
``VIEWMEND_ENVIRONMENT``. Environment values take precedence over the backend.

A token entered in the backend is encrypted with AES-256-GCM using key
material derived from TYPO3 ``SYS/encryptionKey``. It is never displayed again.

Event rules
-----------

Rules independently control page/content tracking, publish, update, unpublish,
delete and full-cache-flush events. Allowed ``doktype`` and ``CType`` lists
limit public record types. Retention is 1 to 365 days and applies only to
successfully delivered history.

The cache-flush rule is disabled by default. It recognises only a confirmed
flush containing TYPO3's complete registered cache-group set, never a partial
cache operation.

Access
------

The backend module is restricted to TYPO3 system maintainers. Mutation routes
are POST-only and use TYPO3 backend route protection. No frontend route or
plugin is installed.
