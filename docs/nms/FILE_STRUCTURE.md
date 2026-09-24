# NMS file structure

178 owned files; 480 bundled dependency files. Public entry points stay at the plugin root.

```text
plugins/nms/
├── css/
│   ├── nms-capabilities.css
│   ├── nms-core-editor.css
│   ├── nms-devices.css
│   ├── nms-fault-config.css
│   ├── nms-graphs.css
│   ├── nms-layout.css
│   ├── nms-map-india.json
│   ├── nms-map-states.json
│   ├── nms-map.css
│   ├── nms-nodes.css
│   ├── nms-snmp-form.css
│   ├── nms-snmpsim.css
│   ├── nms-ssh.css
│   ├── nms-tooltips.css
│   ├── nms-topology-config.css
│   ├── nms-topology.css
│   ├── nms-typography.css
│   └── nms-v1.1.css
├── images/
│   └── nms.svg
├── includes/
│   ├── nodes/
│   │   ├── page.php
│   │   └── service.php
│   ├── platform/
│   │   └── systemd.php
│   ├── topology/
│   │   ├── appearance.php
│   │   ├── canvas.php
│   │   ├── catalog_page.php
│   │   ├── config.php
│   │   ├── config_page.php
│   │   ├── connections.php
│   │   ├── connections_page.php
│   │   ├── discovery.php
│   │   ├── map.php
│   │   └── service.php
│   ├── capabilities.php
│   ├── categories.php
│   ├── core_form_options.php
│   ├── database.php
│   ├── device_manager.php
│   ├── device_metadata.php
│   ├── diagnostic_bandwidth.php
│   ├── diagnostic_history.php
│   ├── diagnostic_summary.php
│   ├── diagnostics.php
│   ├── diagnostics_queue.php
│   ├── discovery.php
│   ├── discovery_display.php
│   ├── discovery_endpoints.php
│   ├── discovery_neighbors.php
│   ├── discovery_network.php
│   ├── discovery_schema.php
│   ├── discovery_snmp.php
│   ├── functions.php
│   ├── graph_item_options.php
│   ├── graph_template_manager.php
│   ├── graphs.php
│   ├── groups.php
│   ├── import_object_report.php
│   ├── import_summary.php
│   ├── interface_rates.php
│   ├── inventory.php
│   ├── mib_import.php
│   ├── navigation.php
│   ├── polling.php
│   ├── readings.php
│   ├── relationships.php
│   ├── rule_scope.php
│   ├── snmprec.php
│   ├── snmpsim.php
│   ├── ssh.php
│   ├── ssh_graphs.php
│   ├── ssh_linux.php
│   ├── ssh_platform.php
│   ├── ssh_schema.php
│   ├── template_manager.php
│   ├── template_native.php
│   └── template_workspace.php
├── js/
│   ├── nms-connection-preview.js
│   ├── nms-devices.js
│   ├── nms-hybrid.js
│   ├── nms-map.js
│   ├── nms-nodes.js
│   ├── nms-pagination.js
│   ├── nms-readings.js
│   ├── nms-ssh-console.js
│   ├── nms-ssh.js
│   ├── nms-template-frame.js
│   ├── nms-templates.js
│   ├── nms-tooltips.js
│   ├── nms-topology.js
│   ├── nms-ui.js
│   └── nms-upload.js
├── snmpsim/
│   ├── configure.py
│   ├── nms-snmpsim-reload.py
│   ├── nms-snmpsim-run.py
│   ├── requirements.txt
│   └── runtime_config.py
├── ssh/
│   ├── assets/
│   │   └── guacamole-1.6.0.js
│   ├── guacamole/
│   │   ├── LICENSE
│   │   ├── NOTICE
│   │   ├── manifest.json
│   │   ├── nms-guacd.service
│   │   ├── protocol.php
│   │   ├── session.php
│   │   └── strict-auth.patch
│   ├── vendor/
│   │   └── … bundled SSH dependencies
│   ├── bootstrap.php
│   ├── composer.json
│   ├── composer.lock
│   ├── config.example.json
│   ├── configure-macos.py
│   ├── configure-windows.ps1
│   ├── configure.py
│   ├── gateway.php
│   ├── metrics.php
│   ├── nms-ssh.service.in
│   ├── nms_ssh.te
│   ├── patch-dependencies.php
│   ├── process.php
│   ├── runtime.php
│   ├── store.php
│   ├── strict_ssh.php
│   ├── windows-acl.ps1
│   ├── windows-service.cs
│   ├── wire.php
│   └── worker.php
├── templates/
│   ├── devices/
│   │   ├── add.php
│   │   ├── discovery_fields.php
│   │   ├── discovery_results.php
│   │   ├── edit.php
│   │   ├── graphs.php
│   │   ├── inventory.php
│   │   ├── nodes.php
│   │   └── readings.php
│   ├── diagnostics/
│   │   ├── behavior.php
│   │   ├── history.php
│   │   ├── profile_dialog.php
│   │   ├── profiles.php
│   │   └── run.php
│   ├── discovery/
│   │   ├── devices.php
│   │   ├── inventory.php
│   │   ├── map.php
│   │   └── presets.php
│   ├── faults/
│   │   └── rule_scope.php
│   ├── monitoring/
│   │   ├── capabilities.php
│   │   └── graphs.php
│   ├── repository/
│   │   ├── import.php
│   │   ├── mib.php
│   │   └── record_report.php
│   ├── topology/
│   │   ├── canvas.php
│   │   ├── catalog.php
│   │   ├── config.php
│   │   ├── discovery.php
│   │   ├── index.php
│   │   └── map.php
│   ├── app_footer.php
│   ├── app_header.php
│   ├── diagnostics.php
│   └── navigation.php
├── vendor/
│   └── leaflet/
│       ├── LICENSE
│       ├── leaflet.css
│       └── leaflet.js
├── .editorconfig
├── INFO
├── capabilities.php
├── config.example.php
├── devices.php
├── diagnostic_listener.php
├── diagnostic_worker.php
├── diagnostics.php
├── discovery_presets.php
├── fault_config.php
├── file_repository.php
├── graphs.php
├── index.php
├── network_discovery.php
├── nms.php
├── setup.php
├── ssh_api.php
├── ssh_console.php
├── ssh_device.php
├── ssh_presets.php
├── templates.php
└── topology.php
```

Shared components: `js/nms-ui.js`, `templates/app_header.php`, `templates/app_footer.php`,
`includes/functions.php`, and `includes/diagnostic_bandwidth.php`. Feature services
and templates remain separate. Geographic topology uses `includes/topology/map.php`,
`templates/topology/map.php`, `js/nms-map.js`, and `css/nms-map.css`.

Node phases 1–3: `includes/nodes/service.php` owns schema, validation, membership and summaries; `includes/nodes/page.php` handles the Nodes tab; `templates/devices/nodes.php` renders setup/details. `js/nms-nodes.js` is reused by node and device forms; `css/nms-nodes.css` scopes node layout. No extra public controller or duplicated Cacti device table.
