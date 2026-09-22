# NMS plugin architecture

The paths below are stable Cacti plugin paths. Keep page controllers and runtime includes where they are: Cacti loads several of them by exact filename.

```text
nms/
├── *.php                    Cacti page controllers and plugin lifecycle entry points
├── css/                     Page and component stylesheets
├── images/                  Plugin-owned visual assets
├── js/                      Browser behavior, one concern per module
├── includes/                PHP domain services; no page markup
│   ├── topology/            Topology service, canvas, discovery and configuration
│   ├── discovery*.php       SNMP, LLDP/CDP, ARP/FDB and network discovery
│   ├── ssh*.php             SSH sessions, platform adapters and stored settings
│   ├── template*.php        Template import and native Cacti template helpers
│   ├── diagnostics.php      On-demand collector diagnostic service
│   ├── database.php         NMS-only schema setup and readiness checks
│   └── polling.php          Cacti poller hooks
├── templates/               Page views; no database writes or shell commands
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
