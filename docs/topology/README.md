# Cacti Topology Configuration — 0.12.1

Native Cacti plugin for device categories, reusable physical port profiles, bulk assignment of existing Cacti devices, configured topology and LLDP/CDP evidence.

**Setup: Device Categories → Port Profiles → Assign Devices.** No Site, Node or reference-device setup is required. Cacti owns device creation, Sites, SNMP credentials, templates, interface queries, data sources and graphs.

The separate Evidence page is removed; old evidence bookmarks redirect to Topology View.

Device inventory is available in **Cacti → Management → Devices**. Old topology inventory bookmarks redirect there.

Read [the current setup guide](SETUP-GUIDE.md) and [the RHEL installation and validation manual](RHEL-INSTALL-AND-VALIDATION.md). Other dated phase and node documents are historical; the current guide supersedes their setup sequence.

The sidebar provides Setup Guide, Topology View, Discovery and Simulator. It uses Cacti’s native tabs, forms, lists, filters, buttons and + controls. The single topology canvas provides draggable devices, category colors, physical port connections, saved positions, − / + zoom, Fit to Screen and cable selection with Disconnect.

Discovery separates Collection Settings, Device Protocols, Run & Results and Saved Profiles into native Cacti tabs. Switching tabs retains unsaved collection and protocol inputs. **Save & Discover Now** saves the configuration and queues collection together. SNMP configuration remains in Cacti core.

The dedicated simulator uses SNMPv2c at 127.0.0.1:1162 inside the VM. Uploading a bounded static SNMP record queues automatic native device/data-source/graph creation after a successful live identity probe. The simulator has only Imports and Upload SNMP Record tabs. Numeric OIDs become one data source each; string/address/OID values and LLDP/CDP neighbor fields are excluded. A dedicated unprivileged worker retries activation delays up to five times, then exposes Retry Provisioning. Neighbor collection uses live SNMP responses and explicit saved protocol/security settings. No protocol or credential fallback is used.

Tested on Cacti 1.2.31, PHP 8.0.30, MariaDB 10.5 and RHEL 9. The current collector supports up to 32 visible assigned devices, 30 seconds/5000 objects per protocol and a 180-second job budget. Native timeout must be 1–5000 ms and retries 0–3. Explicit SNMP engine ID configuration is not supported. This UI release was verified using the existing LLDP simulator pair.

Submenus are rendered from `templates/submenu.php`. List-row navigation uses native text links. Lists use Cacti core’s html_nav_bar pagination with centered counts and numbered Previous/Next links; editable tables keep inputs in the DOM across pages.

## Verification

- `tests/simulator_auto_qa.php`: explicit mixed-OID lab staging and automatic-provision verification; `tests/upload_http.php` tests actual multipart validation only through a temporary loopback PHP test server.
- `tests/discovery_form_test.php`: combined-save validation, rollback, native host preservation and permissions; run only in a disposable clone named `topology_discform_qa_*`.

- `tests/flat_test.php`: migration preservation, idempotency, bulk assignment, rollback and permissions. Run **only in a disposable cloned database** named `topology_flat_qa_*` with `--cacti-root` and `--database`.
- `tests/flow_test.php` and `tests/browse_test.php`: read-only rendering, filtering and escaping checks, with `--cacti-root`.
- `tests/discovery_live.php --mode=baseline`: persisted reciprocal evidence, endpoint-change invalidation and permission checks. Pass the current internal scope and explicit lab host IDs.
- Legacy node-specific tests are retained as text in `tests/legacy/`; they describe the retired node model.

## Upgrade

Back up the database and plugin, stop the topology discovery timer, allow the active job to finish, deploy files and run the normal plugin upgrade. Install the automatic provisioning timer with `sudo python3 simulator/install_provision.py --cacti-path=/var/www/html/cacti --web-user=apache` (substitute the actual paths/account). The simulator installer does this for new installations. Restart the timer. Preserve VM-specific `simulator.local.php`; it is excluded from release archives.

The migration leaves all core tables intact and preserves assigned devices, ports, mappings, cables, layouts, simulator imports, native data sources and graphs. See the setup guide for legacy collection-policy handling.
