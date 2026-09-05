..  include:: /Includes.rst.txt

..  _release:

Release process
===============

Prepare the package
-------------------

#. Update ``composer.json``, ``ext_emconf.php``, ``Documentation/guides.xml`` and
   ``CHANGELOG.md`` to the same release version.
#. Run the checks in :ref:`development`. CI covers TYPO3 13.4 and 14.3 on PHP
   8.3, 8.4 and 8.5; the database and Docker jobs cover event delivery.
#. Check the backend in light and dark themes and review the installation,
   upgrade and privacy documentation.
#. Commit the release changes and build the archive from a clean checkout:

   ..  code-block:: bash

       python3 Scripts/package-release.py

The archive and its SHA-256 checksum are written to ``build/release``.
Packaging verifies the version metadata, file contents and Git export rules.
The archive contains extension code, assets and documentation. Tests, local
containers, dependencies and hidden development files are excluded.

Publish the release
-------------------

#. Push the release commit and confirm that all CI jobs pass for that commit.
#. Create and push a signed tag matching the version in the package metadata.
#. Publish the release notes, ZIP and checksum on GitHub.
#. Confirm that Packagist has indexed the tag, then publish the package under
   the ``viewmend_site_tracker`` key in TER.

The GitHub source archive and the Composer archive should contain the same
extension files. Check the published installation command after the tag is
available on Packagist.
