# Cacti Topology RHEL Installation and Validation Guide

Plugin 0.12.1    Prepared 7 September 2026

Use this guide to install the Topology plugin into an existing Cacti server, configure its simulator and discovery workers, and check the complete setup with repeatable lab examples. Your current RHEL VM is already configured. Use the verification steps there; use the installation sequence when preparing another server.

The verified server is RHEL 9.8 on ARM64 with Cacti 1.2.31. This release is not certified for every operating system. Its service installers depend on RHEL paths, Apache, systemd, Linux service accounts and SELinux tools.

### Compatibility boundary

| Environment | Status and required action |
| --- | --- |
| RHEL 9.8 aarch64 | Verified on the current VM, with SELinux Enforcing. |
| RHEL 9 x86_64 | Candidate deployment. Install architecture-matched packages and run all acceptance checks. Not tested on x86_64 in this project. |
| Other RHEL 9 minor versions | Not individually verified. Python 3.11 packages require a suitable AppStream repository; they are available from RHEL 9.2 onward [2]. |
| Rocky Linux or AlmaLinux 9 | Not certified. Similar package names do not prove compatibility; repository, service and permission checks are required. |
| RHEL 8 or 10 and Debian or Ubuntu | Not supported by the supplied installation procedure. Requires explicit runtime and service integration work. |
| macOS or Windows as server | No native service installer supplied. Use a supported RHEL VM. |
| macOS or Windows as client | Access the Cacti web UI in a browser. The browser operating system does not run the PHP workers or simulator. |

### Verified software baseline

Cacti 1.2.31; PHP CLI 8.0.30 with SNMP and proc_open; MariaDB 10.5.29; Net-SNMP 5.9.1; RRDTool 1.7.2; Apache 2.4.62. RHEL system Python is 3.9.25; the separate SNMPSim environment uses Python 3.11.13, SNMPSim 1.2.2 and PySNMP 7.1.29.

An operating-system update, new PHP version or different Cacti release needs regression testing. The INFO compatibility target is Cacti 1.2.31. The plugin is source code, not a bundled operating system or a complete Cacti installation.

## 1 Local files and system ownership

### Your local plugin folder

The editable source is stored on your Mac at this exact path:

```text
/Users/saimakhan/Documents/ChatGPT/Cacti NMS Project/plugins/topology
```

The distributable archive and this Word guide are in the project output folder:

```text
output/Topology-Plugin-0.12.1.tar.gz
output/Topology-Plugin-0.12.1.sha256
output/topology-install-guide/Cacti-Topology-RHEL-Guide.docx
```

The plugin must run inside the VM web server. Copying it into a Mac folder alone does not install it in Cacti.

| VM path | Purpose |
| --- | --- |
| /var/www/html/cacti/plugins/topology | Installed plugin PHP, JavaScript, templates and tests. |
| /etc/cacti-topology/simulator.json | Administrator-selected simulator settings and native template IDs. |
| plugins/topology/simulator.local.php | Local PHP configuration; retained during upgrades and excluded from release archives. |
| /var/lib/cacti-topology/data | Uploaded records, stored outside the web root. |
| /var/lib/cacti-topology/cache | SNMPSim index cache. |
| /var/lib/cacti-topology/status | Simulator state and reload markers. |
| /opt/snmpsim/venv | Existing shared executable environment on this VM. Separate services use separate ports and data. |
| /opt/cacti-topology-validation/venv | Optional encrypted SNMPv3 validation environment. |
| /etc/httpd/conf.d/cacti-topology.conf | Apache rules protecting private plugin files. |
| templates/submenu.php inside the plugin | Shared submenu definitions for the native Cacti UI. |

### Keep configuration in its existing owner

Cacti core owns Devices, Sites, SNMP access, device permissions, templates, data sources, graphs and polling. The plugin adds Device Categories, port profiles, category assignments, physical cables, layouts, discovery policy and simulator import history. It does not require a Node or Site setup step.

The current sidebar contains Setup Guide, Topology View, Discovery and Simulator. The separate inventory, Evidence page and simulator example tabs are removed. Private test fixtures remain available on disk for validation.

## 2 RHEL package prerequisites

Run commands in the RHEL terminal, not the Mac terminal, unless marked Mac. This guide assumes Cacti already opens successfully, its database is configured, and its native poller is working. Keep the existing PHP package stream; do not switch streams during a plugin installation. Cacti itself has additional installation requirements [1].

### Repositories

Use a registered RHEL 9 system with BaseOS and AppStream, or equivalent approved local repositories. The following repository commands apply to subscription-managed RHEL; an offline server should use section 7 instead.

```text
sudo subscription-manager identity
sudo subscription-manager repos \
  --enable="rhel-9-for-$(uname -m)-baseos-rpms" \
  --enable="rhel-9-for-$(uname -m)-appstream-rpms"
sudo dnf repolist
```

### Packages for an existing Cacti server

```text
sudo dnf install -y php-cli php-snmp php-process \
  net-snmp-utils python3 policycoreutils-python-utils \
  rsync tar gzip curl

# Required only when enabling the simulator
sudo dnf install -y python3.11 python3.11-pip

# Required only for the optional live SNMPv3 validation agents
sudo dnf install -y net-snmp
```

php-snmp supplies the required discovery transport. php-process supplies process support on RHEL; also confirm proc_open is not disabled by PHP configuration. net-snmp-utils supplies snmpget and snmpwalk. Installing net-snmp for testing does not require enabling a new permanent snmpd service.

### Check the existing Cacti stack

```text
cat /etc/os-release
uname -m
php --version
python3.11 --version
rpm -q httpd mariadb-server rrdtool php-fpm php-mysqlnd \
  php-snmp php-process net-snmp-utils
sudo php -r 'echo "SNMP=".(extension_loaded("snmp")?"yes":"no")." proc_open=".(function_exists("proc_open")?"yes":"no").PHP_EOL;'
sudo systemctl is-active httpd mariadb php-fpm
sudo systemctl restart php-fpm
sudo systemctl reload httpd
```

Expected: SNMP=yes, proc_open=yes, and active core services. If the site uses a different PHP service arrangement, apply changes through that installation’s service configuration. Core Cacti must also keep its PHP SNMP support enabled; this plugin has no alternate discovery transport.

### SELinux network access

```text
getenforce
sudo getsebool httpd_can_network_connect
sudo setsebool -P httpd_can_network_connect on
```

Keep SELinux Enforcing. This standard boolean allows Apache/PHP outbound network access, used by web SNMP checks. The installer applies narrow file labels to simulator data and status directories; do not use chmod 777 or disable SELinux.

## 3 Copy and enable the plugin

The current VM already has 0.12.1. The commands below describe deployment to the same Cacti path on another RHEL server or a controlled upgrade. Replace the SSH alias if different. The Cacti database is named cacti in this project.

### Copy from your Mac

```text
cd "/Users/saimakhan/Documents/ChatGPT/Cacti NMS Project/output"
shasum -a 256 -c Topology-Plugin-0.12.1.sha256
scp Topology-Plugin-0.12.1.tar.gz \
  Topology-Plugin-0.12.1.sha256 cacti-rhel9:/var/tmp/
```

### Back up an existing installation

On upgrades, stop the plugin timers and wait until their active workers finish. Do not stop or duplicate the native Cacti poller. Run the backup from a quiet maintenance window; uploads should be paused.

```text
sudo systemctl stop topology-discovery.timer \
  topology-simulator-import.timer topology-snmpsim-reload.timer
sudo systemctl status topology-discovery.service \
  topology-simulator-import.service --no-pager
sudo install -d -m 700 /var/backups/topology-before-0121
sudo sh -c 'umask 077; mariadb-dump --single-transaction cacti > /var/backups/topology-before-0121/cacti.sql'
sudo tar -czf /var/backups/topology-before-0121/plugin.tar.gz \
  -C /var/www/html/cacti/plugins topology
sudo tar -czf /var/backups/topology-before-0121/runtime.tar.gz \
  /etc/cacti-topology /var/lib/cacti-topology
```

Skip the upgrade-only stop and backup commands on a fresh plugin installation. The SQL backup command uses this VM’s local database administrator access; use your approved backup account on a different server. Keep backup files private because the Cacti database contains access settings.

### Extract and apply

```text
cd /var/tmp
sha256sum -c Topology-Plugin-0.12.1.sha256
mkdir -p /var/tmp/topology-0121-stage
tar -xzf Topology-Plugin-0.12.1.tar.gz \
  -C /var/tmp/topology-0121-stage
sudo rsync -a --exclude=simulator.local.php \
  /var/tmp/topology-0121-stage/topology/ \
  /var/www/html/cacti/plugins/topology/
sudo chown -R root:apache /var/www/html/cacti/plugins/topology
sudo find /var/www/html/cacti/plugins/topology -type d \
  -exec chmod 750 {} +
sudo find /var/www/html/cacti/plugins/topology -type f \
  -exec chmod 640 {} +
sudo restorecon -RF /var/www/html/cacti/plugins/topology
```

Open Cacti → Configuration → Plugins. Install and enable Topology; on an existing installation, run its upgrade if offered. Do not uninstall to upgrade. Confirm version 0.12.1. Continue with the service setup below before testing uploads.

## 4 Native permissions and template selection

### Operator permissions

In native Cacti User Management, allow the Topology Setup Guide, category management, physical port profiles, Topology View, Discovery and Simulator realms as required. The operator must also be allowed to see the target Cacti devices.

Simulator provisioning requires the native Devices, Device Templates, Data Templates, Graph Templates and Graph Management permissions. Assignment and physical topology changes require native device management permission. Changing the shared discovery policy requires visibility of every assigned device. A disabled or unauthorized job owner cannot collect in the background.

### Select actual IDs from this database

Do not assume IDs from another installation. The simulator needs an enabled local collector, a native SNMP Get data template with one data-source item and an OID input, a graph template referring to that item, and an interface query. The plugin validates these relationships before accepting an upload.

```text
sudo mariadb cacti -e "SELECT id,hostname,disabled FROM poller;"
sudo mariadb cacti -e "SELECT id,name FROM data_template WHERE name LIKE '%Generic OID%';"
sudo mariadb cacti -e "SELECT id,name FROM graph_templates WHERE name LIKE '%Generic OID%';"
sudo mariadb cacti -e "SELECT id,name FROM snmp_query WHERE name LIKE '%Interface%';"
```

| Setting | Verified current VM value |
| --- | --- |
| Cacti path | /var/www/html/cacti |
| Web and worker user | apache |
| Local poller ID | 1 |
| Data template ID | 289 — SNMP - Generic OID Template |
| Graph template ID | 225 — SNMP - Generic OID Template |
| Interface query ID | 16 — SNMP - Interface Statistics |
| Simulator address and port | 127.0.0.1 UDP 1162 inside the VM |
| Existing simulator executable | /opt/snmpsim/venv/bin/snmpsim-command-responder |

### Create the Python environment on a new server

The current VM already has the executable above; reuse it without reinstalling its packages. For a fresh server, use a separate environment at /opt/cacti-topology-snmpsim/venv and the release’s pinned requirements [3]. Never copy a Mac virtual environment or an ARM64 virtual environment to an x86_64 server.

```text
sudo python3.11 -m venv /opt/cacti-topology-snmpsim/venv
sudo /opt/cacti-topology-snmpsim/venv/bin/python -m pip install \
  -r /var/www/html/cacti/plugins/topology/simulator/requirements.txt
sudo /opt/cacti-topology-snmpsim/venv/bin/python -m pip check
sudo /opt/cacti-topology-snmpsim/venv/bin/snmpsim-command-responder \
  --help
```

These production pins provide the SNMPv2c import responder. The optional encrypted SNMPv3 test uses its separate validation requirements and environment in section 12.

## 5 Configure the background services

### Simulator installer

Check the port before a fresh installation. Existing output on UDP 1162 is expected when the current topology simulator is running; do not start a second listener on that port.

```text
sudo ss -lunp | grep -E ':(1161|1162|1163|1164)\b'
```

For a new server, select the IDs found in section 4. The following example uses this project’s verified IDs and the NEW dedicated Python environment. On the existing VM, the executable argument must remain /opt/snmpsim/venv/bin/snmpsim-command-responder. The installer refuses a configuration that differs from the saved one.

```text
sudo python3 /var/www/html/cacti/plugins/topology/simulator/install.py \
  --cacti-path /var/www/html/cacti \
  --executable /opt/cacti-topology-snmpsim/venv/bin/snmpsim-command-responder \
  --port 1162 --poller-id 1 \
  --data-template-id 289 --graph-template-id 225 \
  --interface-query-id 16 --web-user apache
```

The installer creates topology-sim, data/cache/status directories, the local configuration, a loopback listener, its reload timer and the automatic import worker. It also writes Apache private-directory rules and SELinux labels. Release 0.12.1 creates /usr/local/libexec if missing on a clean server.

### Discovery installer

```text
sudo python3 \
  /var/www/html/cacti/plugins/topology/simulator/install_discovery.py \
  --cacti-path /var/www/html/cacti --web-user apache
```

For an existing simulator installation that predates automatic provisioning, install its worker explicitly:

```text
sudo python3 \
  /var/www/html/cacti/plugins/topology/simulator/install_provision.py \
  --cacti-path /var/www/html/cacti --web-user apache
```

### Finish permissions and restart stopped timers

```text
sudo chown root:apache \
  /var/www/html/cacti/plugins/topology/simulator.local.php
sudo chmod 640 \
  /var/www/html/cacti/plugins/topology/simulator.local.php
sudo restorecon -RF /var/www/html/cacti/plugins/topology
sudo systemctl daemon-reload
sudo systemctl enable --now topology-snmpsim.service \
  topology-snmpsim-reload.timer topology-simulator-import.timer \
  topology-discovery.timer
```

The import worker checks about every 15 seconds and retries activation failures up to five times. The reload timer checks every 10 seconds. Discovery checks queued work every 15 seconds; the collection interval itself is configured in the Discovery UI. The native Cacti poller separately creates and updates RRD files.

No inbound UDP 1162 firewall rule is needed: the simulator is deliberately bound to VM loopback. The Mac URL port 8080 is an existing VM web forward; it is not the simulator port. Real-device polling requires a route and permitted SNMP traffic from the VM to each device.

## 6 Verify the running installation

### Service health

```text
sudo systemctl is-active httpd php-fpm mariadb \
  topology-snmpsim.service topology-snmpsim-reload.timer \
  topology-simulator-import.timer topology-discovery.timer
sudo systemctl list-timers --all 'topology*'
sudo systemctl status cacti-poller.timer --no-pager
sudo systemctl cat topology-discovery.service
sudo ss -lunp | grep ':1162'
sudo cat /var/lib/cacti-topology/status/simulator.state
getenforce
```

Expected: listener and timers active, UDP 1162 owned by the dedicated simulator, and SELinux Enforcing. The queue services are Type=oneshot: inactive between successful runs is normal. cacti-poller.timer is this VM’s native scheduler; other Cacti installations may use cron instead. Do not install a second native poller schedule.

### Apache private file protection

Run from inside the VM. The project serves /cacti on HTTP port 80 there. Each probe below should return 403. A login page, PHP output or a 200 response does not satisfy this check. The installer provides server-level rules because RHEL commonly ignores .htaccess overrides.

```text
sudo httpd -t
for path in simulator.local.php includes/model.php \
  tests/fixtures/qa-mixed-oids.snmprec simulator/service.py \
  templates/submenu.php; do
  curl -s -o /dev/null -w "%{http_code} $path\n" \
    "http://127.0.0.1/cacti/plugins/topology/$path"
done
```

### Live SNMP confirmation after an import

Active service state alone is not a successful SNMP test. After importing the numeric QA record in section 8, run this command in the VM. The community is a disposable loopback lab value, not a production credential.

```text
snmpget -v2c -c topology-upload-qa-0110 -On \
  udp:127.0.0.1:1162 \
  1.3.6.1.2.1.1.5.0 1.3.6.1.2.1.1.3.0
```

Expected: sysName topology-upload-qa-0110 and TimeTicks 123456. For an existing import, the Simulator row’s Test SNMP button checks the live identity with its configured community.

### Log locations

```text
sudo journalctl -u topology-snmpsim.service -n 50 --no-pager
sudo journalctl -u topology-simulator-import.service -n 50 --no-pager
sudo journalctl -u topology-discovery.service -n 50 --no-pager
sudo tail -n 50 /var/log/httpd/error_log
sudo ausearch -m AVC -ts recent
```

Check actual errors before changing permissions or packages. An unavailable required MIB must be reported as a failed collection; it must not be replaced with guessed neighbor information.

## 7 Offline RHEL package preparation

Package installation needs an accessible repository or a prepared offline bundle. Runtime polling and the simulator do not require internet. Prepare RPMs and Python wheels on a connected RHEL 9 machine with the same CPU architecture, compatible repositories and Python version as the target. This plugin archive does not contain RPMs or Python wheels.

### On a connected matching RHEL machine

```text
sudo dnf install -y dnf-plugins-core python3.11 python3.11-pip
mkdir -p "$HOME/topology-offline/rpms" "$HOME/topology-offline/wheels"
sudo dnf download --resolve --alldeps \
  --destdir="$HOME/topology-offline/rpms" \
  php-cli php-snmp php-process net-snmp-utils net-snmp \
  python3 python3.11 python3.11-pip policycoreutils-python-utils \
  rsync tar gzip curl
python3.11 -m venv "$HOME/topology-wheel-build"
"$HOME/topology-wheel-build/bin/python" -m pip download \
  --only-binary=:all: --dest "$HOME/topology-offline/wheels" \
  -r /var/tmp/topology-0121-stage/topology/simulator/requirements.txt
"$HOME/topology-wheel-build/bin/python" -m pip download \
  --only-binary=:all: --dest "$HOME/topology-offline/wheels" \
  -r /var/tmp/topology-0121-stage/topology/simulator/validation-requirements.txt
tar -czf "$HOME/topology-offline.tar.gz" \
  -C "$HOME" topology-offline
```

Extract the plugin archive to the stage path first, or adjust the requirements paths. The second download is optional and adds the encrypted test environment dependencies. The command deliberately fails if a wheel is unavailable; prepare and test a matching wheel separately instead of attempting internet access from the offline target.

### On the offline RHEL target

```text
tar -xzf /var/tmp/topology-offline.tar.gz -C /var/tmp
sudo dnf --disablerepo="*" install -y \
  /var/tmp/topology-offline/rpms/*.rpm
sudo python3.11 -m venv /opt/cacti-topology-snmpsim/venv
sudo /opt/cacti-topology-snmpsim/venv/bin/python -m pip install \
  --no-index --find-links=/var/tmp/topology-offline/wheels \
  -r /var/www/html/cacti/plugins/topology/simulator/requirements.txt
sudo /opt/cacti-topology-snmpsim/venv/bin/python -m pip check
```

Use your approved media transfer process for the plugin and offline bundle. Keep package signatures enabled. A bundle prepared against a different PHP stream or architecture can fail dependency resolution; validate it on a matching test VM before deployment. Continue with sections 4–6 after packages are installed.

For the optional validation environment, use the same --no-index and --find-links arguments with its validation-requirements.txt file in section 12.

## 8 Example simulator uploads

Use the existing Switch A and Switch B on your current VM for LLDP checks. They already advertise identities LAB-SW-A and LAB-SW-B. Do not upload the dual-protocol fixtures alongside them: those fixtures advertise the same identities. Use a separate fresh lab installation for the full LLDP and CDP example below.

### Prepare a new lab

1. Open Setup Guide → Device Categories. Use the single header + to create Network, select a blue topology color, and save.

2. Open Simulator → Upload SNMP Record. Enter the first row below, choose Network, select the fixture from your Mac, and Save. Repeat for the second and third rows.

3. Return to Imports. Wait for Pending to become Provisioned; allow several worker cycles. Use Test SNMP on each row. Open Cacti Device to inspect the generated native host.

| Device name | Lab community | Fixture and expected count |
| --- | --- | --- |
| QA Switch A | qa-switch-a | switch-a-dual.snmprec<br>57 records / 24 metrics |
| QA Switch B | qa-switch-b | switch-b-dual.snmprec<br>57 records / 24 metrics |
| Topology Upload QA 0.11.0 | topology-upload-qa-0110 | qa-mixed-oids.snmprec<br>11 records / 5 metrics |

The switch files are under plugins/topology/tests/fixtures/protocols/ in your local project. The numeric file is under plugins/topology/tests/fixtures/. They are test data on disk, not restored simulator example tabs. A comment names each numeric reading; the numeric OID and ASN.1 type determine its data source.

### Complete numeric QA file

The bundled file has comments for labels. The following equivalent record body shows all 11 OIDs and their types:

```text
1.3.6.1.2.1.1.3.0|67|123456
1.3.6.1.2.1.1.5.0|4|topology-upload-qa-0110
1.3.6.1.4.1.8072.9999.1.0|2|-12
1.3.6.1.4.1.8072.9999.2.0|65|345678
1.3.6.1.4.1.8072.9999.3.0|66|77
1.3.6.1.4.1.8072.9999.4.0|70|12345678901
1.3.6.1.4.1.8072.9999.5.0|4|not-a-metric
1.3.6.1.4.1.8072.9999.6.0|6|1.3.6.1.4.1.8072
1.3.6.1.4.1.8072.9999.7.0|64|192.0.2.10
1.0.8802.1.1.2.1.3.7.1.2.1|2|5
1.3.6.1.4.1.9.9.23.1.2.1.1.3.1.1|2|1
```

Use the bundled file unchanged if you will run the automatic QA script. On the current VM it is already import 3 / Cacti device 11. Uploading the same content or community again must be rejected; use its existing entry.

Upload limits: .snmprec only, at most 2 MB, 5000 lines and 64 graphable metrics. A nonempty text sysName.0 and TimeTicks sysUpTime.0 are required. Executable variation tags are rejected. The numeric-only QA device has no interface table and should use discovery protocol None.

## 9 Categories ports and topology example

### Create and apply a port profile

1. Open Setup Guide → Port Profiles and use +. Choose Device Category Network; name the profile QA 2 Port Switch; enter prefix Ethernet, first number 1, count 2 and connector RJ45. Save.

2. Open Assign Devices. Filter category Network, choose QA 2 Port Switch, select QA Switch A and QA Switch B, then Apply to Selected Devices. Do not apply this switch profile to the numeric-only QA device.

3. Open Topology View. Both assigned switch devices should show Ethernet1 and Ethernet2. Each device keeps its native Cacti status and a Simulated label.

4. Use Configure ports on each switch. Map Ethernet1 to its cached Ethernet1 interface, ifIndex 1; map Ethernet2 to ifIndex 2. If no interfaces are offered, open the native Cacti device and refresh its SNMP Interface Statistics data query first.

Port numbers are labels. Port 1 is not automatically ifIndex 1 on a real device. The mapping must come from that device’s actual Cacti interface cache. A category may have multiple model-specific profiles. Profiles currently describe one sequential bank, one connector label and up to 128 ports; editing a profile does not silently change previously generated ports.

### Arrange and connect

1. Drag the device headers apart. Select Save Layout, reload, and confirm positions are retained.

2. Drag Switch A Ethernet1 onto Switch B Ethernet1. A blue Configured cable should appear and remain after reload. Connecting a cable saves it immediately.

3. Connect Ethernet2 to Ethernet2. Each port allows only one cable. Same-device or already occupied endpoints must be rejected.

4. Use minus and plus to zoom, then Fit to Screen. Dragging and connecting must still target the correct ports after zooming.

5. Select the second cable or a connected port and use Disconnect. Confirm only that cable is removed. Reconnect it if restoring the two-cable lab baseline.

6. Change a category’s topology color and reload the view. Filter the category and confirm the correct devices remain visible.

| Display | Meaning |
| --- | --- |
| Category stripe and tint | The saved Device Category color. |
| Green, red, amber or gray device badge | Latest native Cacti status: Up, Down/Error, Recovering or Unknown/Disabled. |
| Purple Simulated label | Device came from a simulator import. |
| Blue Configured cable | Operator-defined physical connection. It does not prove an actual live Ethernet cable. |
| Available or selected port styling | Availability or current selection in the editor, not measured traffic. |

The configured canvas is distinct from LLDP/CDP discovery. Drawing a cable does not change switch hardware or enable LLDP. In this release the removed Evidence page is not replaced by an automatic live-neighbor overlay on the configured canvas.

## 10 Discovery profiles and protocol checks

### Configure the four tabs

1. In Saved Profiles, use + to create QA Both 5 Minutes. Choose LLDP + CDP, Scheduled, interval 300 seconds, stale after 900 seconds, and Active. Save the profile.

2. In Collection Settings, select the profile and Load Profile. Leave Use profile protocol for all devices unchecked when the numeric-only QA device is also assigned. Loading fills the form; Save is required to apply it.

3. In Device Protocols, select LLDP + CDP for the two dual-fixture switches and None for the numeric-only QA device. Use Save & Discover Now.

4. In Run & Results, confirm the queued job finishes. Two dual-protocol switches should produce four successful protocol collections and zero failures. A job reports protocol collections, not a count of cables.

5. Select LLDP only for the switches and save/run again: expect two successful collections. Repeat CDP only: expect two. This CDP check requires the dual or CDP fixture data, not the existing LLDP-only records.

6. Return to the intended protocol choice and save. Check that the scheduled policy queues a subsequent job after its configured interval.

### Check tab and pagination behavior

Change an interval field without saving, switch to Device Protocols and back, and confirm the value remains. Restore the saved value before leaving. Save from a configuration tab should keep that tab selected; Save & Discover Now should open Results. Discover Now in Results uses saved settings.

Use Results page 2 and a different Rows count when enough jobs exist. Counts and Previous/Next controls should match Cacti core. A list with only three entries correctly has no second page; do not create production records just to force pagination.

### Inspect collection outcomes without restoring the Evidence page

```text
sudo mariadb cacti -e "SELECT id,state,message FROM plugin_topology_jobs ORDER BY id DESC LIMIT 5;"
sudo mariadb cacti -e "SELECT host_id,protocol,status,attempted_at,succeeded_at,error FROM plugin_topology_snapshots ORDER BY host_id,protocol;"
```

Expected snapshots are success for each selected supported protocol. Old snapshots from a previously selected protocol may remain as history. The read-only queries expose collection status; the private reconciliation tests validate reciprocal-neighbor matching.

### Runtime boundaries

Supported discovery settings: 60–86400 second collection interval; stale threshold at least twice that interval and at most 604800 seconds. The local collector currently limits discovery to 32 visible assigned devices, 5000 returned objects and 30 seconds per protocol, with a 180-second job budget. Native timeout must be 1–5000 ms and retries 0–3. A missing required MIB, disabled device or wrong collector assignment produces an explicit failure.

## 11 Verify automatic native data sources

For the numeric QA upload, provisioned means the worker has created the native Cacti objects after checking the simulator’s live sysName. It does not mean that every RRD file already contains graph history. The native Cacti poller creates and updates the RRD files on its own schedule.

| OID or field | Record type | Expected native result |
| --- | --- | --- |
| sysUpTime.0 | 67 TimeTicks | GAUGE with raw value 123456 |
| 8072.9999.1.0 | 2 Integer32 | GAUGE, signed value -12 allowed |
| 8072.9999.2.0 | 65 Counter32 | COUNTER; input value 345678 |
| 8072.9999.3.0 | 66 Gauge32 | GAUGE; value 77 |
| 8072.9999.4.0 | 70 Counter64 | COUNTER; input value 12345678901 |
| Text, OID and address values | 4, 6 and 64 | No data source or graph |
| LLDP and CDP identifier subtrees | Numeric identifier types | Excluded from performance metrics |

### Check in Cacti core

1. Open Management → Devices and select Topology Upload QA 0.11.0. Confirm its loopback address, UDP 1162, SNMPv2c and local collector. Site is initially Unassigned and is edited only in Cacti core.

2. Open Data Sources and filter by this device. Confirm exactly five data sources. Inspect each OID field and data-source type against the table above.

3. Open Graph Management and filter by the same device. Confirm five graphs. Wait for at least two successful native polling cycles before judging rate graphs.

4. Open Simulator and use Test SNMP again. Confirm the record is still Provisioned and no duplicate device or data sources were created.

Counter fixtures are static, so their rate graphs can show zero after enough samples. TimeTicks is stored as the raw number in this generic mapping, not converted to seconds. Automatic metric creation is driven by numeric ASN.1 type and exclusion rules; it does not infer device-specific units or semantics.

### Repeatable QA on the supplied numeric fixture

The current VM already contains the expected QA import. Run verify only. On a fresh lab, stage is an optional CLI alternative to the UI upload; it selects the first active category, so create Network first. The verify command checks exact OIDs, native types, five graphs, idempotent provisioning and duplicate-record rejection.

```text
# Fresh lab only if the QA file has not been uploaded through the UI
sudo php /var/www/html/cacti/plugins/topology/tests/simulator_auto_qa.php \
  --cacti-root=/var/www/html/cacti --mode=stage

# After the import worker has provisioned the record
sudo php /var/www/html/cacti/plugins/topology/tests/simulator_auto_qa.php \
  --cacti-root=/var/www/html/cacti --mode=verify
```

Expected: all PASS lines and a QA HOST ID. On your VM that ID is 11; IDs on another installation will differ. The verification entry point is intended for this named lab fixture, not arbitrary production uploads.

## 12 SNMPv3 and real device validation

### Optional encrypted local validation

Install the optional net-snmp package from section 2. The simulator installer must already have created topology-sim. Confirm loopback UDP 1163 and 1164 are free. Use the explicitly identified existing native loopback device as the baseline; it is Cacti device 2 on this VM. Do not copy that ID to another database without checking.

```text
sudo python3.11 -m venv /opt/cacti-topology-validation/venv
sudo /opt/cacti-topology-validation/venv/bin/python -m pip install \
  -r /var/www/html/cacti/plugins/topology/simulator/validation-requirements.txt
sudo /opt/cacti-topology-validation/venv/bin/python -m pip check
sudo python3 \
  /var/www/html/cacti/plugins/topology/tests/run_local_validation.py \
  --cacti-root=/var/www/html/cacti --host-id=2
```

The runner starts temporary Net-SNMP and SNMPSim agents with generated test keys, runs as the Cacti worker account, then removes the agents and temporary profiles. It does not change the native device’s saved credentials. It needs a working loopback Cacti device with readable system and IF-MIB objects.

Expected: SHA/AES and SHA256/AES authPriv succeed; incorrect keys, names and contexts fail without fallback. The dual-protocol simulator returns one reciprocal link with four observations. A local Linux agent without LLDP/CDP MIBs must fail topology collection explicitly. These checks passed on the current VM after fixing the retired fixture path in 0.12.1.

### Example physical network without internet

Example: PC Ethernet connects to Switch A port 1, and Switch A port 24 connects to Switch B port 24. The Cacti VM reaches both switches through their management network. LLDP advertisements travel over the local Ethernet links; internet access is not required. Cacti queries the switches’ neighbor tables over SNMP [4].

1. Enable LLDP transmit and receive on the relevant physical switch interfaces using the device vendor’s configuration. For CDP, both devices must support CDP and expose its MIB. Exact device commands depend on vendor and model.

2. Add each switch under Cacti Management → Devices. Configure its reachable management address and read-only SNMP settings there, including version, authentication, privacy and context when using v3.

3. Allow the VM’s management address through the device’s SNMP access controls. The SNMP view must expose system uptime, IF-MIB, required IF-X-MIB identifiers and the selected LLDP-MIB or CISCO-CDP-MIB.

4. Assign the Cacti devices to a Device Category and port profile. Map actual interfaces, then select the protocol in Discovery and run it. Confirm switch neighbor tables and collected results agree.

A PC Ethernet link can be Up while the PC sends no LLDP. To identify that PC through LLDP, it needs an LLDP-capable agent configured to advertise; the neighboring switch must receive those advertisements. The VM does not need to receive raw LLDP if it can poll switch tables. NAT or bridged VM networking must provide SNMP management reachability; plugging a host cable in alone does not guarantee it.

Native authoritative engine ID must be empty for the plugin’s current supported v3 setup. A nonempty value is rejected. A context engine ID is a different SNMP concept [5]. Physical switch firmware and all possible SNMP algorithms remain separate acceptance tests.

## 13 Functional acceptance checklist

Run the following in a disposable lab or with the existing lab devices. Expected results describe acceptance criteria; they are not a claim that every physical device or every OS has been tested. Preserve existing user configuration when testing errors.

| Function | Action | Pass condition |
| --- | --- | --- |
| Install and permissions | Enable plugin; sign in as permitted operator. | Four sidebar entries; native Cacti UI; intended devices visible. |
| Category | Create or edit category and color. | Saved name/color persists and updates canvas category styling. |
| Port profile | Create Ethernet1–2 RJ45 profile. | Correct count, labels and category; no fixed device model required. |
| Bulk assignment | Select two native Cacti devices and apply profile. | Both receive expected ports; no duplicate native devices. |
| Interface mapping | Map ports from native interface query. | Selected cached ifIndex persists; invalid mapping is rejected. |
| Canvas layout | Drag headers, save, reload. | Positions remain. Arrange Devices also requires layout save. |
| Canvas connections | Connect, reload, disconnect one cable. | Immediate persistence; unrelated cables preserved. |
| Port constraints | Try occupied port or same-device cable. | Rejected without creating an invalid connection. |
| Canvas controls | Zoom out/in, fit, drag and connect. | Correct scaling and port targeting; category filter works. |
| Simulator upload | Upload complete valid fixture. | Pending becomes Provisioned; Test SNMP succeeds. |
| OID provisioning | Inspect numeric QA device. | 5 data sources and 5 graphs with exact OIDs and types. |
| Duplicate import | Upload same record or community again. | Rejected; no second host or metric set. |
| Invalid upload | Use duplicate OID, bad tag or omit sysName. | Validation error; no new Cacti objects. |
| Activation retry | Use header retry for a retained pending import. | Only dedicated topology responder reloads; no NMS restart. |
| Profiles | Save profile, load it, then save settings. | Load alone does not persist policy; saved values are used. |
| Discovery protocols | Test LLDP, CDP and both on dual fixtures. | 2, 2 and 4 successful protocol collections respectively. |
| Scheduling | Save Scheduled policy with 300-second interval. | New job appears when due; worker respects owner permissions. |
| Tabs and lists | Switch tabs, paginate and change Rows. | Only relevant section visible; unsaved configuration retained. |
| Access restrictions | Use a separately configured read-only lab account. | Hidden devices stay hidden; unauthorized writes are rejected. |
| SNMPv3 errors | Run the isolated validation command. | No fallback, credential rewrite or fabricated success. |
| Native graphs | Wait for normal polling cycles. | RRDs update; static counter rates can be zero. |
| Persistence | Reload browser; inspect workers after reboot in lab. | Saved topology remains; enabled timers and listener return. |

One-time parser and transport checks are listed on the next page. Destructive database regression suites should run only in their explicitly named disposable clones, never against the working Cacti database.

## 14 Diagnostics and maintenance

### Read only parser and transport checks

```text
sudo php /var/www/html/cacti/plugins/topology/tests/records_test.php
sudo php /var/www/html/cacti/plugins/topology/tests/neighbors_test.php
sudo php /var/www/html/cacti/plugins/topology/tests/security_test.php
sudo php /var/www/html/cacti/plugins/topology/tests/discovery_transport_test.php
```

| Symptom | Check and corrective action |
| --- | --- |
| Simulator configuration missing | Run the explicit installer and inspect simulator.local.php ownership. Do not create credentials in plugin code. |
| Import stays Pending | Inspect listener, reload timer and import timer logs. Check data directory labels and native template linkage. |
| Import is Failed | Read its error. Fix the cause, then use Retry Provisioning. Automatic attempts stop after five failures. |
| SNMP identity mismatch | Check uploaded sysName, community, port and active record. No device should be provisioned from a mismatched response. |
| Discovery fails required objects | Enable correct device protocol and grant required MIB reads. SNMP access to sysName alone is insufficient. |
| Discovery job remains queued | Check topology-discovery.timer, PHP CLI SNMP/proc_open and policy owner permissions. |
| No interface choices | Refresh the native interface data query; check IF-MIB access and the selected query ID. |
| Graphs empty or counters zero | Check Cacti native polling and allow two samples. Static counters may legitimately yield zero rates. |
| Port profile cannot be reduced | Disconnect affected cables first. Existing cable integrity takes precedence over removing ports. |
| Configuration changed error | Reload the form after another operator changes assignments or layout; resubmit the intended edit. |

### Disable without deleting history

Disable the plugin through native Plugin Management, then disable its dedicated units. Confirm no active queue worker is still running. Disable the native lab devices separately if their normal Cacti polling should stop.

```text
sudo systemctl disable --now topology-discovery.timer \
  topology-simulator-import.timer topology-snmpsim-reload.timer \
  topology-snmpsim.service
sudo systemctl status topology-discovery.service \
  topology-simulator-import.service --no-pager
```

Plugin uninstall preserves its metadata and native graph history. Do not delete native devices, templates or RRDs merely to remove a menu. Before rollback, stop writers, disable the plugin, restore compatible plugin code and local configuration, and validate the schema. Restoring a full old database also overwrites newer unrelated Cacti work and is not the default code rollback method.

## 15 Verification record and references

### Checks completed on your VM

| Check | Observed result on 7 September 2026 |
| --- | --- |
| OS and services | RHEL 9.8 aarch64; SELinux Enforcing; core services, simulator listener and all three topology timers active. |
| Record parser and neighbor logic | Passed validation, counter ranges, canonical records, duplicate identity handling, reciprocal links, expiry and incomplete-table rejection. |
| Security and transport | Passed explicit SNMP security validation, timeout, object limit and no partial table publication. |
| Automatic metric provisioning | Retained QA import 3 / device 11: live identity matched; 5 exact OID data sources, 5 native graphs, no duplicates on retry. |
| Live local SNMPv3 | SHA/AES and SHA256/AES succeeded. Incorrect credentials and contexts failed; native device configuration was unchanged. |
| Simulated v3 topology | Named contexts returned both LLDP and CDP neighbors; one reciprocal link retained four observations. |
| Discovery UI and jobs | Four native tabs verified; unsaved settings retained; core pagination worked; job 55 completed two collections with zero failures. |
| Physical hardware and other OSes | Not certified by these tests. Run a physical lab and the compatibility acceptance checklist before relying on those environments. |

### Changes included with this guide

Release 0.12.1 corrects the optional v3 runner to use tests/fixtures/protocols after removal of the public example pages. The simulator installer now creates /usr/local/libexec when absent. Production responder version pins are supplied in simulator/requirements.txt. Existing device, SNMP, port and cable configuration is preserved. The preceding installed code was backed up at /var/backups/topology-0121/plugin-before.tar.gz.

The upload form and numeric provisioning workflow were validated previously using multipart validation plus the automatic worker. The current guide preparation reran the retained fixture’s provisioning verification and the local encrypted SNMP tests; it did not re-upload or duplicate your working lab devices.

### Sources and further reading

[1] Cacti requirements and installation. Use the documentation for your installed Cacti version when preparing the underlying server.
https://docs.cacti.net/Requirements.md
https://docs.cacti.net/General-Installing-Instructions.md

[2] Red Hat Enterprise Linux 9 Python installation and package naming.
https://docs.redhat.com/en/documentation/red_hat_enterprise_linux/9/html/installing_and_using_dynamic_programming_languages/assembly_introduction-to-python_installing-and-using-dynamic-programming-languages

[3] SNMP Simulator quick start and responder invocation.
https://docs.lextudio.com/snmpsim/quick-start

[4] Cisco LLDP configuration overview. The device model determines its exact commands.
https://www.cisco.com/c/en/us/td/docs/switches/lan/catalyst9300/software/release/17-12/configuration_guide/int_hw/b_1712_int_and_hw_9300_cg/configuring_lldp__lldp_med__and_wired_location_service.html

[5] PHP SNMP security API and Net-SNMP agent configuration.
https://www.php.net/manual/en/snmp.setsecurity.php
https://www.net-snmp.org/docs/man/snmpd.conf.html

Plugin-specific statements were checked against the 0.12.1 source, installation scripts, private fixtures and the running cacti-rhel9 VM. Current operator instructions are in docs/SETUP-GUIDE.md and this guide; older phase documents describe earlier workflows.
