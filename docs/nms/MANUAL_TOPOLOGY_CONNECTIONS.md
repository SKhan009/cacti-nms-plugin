# Manual topology connections — NMS 1.10.79

Open **Topology → Edit topology**. Choose Device A and Device B, including their cached interfaces where available. Select Ethernet, Fiber, Wireless or Logical, optionally enter a label and configured capacity in Mbps, then save. Select the device-only option when the interface is unknown. A capacity of 0 means unknown.

Click a manual line in the map to see its details and the **Edit / delete connection** link. The editor provides a device/type/label filter and 20 records per page. Deleting requires expanding the Delete control first.

Under **Topology → Device appearance → Connection types**, choose each manual connection type's colour, solid/dashed/dotted line, and endpoint circle/square/arrow/none. The same settings are accessible from Edit topology.

Manual links are operator statements, never discovery evidence. Configured capacity is not measured throughput. Interface readings appear only where a matching cached interface identity and collected IF-MIB interface can be associated. Missing readings display Not monitored. Reindexed or renamed endpoints may require selecting the interface again. Device-level connections do not invent physical port numbers.

Permissions reuse Cacti management and device access checks. POST requests require CSRF tokens. Saving returns a GET page, preventing refresh from repeating an action. Reverse duplicates are rejected. Schema changes affect only plugin-owned tables; upgrade via Cacti Plugin Management before using these pages on another installation.

This update implements the manual connection editor and connection appearance portion of BLR 5.3.2.1.2. It does not introduce the separate discovery wizard or configure switch ports/VLANs.

Files: `topology.php`, `includes/topology/connections.php`, `includes/topology/connections_page.php`, `includes/topology/canvas.php`, `includes/database.php`, `templates/topology/canvas.php`, `templates/topology/catalog.php`, `js/nms-hybrid.js`, `css/nms-topology-config.css`, `INFO`.

Validation: PHP syntax, JavaScript syntax, style validation and duplicate fingerprints, and RHEL database integration tests covering create/edit/delete, canvas provenance and type appearance. Integration changes are rolled back. Chrome verified the map toolbar; the editor's initial missing dependency was corrected, with subsequent server-side rendering checks. Chrome's Cacti session then required login, so final interactive editor verification remains.
