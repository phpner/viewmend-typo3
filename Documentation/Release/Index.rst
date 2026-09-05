..  include:: /Includes.rst.txt

..  _release:

Release process
===============

No release action is performed automatically by this repository.

Before a maintainer publishes:

#. verify both TYPO3 dependency branches and all supported PHP versions;
#. run the MariaDB and Docker scenarios from a clean checkout;
#. review security, privacy, dependency and rendered backend UX results;
#. ensure ``composer.json``, ``ext_emconf.php``, changelog and documentation use
   the same version and compatibility constraints;
#. reserve/confirm the ``viewmend_site_tracker`` TER key;
#. create the signed source tag only after approval;
#. publish to GitHub, Packagist and TER as separate controlled external steps.

The public package must not claim PostgreSQL or a TYPO3 branch until that
combination is present in the automated test matrix.

Create a local installable archive with:

..  code-block:: bash

    python3 Scripts/package-release.py

The archive and its SHA-256 checksum are written to ``build/release``.
Packaging checks the version metadata and the allowed runtime/documentation
files. Tests, local containers, dependencies and hidden development files are
excluded. Building an archive does not create a tag or publish a release.
