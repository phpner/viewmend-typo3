..  include:: /Includes.rst.txt

..  _development:

Development and verification
============================

Focused checks
--------------

..  code-block:: bash

    composer validate --strict
    vendor/bin/phpcs
    vendor/bin/phpstan analyse --no-progress --memory-limit=512M --debug
    vendor/bin/phpunit --testsuite unit,integration,functional

Docker Compose
--------------

The root ``compose.yaml`` builds a Composer-based TYPO3 14.3 installation and
starts MariaDB. It installs this checkout as a path repository rather than
copying application code into the extension.

..  code-block:: bash

    docker compose up --build -d
    docker compose exec -T web vendor/bin/typo3 viewmend:status
    docker compose exec -T web vendor/bin/typo3 viewmend-e2e:scenario

The local backend is available at ``http://127.0.0.1:8088/typo3/``. Credentials
and test-only environment values are defined only in ``compose.yaml`` and must
never be reused in a public or production deployment.

DDEV
----

Use ``Scripts/setup-ddev.sh`` in this repository. It creates the disposable
``.ddev/site`` Composer project, registers this checkout as a path repository,
starts DDEV and installs TYPO3. The generated site is ignored by Git.

MariaDB suite
-------------

CI provides an isolated MariaDB database and runs the ``mariadb`` PHPUnit
suite. The test is skipped locally unless all ``VIEWMEND_TEST_DB_*`` variables
are provided.
