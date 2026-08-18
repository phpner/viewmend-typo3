..  include:: /Includes.rst.txt

..  _installation:

Installation
============

Requirements
------------

* TYPO3 13.4 LTS or TYPO3 14.3
* PHP 8.3, 8.4 or 8.5
* Composer 2
* a TYPO3-supported database; MariaDB is the verified end-to-end target
* an absolute public base URL in TYPO3 site configuration

TYPO3 12.4 is not supported because its free community maintenance ended on
30 April 2026.

Composer installation
---------------------

..  code-block:: bash

    composer require viewmend/typo3-site-tracker:^1.0
    vendor/bin/typo3 extension:setup

``extension:setup`` creates or updates the internal settings, delivery,
attempt and observation tables using ``ext_tables.sql``.

Initial setup
-------------

Open :guilabel:`Admin Tools > ViewMend Site Tracker` as a system maintainer.
Configure the Connection first, then Event rules. Queue a test event and run
the worker once. A successful test ends in ``accepted`` or ``duplicate``.

Upgrade
-------

Back up the database, review ``CHANGELOG.md``, update with Composer, run
``vendor/bin/typo3 extension:setup`` and flush TYPO3 caches. Existing queue
rows and stable event IDs are not regenerated.

Uninstall
---------

Disable capture and drain or deliberately cancel waiting events before
removing the Composer package. TYPO3 does not automatically destroy extension
data during Composer removal. Remove the four ``tx_viewmendsitetracker_*``
tables only through a reviewed schema migration or database analyser action,
after backing up any required audit history.
