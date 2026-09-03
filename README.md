# NMS Fault Management and Dynamic Topology Plugin

The `nms` plugin adds dynamic fault management to Cacti 1.2.31 without modifying Cacti core.

## Working release status

The working version is 1.10.0. Native migration and VM deployment are not yet
verified. Read [upgrade and rollback gates](docs/UPGRADE_1.10.0.md) and the
[evidence ledger](docs/GOAL_PROGRESS.md) before deployment.

## Generic offline package

Use one reviewed plugin folder across validated Cacti installations. Start with
[Generic installation](docs/GENERIC_INSTALL.md): use your actual Cacti root, PHP
account and browser URL instead of assuming an OS-specific path or username.
Optional simulator configuration supports local POSIX/Windows paths and manual
activation; legacy systemd support is isolated in a Linux-only adapter. Cross-OS
path tests are not a claim of live Windows/macOS integration testing.

## RHEL installation and missing-menu repair

**Offline RHEL:** no internet, CDN, cloud service, npm, Composer or Python is
required for NMS itself. All web assets are included. See
[offline operation](docs/OFFLINE_RHEL.md) for requirements and the optional
SNMPSim boundary. Do not install packages from online repositories on the target.

Version 1.9.32 adds a native NMS Console sidebar and repairs hook registration when
installed, upgraded or enabled. See [RHEL menu repair](docs/RHEL_MENU_REPAIR.md) for
offline deployment to `/usr/share/cacti`, safe permissions, SELinux checks, Cacti
user permissions and the distinction between a directory 403 and a PHP error.
Do not uninstall NMS or use chmod 777 to repair navigation.

## Templates workspace

Version 1.9.35 adds an expandable **Templates** sidebar menu, not a top-header
tab. Its five sections use the installed Cacti editors directly: Data Input
Methods, Data Queries, Data Source Templates, Graph Templates, and Device
Templates. Start with an input method and data-source template, then a graph
template and device template; indexed collection also uses a data query.

Native forms, dropdown options, conditional fields, validation, save messages,
permissions, CSRF checks, pagination and associations remain controlled by Cacti.
NMS applies its monochrome sidebar and two-column form layout through plugin
hooks, without modifying core files or embedding a frame. Direct native URLs
remain available; `nms_workspace=off` explicitly selects the original core UI.

The existing reading-based graph builder is now under **Templates → Graph
Templates → Create graph template from a reading**. Legacy device-builder links
redirect there. Device management retains device add/edit and graph/query
associations, but no longer owns graph-template creation.

## Native graph-template options

Templates → Graph Templates → Create graph template from a reading reads the installed Cacti `struct_graph` form
definitions, option arrays, configured defaults, and database presets. Common,
scaling, grid, axis, and legend sections use native options without per-field
override switches. Multiple Instances and Test Data Sources are saved on the native
template. Width/height are editable values, not a fixed size menu; labels retain
Unicode and Cacti's field lengths. Invalid selections produce errors instead of
silently substituting a color, format, or preset ID.

The builder writes `graph_templates`, `graph_templates_graph`, graph items, and
input mappings using Cacti's `sql_save` helper, as the native editor does. It no
longer depends on a template named “SNMP - Generic OID Template” for this workflow.
Open the created template in Cacti to edit/reorder individual items or add more
readings. Importing SNMP records still intentionally duplicates Cacti's Generic
OID data/graph templates so their native collection configuration is retained.
No core files or additional graph-settings store are created.

NMS does not expose per-field override switches. Native editor submissions retain
existing Cacti override metadata unchanged; normal value switches such as Active
and Auto Scale remain available. The reading-based builder ignores override flags
and creates graph templates with their values fixed. Use the core UI directly if
an existing template's override policy must be changed. Collapsed navigation is
icon-only; selecting Templates expands its readable submenu.

Device add/edit uses core SNMP/authentication/privacy, availability, ping method,
maximum-OID and collection-thread choices. Ports, timeouts, and retries are editable
numbers. NMS-specific fault severities and known-OID recognition remain plugin logic.
Data-query selection and attachment resolve the query's native `data_input.type_id`;
they do not assume that an input-method record with ID 2 means SNMP. This preserves
script queries on SNMP-disabled devices even when installations use different IDs.

Standalone checks: `php tests/core_form_options_test.php` and
`php tests/graph_item_options_test.php`. On a test Cacti installation,
`php tests/graph_item_integration_test.php --run` explicitly creates temporary QA
templates, verifies native options/items/input mappings, then removes only those
test templates. Do not run this write test against production without approval.

## Manual serial-number entry

Open **Devices → Edit device → Device serial number (NMS)**, enter the serial
printed on the device, and choose **Save serial number**. Leave it blank and save
to clear it. New devices also have an optional **Serial number (manual)** field.

When no NMS serial is saved, Edit device prefills a suggestion from a fresh,
successful SNMP observation of the serial OID linked through the device's current
imported host template. The field shows the OID and observation time. Review and
save to persist it; viewing the page does not save anything. Existing manual values
and attempted form edits are preserved. Failed/stale reads, disabled/down devices,
and imported sample values are never used as suggestions. No linked OID means
manual entry remains available. Clearing removes the saved NMS value; the immediate
confirmation stays blank, but a later visit may offer a new unsaved SNMP suggestion.

These values are stored only in `plugin_nms_device_metadata`, keyed by Cacti
`host_id`, with the last editor and update time. The dedicated edit action does
not call Cacti's device-save API or modify core tables. The existing Add device
workflow still creates the core device normally, then saves optional NMS metadata.
The table is created by NMS's checked schema setup on installation/upgrade, never
by ordinary page initialization, and removed on plugin uninstall.

Manual values are labeled separately in the device inventory. They do not replace
SNMP observations, reset the serial fault baseline, or count as successful polls.
Regression checks: `php tests/device_metadata_test.php` (no Cacti database needed).

Data sources:

- current device status from `host`;
- actual device parameter definitions from Cacti data templates;
- latest raw values returned by Cacti device collection, captured through `poller_output`;
- live text inventory queried with Cacti's SNMP API because RRDtool cannot store strings;

Text inventory only probes devices owned by the current enabled native Cacti
collector. Missing identities, a differing CLI collector override, and invalid
native retry settings are errors, not reasons to select a different collector.
Down devices and devices with SNMP disabled are not probed; disabled SNMP is stored
as `unconfigured`. Failed/skipped reads do not refresh the successful observation.
Inventory writes are checked, unfiltered backend errors are not copied into stored
diagnostics, and a collector's partial host cache cannot trigger global inventory
deletion. Native remote-collector deployment and database synchronization still need
integration verification; this is not a claim that remote text inventory is deployed.

The plugin stores independent equipment classification, operational groups, fault
policy/incident audit, manual metadata, missing topology relationships, and latest
observations needed by its rules in `plugin_nms_*` tables. Cacti remains authoritative
for devices, templates/classes, sites, trees, data sources, polling and graph history.
See the upgrade guide for table-by-table ownership and migration behavior.

Shared helpers keep classification, rule validation, freshness and incident lifecycle
consistent. Native editors remain available for core-owned settings. Missing or
partial schemas produce an explicit upgrade-required state; poller hooks skip NMS
work without interrupting native RRD updates.

## Device categories and fault rules

Equipment categories are independent of Cacti tree IDs. The initial editable
catalogue includes Network, Voice/Video, Security, Satellite/VSAT, LOS Communications,
Computers, Power, Timing, Sensors/Instrumentation and Fire Prevention. Existing
legacy category labels and rule scopes are preserved rather than silently merged.

In **Fault Configuration → Categories and template defaults**, edit catalogue names
and descriptions and set reviewed suggestions for native host templates. **Devices →
Edit device → Classification** assigns an individual category, type and role.
Sharing a template does not force devices into the same category. Site, native
template class, tree membership and many-to-many operational groups remain separate.

Rules select actual configured Cacti data-template items or mapped inventory status;
an import defines OIDs, not verified model support or live values. Thresholds,
comparisons, units, severities and enabled states are plugin policy, while numeric
measurements come from core polling. Unsupported legacy rule metrics stop migration
with actionable IDs instead of being silently deleted.

Expand **Applicability** beneath a saved rule to combine native template, declared
device type, input method, SNMP version, device, query/index and exact data-source
filters. Defaults preserve the original category-wide rule breadth. Interface scope
requires native query-cache interface evidence; component/service scope requires an
explicit data source, not a name-based guess. These settings extend the existing
plugin rule table and do not create another collector or core configuration store.
SNMP-version matching for numeric sources reads the native poller-item configuration;
script input methods are not assumed to use SNMP. Core availability remains governed
by Cacti's own availability method. A shared-category policy edit requires native
management permission and visibility of the category's devices.

Policy changes are evaluated by Cacti's next post-poll hook. Ordinary dashboard or
device views do not reconcile incidents, rename templates, or run schema migrations.

The plugin does not create generic poller or RRD-file faults. It records the latest raw
device value in `plugin_nms_device_parameters`, evaluates it against category rules and
stores only resulting incident state. A value older than two poll intervals is historical
and is neither displayed nor evaluated as current. It never inserts sample readings or
uses an imported `.snmprec` value as a live fallback.

Interface and other indexed data sources retain Cacti's data-source name and SNMP index in
their live display label. Category-wide rules still apply to every matching instance, but
each resulting incident identifies the affected port, sensor, disk, or other indexed item.
Parameter incidents require fresh core state and fresh samples. A down device, stale
sample, removed data source, or absent rule does not prove recovery and does not
refresh/resolve retained incidents. Only an explicitly evaluated clear condition
resolves its matching incident; retained out-of-scope incidents require review.

All native dropdowns in the NMS interface share one CSS chevron with fixed right and
vertical alignment, including device forms, fault rules, topology controls, graph controls,
and pagination page-size selectors.

## Dynamic topology

Topology reads authorized native hosts, sites, interfaces and core status. Devices
must have a Cacti Site before using the site canvas. A reviewed anchor and dragged
positions control layout only; they do not prove physical or logical connectivity.

The **Connections** section records manual network, power or containment edges
separately from coordinates. Interface endpoints retain native query ID, SNMP index
and identity text so index reuse can be flagged for revalidation. Management ports
such as UDP 161 are not interface IDs. Multiple and cross-site relationships are
supported as records; only mapped endpoints in the current site are drawn together.

Manual connections have recorded/audit time but no fabricated discovery timestamp.
No LLDP/CDP discovery collector is currently integrated. Legacy parent-layout data is
retained but is not automatically drawn as a verified edge. Removing a map position
does not delete the native device or its independent connections. Archiving a manual
connection retains its record.

The fault dashboard is rendered as a dedicated NMS interface. It still uses Cacti's
authenticated session and live database, but does not display Cacti's administration
chrome. Administrators can return through the **Cacti Backend** button in the header.

The Fault dashboard displays every enabled device from Cacti's `host` table, including
healthy devices. Each row shows status, availability, latest real device parameters and the
latest reading time. Faulted devices also show their
active incident and acknowledgement action. Collector, RRD-file, automation, PHP and
system-log messages are not shown on this page.

The left navigation sidebar can be opened or collapsed from the menu button in the
header. Acknowledgements are recorded against Cacti's authenticated user and move an
incident from **Open** to **Acknowledged** without modifying Cacti core.

## SNMP test devices

The `snmpsim/` directory contains optional lab records and an explicit configuration
generator. Examples are not activated merely by installing NMS. Configure an
administrator-owned JSON file outside the web root and select it with
`NMS_SNMPSIM_CONFIG`; no implicit Linux configuration path is used.

Manual activation is portable across separately validated responder environments.
The optional Linux/systemd adapter requires explicit executable/helper/configuration/
lock paths, account, service and endpoint settings. PHP never launches the responder
or starts/stops its service. A stopped simulator must result in a genuine failed or
stale reading, never a value read from an uploaded record.
See [the simulator guide](snmpsim/README.md).

## Device management and SNMP record imports

### Shared interface layout

All NMS pages load `css/nms-typography.css` and then `css/nms-layout.css`
through `templates/app_footer.php`. Keep new pages on that shared footer so
device management, graphs, imports, fault configuration and topology stay consistent.
Use `.nms-panel-head` for black panel headings and `.nms-form-grid` for full forms.
The interface uses a black-and-white theme with neutral gray borders and hover
states. Use `--nms-accent` and `--nms-accent-hover` for actions and navigation;
reserve `--nms-success`, `--nms-red`, `--nms-orange`, and `--nms-amber` for
semantic messages and statuses. Do not desaturate device health indicators,
destructive actions, or Cacti graph/color-picker swatches. Open
`tests/theme-preview.html` through a local static server for a database-free
visual check of shared components.
Full forms use two equal columns, with one column below 700px. Body text and
controls use 12px; section titles use 14px; supporting captions use 11px.
Controls are 40px tall (34px in compact table editors). Keep help text below
controls, not in the label row; preserve native labels and keyboard focus.
Map nodes, zoom controls and compact list rows retain their specialized layout.
Do not hide overflowing page content to mask layout problems or add per-page
font and spacing overrides. Check dropdowns, header stacking, both sidebar states,
and long labels when changing the shared styles.

The **Devices** module provides a live inventory from Cacti's `host`, template, site,
poller, graph, data-source, and poller-item tables. Its Add Device form calls Cacti's
supported `api_device_save()` function, so the created target is a normal core Cacti
device rather than a copied NMS record.

Administrators can upload a static `.snmprec` file up to 2 MB and 5,000 lines. NMS
validates every OID, type tag, value, community, filename, and category before making
changes. It stores import metadata in `plugin_nms_snmprec_*` tables and deploys the
validated record to the configured SNMPSim directory. Text and object-identifier records
remain visible in the import inventory. Numeric Integer, Counter, Gauge, TimeTicks, and
Counter64 records receive native Cacti data-source and graph templates by duplicating the
installed **SNMP - Generic OID Template** through Cacti's template APIs and fixing the
template OID to the imported record. A single upload is limited to 64 graphable readings.

Each import creates or reuses a named Cacti host template, links all generated graph
templates to it, and stores a reviewed equipment-category suggestion linked to the independent NMS category ID. When a device
uses that template, NMS invokes Cacti's `create_complete_graph_from_template()` path to
create native local graphs, data sources, and poller items. Existing matching devices are
reconciled idempotently at the end of a poller run. Uploading templates does not create
synthetic Cacti readings: the poller must retrieve the actual OID from SNMPSim or the
eventual real device.

OCTET STRING records whose section identifies a serial number are inventory, not graphs.
Their OID is queried with the device credentials stored in Cacti. The first successful
value becomes the baseline; later observations report ok/changed/failed separately from
manual serial metadata. Explicit category inventory-status rules control incidents.
There is no hidden changed/failed severity fallback. Existing legacy implicit-policy
incidents are retained for review, not falsely refreshed or cleared by absent policy.
A timeout records a failed live check without substituting the value from the uploaded file.

Use **Upload SNMP record → Download sample file** for a ready-to-import test record. The
sample community can be `nms-device-demo`, with a host template such as **NMS Demo
Environmental Device**. It contains 23 OIDs, including 18 numeric CPU, memory,
environmental, storage, and interface readings.

On **Templates → Graph Templates → Create graph template from a reading**, NMS lists the selected device's live
`data_local` and `data_template_rrd` items from Cacti core. Already-graphed readings stay
visible and are marked read-only. For an ungraphed reading, one action creates a native
Cacti graph template, links its LINE and Current/Average/Maximum graph items to the
selected data-template item, and creates the device graph against the existing local data
source. The data source is reused; it is not copied into an NMS table.
