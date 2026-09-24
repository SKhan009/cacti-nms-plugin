# NMS for Cacti

NMS is a Cacti plugin for device inventory, discovery, topology, faults, templates, diagnostics, SNMP simulation and managed SSH access.

Start with [the architecture map](ARCHITECTURE.md). It explains which folders own each concern and which exact paths Cacti loads at runtime.

Common files are grouped by feature:

- Device inventory: `templates/devices/inventory.php`
- Topology views: `templates/topology/`
- Discovery views: `templates/discovery/`
- Topology services: `includes/topology/`
- Discovery services: `includes/discovery*.php`

The root `topology.php`, `devices.php`, and similar files are thin Cacti entry points. They remain at the root for Cacti URLs; reusable logic belongs in the feature directories.

## Working in the source

1. Begin with a root controller such as `diagnostics.php` or `topology.php`.
2. Keep request validation and service calls in the controller or `includes/`.
3. Render only through `templates/`; large pages use a matching template subdirectory.
4. Keep styles in `css/` and browser behavior in `js/` or a named `behavior.php` view partial.
5. Run PHP syntax checks and the relevant Cacti/plugin regression checks before packaging an update.

The project uses `.editorconfig`: tabs in PHP, four-column display width, UTF-8 and LF line endings. Do not reformat `ssh/vendor/`.

## Deployment guides

- [GeoServer and Pathchar on RHEL 9](GeoServer_and_Pathchar_RHEL_9_Installation_Guide.docx) includes local-only map configuration and offline package preparation.
- [Netperf on RHEL 9](Netperf_Offline_RHEL_9_Guide.docx) covers installation and verification.
- [Nodes](NODE_SETUP.md) documents manual membership and automatic member-data updates.
- [Topology map](TOPOLOGY_MAP.md) documents the local GeoServer and bundled map assets.
