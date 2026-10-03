# ICCT NMS Inventory

`icct_nms` is an independent Cacti 1.2.31 plugin implementing the shared responsive Inventory designs. It works with the NMS plugin disabled or uninstalled. Runtime requests and collectors use only Cacti core tables and this plugin's `plugin_icct_nms_*` tables. There is no other-plugin database lookup or fallback.

## Installation

1. Copy this directory to `cacti/plugins/icct_nms`.
2. Install or upgrade and enable **ICCT NMS Inventory** through native Plugin Management.
3. Grant the **View ICCT NMS Inventory** realm. Cacti's existing device-management permission and device ACLs remain authoritative.
4. Open **ICCT NMS → Inventory**. On the configured VM: `http://127.0.0.1:8080/cacti/plugins/icct_nms/inventory.php`.

Lifecycle setup creates the 27 owned tables from `database/schema.sql`. Normal requests never create, repair or populate tables. Uninstall retains ICCT settings and all Cacti devices for a later reinstall. New installations contain no sample equipment. Native numeric SNMP polling, devices, sites, collectors, templates, graphs and RRD data stay under Cacti's ownership.

## Files

- `setup.php`, `INFO`: native lifecycle, access realm and menu/collector hooks.
- `inventory.php`, `device.php`, `protocol.php`, `diagnostics.php`, `export.php`: authenticated controllers.
- `includes/bootstrap.php`, `backend.php`, `schema.php`: owned service loader and schema boundary.
- `includes/services/`: relevant validation, device, identity, rack, discovery, diagnostic and serial services reused from NMS source, with separate function namespaces, tables and locks.
- `includes/device_service.php`, `protocol_service.php`, `inventory.php`, `forms.php`: controller-facing save, read and form helpers.
- `includes/polling.php`, `diagnostic_listener.php`, `diagnostic_worker.php`, `collector/serial_transport.py`: ICCT collection using Cacti's assigned collector.
- `templates/`: Inventory, Basic Information, Protocol Config and Diagnostics markup.
- `ssh/`: independent encrypted broker source and pinned offline dependencies; service setup is administrator-managed.
- `assets/css/inventory.css`, `assets/js/inventory.js`: separate responsive presentation and UI behavior.

Source uses four-space indentation, LF endings and a final newline. Controllers handle requests, services validate and persist, and templates render. Comments explain credential preservation, device permissions, preset isolation and collector boundaries. Dependencies on Cacti core APIs are explicit.

## Data and saves

Inventory lists permitted, nondeleted Cacti hosts, including authorized disabled devices. Device status, uptime, polling interval and availability use real saved evidence. ICCT tables own segments, classification, manual identities, short names, rack placement, discovery presets, snapshots and protocol assignments. Manual identity and observed SNMP identity remain distinct. The assigned collector automatically reads identity through native Cacti SNMP credentials: IP-address/interface MAC, LLDP chassis identity (and an explicitly reported chassis MAC), and a unique ENTITY-MIB chassis serial, plus template-defined identity OIDs. Empty fields populate from current observations; manual values take precedence. Endpoint, collector, template or credential changes invalidate the identity snapshot. Auto-observed values are not silently saved as manual overrides. New devices must be saved and SNMP configured before polling; unsupported hardware fields stay blank. These reads do not use an arbitrary IP or another plugin’s collector. Empty or stale evidence is never replaced with imported samples.

Short Name is generated from Device Name (word initials, or the first eight characters of a single word) when empty, and remains editable. Manual values are retained; generated values persist on Save. The Basic Information screen does not display the saved-observations table.

Device saves call Cacti's `api_device_save` through the reused validation service. Unshown core settings and blank V3 credential replacements remain saved. Native form choices, sites, pollers and templates come from Cacti. Rack capacity and serial assignment revisions are checked on the server. Protocol saves create private device presets, preserving shared ones. The poller hook collects text SNMP inventory and discovery into ICCT tables; an ICCT listener processes diagnostics and configured serial monitoring. It does not create private numeric pollers or replace core graph ownership.

CDP and LLDP have separate panels and checkbox entries in Add Protocol. Timing uses the existing shared device discovery schedule. Saving either panel enables backend LLDP, CDP, IP-neighbour and FDB detection without exposing method switches, collection switches or preset details. Removing one protocol preserves the other discovery methods. Edit mode initially shows only assigned CDP/LLDP, SSH or serial sections and enabled core SNMP. Add Protocol reveals an unsaved section explicitly. Accordions include visible chevrons and removal controls; confirmed, CSRF-protected removal detaches only this device’s assignment (SNMP removal disables core SNMP polling). Shared presets, connections and encrypted secrets remain. SNMP uses version/security radios and separate V1/V2 and V3 layouts, with confirmation of replacement passphrases. Bulk-walk maximum repetitions is omitted because the installed core host API exposes no separately persisted host setting for it.

The protocol/diagnostics navigation stays at the bottom-right while fields scroll above it. Search, combined filters, numeric name sorting, paging, tree view and CSV export use the same saved inventory. CSV formula prefixes are neutralized.

## Inventory actions

The eye opens read-only details; the pencil opens edit mode. The three-dot menu contains Ping, ARP, Trace Route, Cross Launch URL, Alarm Suppression, Re-Index Device, Modify Device, Clone Device, Disable/Enable Device and Delete Device. Ping, Trace Route and ARP start immediately on the assigned local collector and show real output in an Inventory popup, without creating queue jobs or navigating to a results page. They use saved diagnostic parameters, bounded execution, native permissions and CSRF validation. The existing collector accepts authenticated loopback requests; no system permissions are changed. Other diagnostic methods retain their saved-profile queue. Re-index calls the native Cacti service. Disable and Delete open Cacti's native confirmation page rather than executing on menu opening. Clone prepares an unsaved Add Device form with visible, non-secret settings and requires a new hostname/identity; it does not copy credentials, graphs, observations or protocol assignments.

A saved HTTP(S) Cross Launch URL opens directly. Without one, the item is disabled. Alarm Suppression is disabled because no matching backend service exists. The native Cacti sidebar Inventory entry opens the standalone layout in its own tab using Cacti’s external-link support, avoiding the core AJAX fragment loader. The header menu opens on hover or keyboard focus without a click toggle. Row menus stay within the viewport and close on outside click, Escape or table scroll.

## Limits

Only the supplied Inventory screens are included. Port Config/FCAPS wizard steps remain disabled; the graph row action opens native Cacti graphs. Syslog, Netflow, NTP and TACACS+ report unavailable configuration instead of saving inert settings. The BEL wordmark is a text/CSS approximation.

SSH retains HTTPS, host-key verification and encrypted broker requirements. An administrator must configure this plugin's own `ssh.config.local.json` and compatible broker before credential changes or SSH monitoring can run. Restored credential references do not recover missing encrypted secrets. The HTTP VM was not used for SSH credential enrollment. No other-plugin SSH database or broker is used as a fallback. Serial monitoring requires the actual saved equipment profile and provisioned collector adapter/lock directory; a connection alone is not fabricated monitoring evidence.

## Recovery on the existing VM

NMS had been uninstalled and its inventory tables removed. ICCT-owned tables were created, and relevant saved records were recovered from `/var/backups/nms-upgrade-acceptance-20260927/cacti.sql`. More recent manual identities and the saved serial connection/assignment were recovered from the HTML export made before removal. These were one-time administrative recovery operations. Requests never consult these backups or another plugin's database. Historical active jobs and authentication sessions were not replayed. Settings not present in either recovery source cannot be reconstructed.

Core host configuration and graph/data ownership were hashed before and after recovery and QA cleanup. All 13 original devices remain. A temporary disabled device verified real native create/SNMP saves and owned metadata/discovery/diagnostic persistence with NMS uninstalled, then was removed.

## Verification

From the repository root:

```sh
node --check plugins/icct_nms/assets/js/inventory.js
find plugins/icct_nms -name '*.php' -exec php -l {} \;
```

The development verification suite is maintained locally and is not included in this plugin-only repository.

Checks cover saved credential/core-field preservation, ACL enforcement, native validation, private preset isolation, owned schema/lifecycle registration, absence of legacy runtime dependencies and table behavior. VM PHP 8.0 checks and live independent save flows passed. A real ICCT-owned Ping job completed against the local Cacti host with four packets received and zero loss while NMS was uninstalled. Desktop/mobile navigation and the inventory menu were checked in the browser. Native Delete/Disable final confirmations were not submitted against existing devices. SSH enrollment and serial equipment I/O were not tested.

Serial Communication is grouped into physical interface, connection, communication and protocol sections. Direct saved ports expose RS-232/RS-485 and native termios parameters; RTU gateways show their existing address and port, with physical controls visible but disabled because the gateway manages them. Saves validate the current connection revision, collector ownership, bus address, timeout/retries and equipment profile. A shared connection cannot be changed through one device. Polling intervals are retained in device metadata and updated on an equipment assignment when one exists. Modbus ASCII is available on direct serial connections, with 7/8 data bits, hexadecimal ASCII frames, LRC validation and CR/LF termination. RTU-only gateways reject ASCII. ASCII reuses the existing Modbus register equipment map and address validation. Vendor Specific remains disabled. The UI lists actual saved collector endpoints and does not fabricate serial ports. Physical hardware I/O has not been tested.

Plugin confirmations, success notices, server errors and native required-field validation use a shared accessible modal. Cancel, close and Escape dismiss confirmations without submitting. The destructive action is submitted only after selecting Remove; the existing CSRF and backend checks remain authoritative. Static preview submits use the same modal without writing to the VM.

Each configured protocol has a per-device enable checkbox before its removal control. Disabling greys out and disables its parameters without deleting assignments, presets or credentials. Owned metadata stores the state. CDP/LLDP discovery and serial/SSH execution check that state; SNMP pauses through the native host version and restores its previous version on enable. SSH retains its previous monitoring flag. Disabled configuration remains visible and can be re-enabled. Live VM checks verified LLDP filtering and SNMP pause/resume with the original configuration hash restored.

## Graphs step

Step 4 shows native `host_graph` associations as a template/status table, without chart previews. Graph status and Edit links use Cacti-authorized graph IDs. The searchable Add Graph Template dropdown uses the same eligibility query as Cacti's device editor. Adds run the native automation and plugin hooks; removal uses `api_device_gt_remove` and keeps existing graphs/data. Actions require management permission, device access and CSRF validation. Diagnostics Next opens Graphs; Graphs Previous returns to Diagnostics.

Graph associations expand inline as accordions. Only the time X axis, saved Y axis label, and saved graph minimum/maximum limits appear as read-only fields. The ordered graph items follow the native Graph Template Items columns, including source, type, CF, GPrint, CDEF, VDEF, alpha and color. Allowed graph instances are filtered through Cacti graph authorization before details are loaded; associations without a graph show template defaults. No graph images are rendered.

Step 5 shows native data query associations and cached item/row counts. Its searchable Add Data Query selector is above the list. Re-index methods and add/change/remove/reload use the native Cacti query APIs with management permission, device access and CSRF validation. Verbose output appears inline in the Data Query section. Removal retains existing graphs and data sources. Graphs Next opens Data Query; Data Query Previous returns to Graphs.

Serial saves keep connection preset IDs separate from equipment reading profile IDs. Existing connections can be reconfigured without an equipment profile; their polling interval is retained in device metadata. An existing equipment assignment retains its profile and receives the updated interval. Saving connection settings keeps native collector/address checks and revision validation.

## New device selection

Basic Information shows device fields only. Selecting a device template does not show graph or data-query lists in step 1. Cacti applies those associations when saving the new device; steps 4 and 5 show and configure them.

Serial connection selection appears in step 2 after adding Serial Communication. Selecting a saved TCP gateway automatically fills its gateway hostname/IP address and port. Physical controls and the gateway explanation follow the selected connection transport.

Serial dropdowns start at None without a selected connection. Dependent fields and Save remain disabled until a saved connection is selected. Direct physical settings require an RS-232 or RS-485 selection; RS-485 excludes RTS/CTS. TCP gateway physical fields show None and remain disabled, while the stored connection parameters are retained. Edit forms load applicable saved settings from the selected connection.

## Unsaved device wizard changes

Add and Edit Device use one in-page draft across Basic Information, Protocol Config, Diagnostics, Graphs, and Data Query. Next, Previous, and step links do not submit settings. Save validates and writes pending changes. Cancel, Done, and other navigation prompt with Save, Discard, or Keep editing only when the draft differs from saved values. Closing or refreshing the browser uses its native unsaved-change warning; cancel that warning to return and save. Draft credentials stay in page memory, never browser storage. A failed save retains remaining edits and reports if earlier actions succeeded.

Protocol save errors keep the selected accordion open and preserve the in-page draft. Field validation appears beside the field; server errors appear within the affected form. Shared success notifications use dismissible toasts, including after save redirects. Only confirmation messages use popups; diagnostic and discovery dialogs remain interactive tool views.

Graph origin badges use the stable graph-template hashes in Cacti’s `install/templates/*.xml` and `.xml.gz` packages. Matching originals are System Defined; templates with other hashes are User Defined. Saved stock template names, graph settings, items and inputs are compared with the supplied originals; changes show “System Defined · Edited” and an explanatory footnote. Export-version prefixes and newly introduced exporter fields are ignored. If original packages are unavailable, the UI shows Origin Unavailable instead of guessing. Labels are read-only and do not change Cacti templates.

## Presets

The shared navigation menu and Cacti console include Presets. `presets.php` starts on Segment with the Node, Site, Segment, Device Type, Network Connections and Protocols tabs; Segment, Device Type and Network Connections are implemented. Segment cards read the existing `plugin_icct_nms_categories` records used in device classification. Management users can add or rename segments in an inline editor. Duplicate names and invalid text produce inline feedback with the draft retained; successful writes show the shared success toast. Deletion requires confirmation and is blocked while a device classification device type preset or imported SNMP recording references the segment. All writes enforce the native management realm and CSRF token. Existing classifications are retained; this screen does not seed or replace saved segments. Static HTML is available at `html-css/icct_nms/presets.html`.

Protocol help icons use concise, keyboard-accessible tooltips. Numeric guidance reads the form’s minimum/maximum attributes and units, with “maximum not configured” when the save validation only sets a lower bound. Discovery controls match the server ranges: collection 300–86400 seconds (a multiple of the poller interval), stale 600–604800 seconds (at least twice collection), refresh 10–300 seconds. SSH, serial/Modbus, SNMP security and diagnostic parameters include range or selection guidance.

The Device Type tab (`presets.php?tab=device-type`) lists the saved name/segment, topology icon, physical port count, image and edit/delete actions. Names sort in either direction, and pagination supports 10, 25 or 50 items. Existing device classifications are included without inventing port counts or device photos. Add/edit saves the catalogue in `plugin_icct_nms_meta` under `device_type_profiles`; assigned types cannot be renamed, moved or deleted until devices are reassigned. Validation retains the editor and uses inline errors; saves use success toasts.

The icon SVGs, derived from the NMS topology icon set, live in `assets/images/device-types/`. Device image uploads live in `assets/images/device-types/uploads/`, are validated as PNG/JPEG/WebP (500 KB, 16 megapixels maximum), decoded with PHP GD and saved as PNG thumbnails up to 512 pixels. Network, rack and map visibility each support None, Icon or Image; Image requires a saved image. `icct_nms_type_asset()` resolves the selected local asset for a view. This catalogue is owned by ICCT NMS and does not modify the separate NMS plugin's settings. Uploaded files are runtime data excluded from Git; back up the upload folder together with the database.

On installation, make only `assets/images/device-types/uploads/` writable by the web-server account. On RHEL use `httpd_sys_rw_content_t` for that directory and retain the included `.htaccess`. Keep the rest of the plugin read-only. PHP GD is required for uploads. The static Device Type preview is `html-css/icct_nms/device-types.html`; it demonstrates the interface without writing to Cacti.

The Network Connections tab (`presets.php?tab=network-connections`) shows five cards per desktop row with the name, colored line preview and edit/delete actions. Its six initial profiles match the supplied reference. Add/edit supports the NMS topology styles (solid, dashed, dotted, dash-dot, fine-dotted, short-dashed) and endpoint symbols (none, circle, square, arrow), with a live preview. ICCT saves its own catalogue in `plugin_icct_nms_meta` under `connection_profiles`; it does not overwrite the separate NMS plugin’s connection table. Empty saved catalogues remain empty. Native realm/CSRF checks protect writes; duplicates and invalid colors/styles stay inline with the draft retained. The static preview is `html-css/icct_nms/network-connections.html`.

Native SNMP/availability fields use one shared range definition for server validation and HTML/tooltip limits: SNMP port 1–65535; SNMP timeout 1–16777215 ms (Cacti unsigned MEDIUMINT capacity); ping timeout 1–2147483647 ms; ping retries 0–2147483647. These are accepted limits, not recommended timeout settings. Saved values remain unchanged.

Device Type add/edit appears inline below the preset tabs, matching the Segment and Network Connection panels. Topology visibility controls stay visible without an accordion. It uses the four-column reference layout and an overlay icon picker with six compact columns. The picker discovers SVG files from `assets/images/device-types/` and `assets/images/icons/` (lowercase filename keys with letters, digits, hyphens or underscores); copy trusted icon assets there to add choices. The Add/Edit Device form filters saved type profiles by segment without displaying icon, image or port summaries. A segment with one type selects it automatically when changed; existing selections, including None, are retained on initial load. Device saves validate the type against the selected segment, and appearance settings remain in the shared preset catalogue.

Presets → Site reads Cacti’s native `sites` table on each page load, with a searchable list and active device counts. Add/Edit covers the site name, address, timezone, latitude/longitude, map zoom, notes, and alternate name. Saves use Cacti’s native `sql_save()` and site cache timestamps, with management realm 3 and CSRF protection. Coordinate ranges are −90–90 latitude and −180–180 longitude; zoom is 0–23. Validation errors retain the form; successful saves show a toast.

## Dashboard map topology

`plugins/icct_nms/topology.php` is the Dashboard entry in the ICCT NMS console and header menus. It uses the same bundled Leaflet 1.9.4, local state/UT polygons and GeoServer web service as NMS. ICCT owns its map controller and data adapter; it does not load NMS runtime code or read NMS-owned device tables.

The administrator's existing Cacti configuration is reused:

```php
$config['nms_geoserver_wms_url'] = 'http://127.0.0.1:8090/geoserver/wms';
$config['nms_geoserver_layer'] = 'nms:countries';
```

Cacti authenticates both the page and WMS image endpoint under the existing ICCT view realm. Bounded EPSG:3857 PNG requests use only the configured upstream URL and layer; request parameters cannot select a server. No change to the GeoServer service or security configuration is required.

Native Cacti sites provide coordinates and grouping; ICCT inventory supplies only devices permitted for the current account. Default 0,0 and invalid coordinates remain unlocated. Editing the native site or device site assignment is reflected on refresh. Device Type map visibility chooses the popup icon/image. Disabled, stale and pending devices remain visible as Other rather than being counted online. Counts cover all permitted inventory; site markers cover only located devices.

Site selection opens device details. Fit sites, India overview, zoom, refresh and fullscreen controls are available. Device links open ICCT Add/Edit Device. Multiple devices at a site get a popup selector. The popup shows current availability, segment, serial number, response time, supported fresh CPU/memory RRD measurements, private current-user ping measurements and current threshold alarms. Unsupported or stale measurements remain unavailable. Packet loss is never inferred from Cacti availability. No live diagnostics or device writes occur when viewing the map.

CPU sources are explicit cpu_percent / 5min_cpu or 100 − ssCpuIdle. Memory is memory_percent or (mem_total − mem_free) / mem_total. Reads use only graphs the account can access, fresh AVERAGE data before CDEF/VDEF transformations. Arbitrary graph names, load averages and unknown units are not treated as percentage measurements. Ping output is parsed on the server, checked against its current device/profile/collector signature and retained privately by request owner; no raw output or credentials are sent to the map.

Tests: `plugins/icct_nms/tests/map_service_test.php` covers authorized grouping, hidden devices, unlocated records, counts, coordinate bounds, safe WMS parameters and valid percentage conversion. Browser checks covered the live GeoServer image, Bengaluru and Delhi popups, Fit sites, India overview and error-free console. A restricted interactive account was unavailable; device filtering uses the shared native ACL and is covered by the regression fixture.

Dashboard views use a compact Topology / Rack View / Image View / Map View switcher. Topology currently shows permitted device cards; Rack View shows saved device placements; Image View shows preset assets. Map credits are available through the information control below the map. The map toolbar no longer includes site selection or the unlocated-device list.

Node Configuration is available in both ICCT navigation menus. It shows permitted devices in one list with device search and site, node and status filters. The separate Add section displays the selected node’s site as read-only and offers a searchable multi-device picker. Assigned devices and devices at other sites are disabled with a reason. Bulk assignments save atomically; invalid site, rack or node membership rolls back the entire selection. Rack placements continue to determine node membership, and direct assignments can be unassigned from the list. Management permission, device access and Cacti CSRF checks protect saves. Explicit assignments are stored as device_node_id_<host id> metadata; stale or cross-site assignments display as unassigned. Nodes with assigned devices cannot be deleted or moved to another site until those devices are unassigned.

Add/Edit Device keeps only Rack Name and Placement in the Rack. Reusable rack presets appear at the selected site even before they are instantiated. Selecting a preset limits placement choices to its units-per-rack count. On save, the preset is instantiated at that site under a node named after the preset; existing matching node racks are reused. Capacity and occupied-unit checks remain authoritative.

Map View now draws only the locally served India state boundaries on white. The overview includes island territories, limits zoom-out to the full India view, and restricts panning to India bounds. GeoServer country/ocean raster overlays are omitted from this view; the authenticated WMS relay remains available for compatibility.

### Rack placement

Dashboard Rack View displays numbered rack cabinets from node/rack presets. Select a node and use the pencil control to drag devices into consecutive units or the device list to unassign them. Unassigned devices at the node site remain available in the list. Dragging stages a draft without changing saved placements. Click Save to persist all moves together. Leaving edit mode, changing node or switching views prompts Save, Discard or Keep editing; reloading or closing the page uses the browser unsaved-changes warning.

Add/Edit Device uses Rack Name, Rack Number and a multi-unit picker. Occupied and reserved units are disabled; a device must occupy consecutive units. Both surfaces share a locked, transactional placement service with native management/device permissions, capacity and overlap checks, and stale-move detection. Numbered placements use the existing rack_devices table; reserved dummy items use plugin-owned metadata and do not create Cacti devices. Placement edits enter the device configuration history.

Rack View shows three cabinets at a time, with previous/next controls when a node has more than three racks. Zoom, fit, fullscreen and placement editing are on the right control rail. Direct node membership, numbered rack placement share single-node validation under the rack lock. Unassign a device before assigning it to another node; routine saves of directly assigned, unracked devices preserve their node membership.

Drag the grey Reserved slot dummy from the edit device list into an empty rack unit to reserve space, or back into the list to clear it. Reservations use the same Save/Discard draft and overlap validation as device placements. Rack cards and map popup device names do not navigate to Edit Device.

Dashboard Topology displays status-coloured device cards and actual fault-rule counts, with the core switch initially centered. The pencil enables drag placement (or arrow-key positioning); Save persists normalized canvas positions independently of rack/site placements. Leaving an unsaved layout prompts Save, Discard or Keep editing. Zoom, fit and fullscreen sit on the right rail. Connection lines resolve current, unambiguous CDP/LLDP neighbour observations from successful snapshots within the device’s configured stale interval and matching its current enabled methods and configuration; missing or ambiguous peers do not create links. Layout saving uses native management/device authorization, CSRF validation, bounds checking, a database lock and a stale-write revision.

Node Configuration shows plain device names, shared status badges and a paginated device list (10 rows by default, configurable to 5/25/50). Search and site/node/status filters run across the full list and reset to page one. Device states use green Up, red Down, grey Disabled and amber unavailable/unknown in Inventory, Node Configuration, Rack/Topology cards, Map popups and Image View. Port states use green Available, blue In use, grey Disabled and amber Unknown.

Device Type presets include a Device Shape section with Square, Rectangle, Wide rectangle and Tall rectangle. Shape is stored in the shared type profile and resolved by both segment and type name for existing and new devices. Add/Edit Device previews the inherited shape; network topology cards use its dimensions, while rack labels and map device popups show the same shape symbol. Rack unit occupancy and site map markers keep their physical/location meaning. Changing a type shape applies to its assigned devices on page refresh.

Topology Configuration is available from both plugin and Cacti menus. Device Connections saves manual source/target ports and connection presets, with self-link/duplicate/permission validation. Source, target and discovery device selectors support name/address search and tooltips. A shared, filterable and paginated list shows saved manual connections and actual detected neighbours with local/remote ports, method, freshness and detection time, rather than listing every configured collector. Its discovery controls use per-device presets and the assigned collector for LLDP, CDP, IPv4/IPv6 IP neighbours and MAC/FDB tables. Discovered Devices combines those observations with native Cacti network discovery results, supports search, method/network filters and pagination, and keeps indirect evidence separate from direct neighbour adjacency. Networks displays and edits core automation networks inside the plugin, including subnet ranges, collector/site, schedule and multi-day selections, notifications, reachability/SNMP options and automatic-add settings. Save writes the same native automation record and recalculates address counts with Cacti’s subnet parser, while keeping runtime counters intact. Discover Now uses Cacti’s native local/remote collector dispatch. Automation data requires realm 23; device settings require realm 3 and per-device access. Unknown neighbours must be added as Cacti devices before appearing as topology cards. ARP/FDB observations do not constitute a full routed Layer 3 topology or a proven physical cable.
