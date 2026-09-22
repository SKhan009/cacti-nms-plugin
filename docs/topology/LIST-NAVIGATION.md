# List navigation — 0.6.2

The sidebar opens populated lists immediately:

- Discovery: all accessible nodes, collection policy and direct SNMP Access / Protocols / Policy / Run & Results buttons.
- Device Inventory: all accessible assigned devices with Site, Node, category, status, physical-port count and direct configuration actions.
- Physical Ports: all accessible assigned devices with direct Configure Ports and Map Interfaces actions.
- Node Topology: all accessible nodes with Open Topology buttons.
- LLDP / CDP Evidence: all accessible nodes with View Evidence buttons.

Each landing list uses native Cacti Search, Site, Rows, Go and Clear controls. Site and row-count changes apply immediately. Search submits with Enter or Go. Lists are paginated and show matching totals. Empty search results retain filters and provide a Clear action. All Sites is the default; it does not implicitly select or configure a node.

Opening a row retains explicit node/device scope. Detail pages replace the node dropdown and View button with an All Nodes or All Devices return button. Return actions clear setup-guide scope rather than silently reopening the same node. Discovery's unselected view has Nodes and Discovery Profiles tabs; its four configuration tabs appear inside a node.

No database migration, SNMP change or new package is required. Native Cacti permissions still determine which nodes, devices and counts appear. Configuration forms keep their required selections for saving an explicit assignment.

Validation: PHP syntax passed; 15 read-only list checks passed against the configured lab, including default visibility, search, Site scope, escaped input, out-of-range pagination, five list renderings and restricted-account visibility. Existing 10 setup-guide checks passed. Browser validation covered all five landing lists, no-match search, immediate Site filtering, direct row actions, and returning from guide-scoped discovery to the unselected list.

VM backup: `/var/backups/topology-lists-20260907/topology-before.tar.gz`.
