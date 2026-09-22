# Native action controls — 0.6.1

Topology action links now use Cacti's `ui-button ui-corner-all ui-widget` classes. Existing navigation retains native tabs. The update covers discovery evidence, SNMP configuration, profile editing, categories, Sites, node assignments, inventory classification, physical-port configuration and mapping, simulator downloads, and device-card actions on the topology canvas. Inline pipe/dot separators between action controls were removed. Canvas card actions use a wrapping row with spacing.

This release changes presentation only. URLs, permissions, form submissions and topology configuration are preserved. No core Cacti or NMS plugin files were edited.

Validation: PHP syntax passed, all 10 existing guide checks passed, and 17 rendered page variants were inspected for plain text action anchors. The browser audit also found and corrected the dynamically generated canvas links. Discovery, inventory and canvas screenshots confirmed native button styling and spacing. The SVG evidence diagram retains clickable device shapes.

VM backup: `/var/backups/topology-actions-20260907/topology-before.tar.gz`.
