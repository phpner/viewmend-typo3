..  include:: /Includes.rst.txt

..  _dashboard:

Monitoring dashboard
====================

Open :guilabel:`ViewMend > Site Tracker > Overview` to inspect the connected
project. Project-wide issue counts and findings appear alongside the health,
trends and resources of the selected page and device.

Use finding search and severity filters together. Identical findings are
grouped while the counts retain every occurrence. Performance history includes
up to 30 retained checks. Use the slider or pointer to inspect a check, or
expand the equivalent data table. Missing measurements remain gaps.

Changed resources show before/after sizes. Transfer categories load stored
requests on demand with URL search. Truncated inventories show how many
requests were retained; totals can include unattributed bytes.

Responses are cached for five minutes. Refresh data reads current results and
does not schedule a new check. Credentials remain on the server, redirects
are disabled, and resource URLs returned by the API are never fetched as
network destinations. Current authentication failures invalidate cached
Overview results. Local event delivery remains independent of Dashboard reads.
