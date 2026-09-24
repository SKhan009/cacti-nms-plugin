# NMS plugin architecture

The paths below are stable Cacti plugin paths. Keep public page controllers and lifecycle entry points stable: Cacti loads them by exact filename. Internal modules may be consolidated when all callers are updated.

```text
nms/
├── *.php                    Cacti page controllers and plugin lifecycle entry points
├── css/                     Page and component stylesheets
├── images/                  Plugin-owned visual assets
├── js/                      Shared nms-ui.js plus focused feature scripts
├── includes/                PHP domain services; no page markup
│   ├── topology/            Topology service, canvas, discovery and configuration
│   ├── discovery*.php       SNMP, LLDP/CDP, ARP/FDB and network discovery
│   ├── ssh*.php             SSH sessions, platform adapters and stored settings
│   ├── template*.php        Template import and native Cacti template helpers
│   ├── diagnostics.php      On-demand collector diagnostic service
│   ├── diagnostic_bandwidth.php  Shared local iPerf3/Netperf execution
│   ├── database.php         NMS-only schema setup and readiness checks
│   └── polling.php          Cacti poller hooks
├── templates/               Reusable views; no database writes or shell commands
│   ├── devices/             Add, edit, inventory, graphs and discovery view fragments
│   ├── topology/            Topology page, canvas, catalog and configuration views
│   ├── discovery/           Discovery devices, inventory, map and preset views
│   ├── monitoring/          Capabilities and graphs views
│   ├── faults/              Fault-rule view fragments
│   ├── repository/          MIB and SNMP record import view fragments
│   ├── diagnostics/         Protocol-check page partials
│   └── app_header/footer    Shared application chrome
├── snmpsim/                 Offline SNMP simulator helpers
└── ssh/                     SSH gateway runtime and its isolated third-party dependency tree
```

## Conventions

- Controllers validate requests, call services, assemble view data, then render a template.
- Files in `includes/` return data or perform one domain action. They never emit page markup.
- Files in `templates/` render supplied values only. Escape text with `nms_h()` and keep scripts in a named `behavior.php` partial or `js/` module.
- Put one public page's large sections in a matching `templates/<page>/` directory. For example, the device inventory view is always `templates/devices/inventory.php`; topology views are under `templates/topology/`.
- Root `topology.php` is only the Cacti route. Its implementation is in `includes/topology/` and its markup is in `templates/topology/`, so search results are unambiguous.
- Use tabs for PHP indentation, braces on the same line as declarations, one expression per line, and a short docblock for exported functions.
- Do not edit `ssh/vendor/`; it is an isolated third-party dependency tree.

## Discovery flow

```text
Cacti poller hook
    → includes/polling.php
    → includes/discovery.php
    → includes/discovery_snmp.php
    → LLDP/CDP or IPv4/IPv6/FDB parsers
    → NMS snapshots
    → topology and device templates
```

The user interface never starts a direct web-request SNMP walk. A test queues work for the assigned Cacti collector.

## Compact components

- `js/nms-ui.js`: one shared sidebar, dialog, confirmation and notification module,
  loaded by `templates/app_footer.php` on every NMS page.
- Dialogs opt in with `data-nms-auto-open` and use `data-nms-dialog-close` buttons.
  Forms use `data-confirm` for confirmation. Notification consumers use `nmsNotify`.
- `includes/diagnostic_bandwidth.php`: collector-address classification and bounded
  local iPerf3/Netperf server helpers. Public PHP function names are unchanged.
- Repository views live only under `templates/repository/`; device-page import
  bookmarks continue to redirect through the existing controller.
- The diagnostics heading is part of its page assembler, avoiding a single-use file.

Owned NMS files reduced from 174 to 167. Bundled SSH dependencies remain untouched.
Public PHP routes, Cacti hooks and realms are unchanged. See [complete owned file
structure](FILE_STRUCTURE.md) and [every file including vendor](FILE_STRUCTURE_ALL.txt).

## Geographic topology

Network view retains the existing canvas. Map view reads Cacti `sites` coordinates
and native `host.site_id` memberships with the shared device ACL. GeoServer supplies
only the world basemap through an authenticated, bounded WMS PNG proxy. Leaflet
is bundled in `vendor/leaflet`; no external browser tile provider is required.
See [Topology map setup](TOPOLOGY_MAP.md).

## Node containers (1.10.99)

Device management dispatches its Nodes tab to `includes/nodes/page.php`. `includes/nodes/service.php` owns explicit same-site membership in native Cacti hosts, guarded by native permissions and a shared write lock. Views and assets remain in their existing module directories. See [NODE_SETUP.md](NODE_SETUP.md).
