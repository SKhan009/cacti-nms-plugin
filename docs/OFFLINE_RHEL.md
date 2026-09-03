# Offline RHEL package — NMS 1.9.33

This package is for an existing, working Cacti 1.2.31+ installation on RHEL.
The PHP code supports PHP 8.0.30, as shown in your server log.

## No internet installation or runtime dependency

- CSS, JavaScript, images and sample download files are inside the package.
- Fonts use the system font stack, not an internet font service.
- No CDN, cloud API, telemetry endpoint or automatic updater is used by NMS.
- No npm, Composer, pip, wget, online dnf/yum, or build step is needed to install NMS.
- Topology saves go to the same Cacti web origin. Cross-origin fetch is rejected.
- Device names, statuses, templates, graphs and sites come from your Cacti database.
- Cacti/SNMP requests still need access to the configured devices on your private
  network. No internet is required, but “offline” does not mean disconnecting the LAN.
- Documentation links and INFO's homepage are reference text. They are not loaded
  as application resources and do not need to be opened on the RHEL server.

The package contains plugin code, not a replacement operating system, Cacti,
MariaDB, PHP, Apache or RRDtool installation. It reuses those existing services and
Cacti's installed libraries. Any missing OS prerequisites must be supplied through
your approved matching RHEL installation media or offline RPM repository, not
downloaded by this plugin. No security controls are disabled.

## Install from USB

1. Transfer `NMS-Plugin-1.9.33-RHEL-OFFLINE.tar.gz` to
   `/home/bstc/Downloads/` on RHEL.
2. Follow [the included RHEL repair instructions](RHEL_MENU_REPAIR.md). They use
   local tar extraction, a backup, safe permissions and Cacti's installed CLI.
   There are no internet commands in that procedure.
3. After installation, access Cacti over the local network and sign in. NMS can
   show real monitored devices and their configured topology without internet.

Do not uninstall the previous plugin. Back up the Cacti database before migrations.
The file backup in the instructions is separate from the database backup.

## Optional SNMPSim is separate

SNMPSim is only needed to simulate devices and deploy imported `.snmprec` records.
It is not required for the NMS menu, normal real-device monitoring or topology.
This package contains simulator helper scripts and example records, **not** a
Python interpreter or the SNMPSim/PySNMP dependency wheels.

If your simulator is already installed, retain it and its existing service and
root-managed `/etc/cacti-nms/snmpsim.json`. No reinstall/download is triggered.
If it is missing, the simulator feature reports the missing configuration; it
does not download software or substitute sample readings for live data.

For a new simulator installation, prepare a separate offline dependency bundle on
a connected staging machine with matching RHEL architecture and Python version.
Transfer the approved RPMs and complete wheel set by USB. Only use pip with
`--no-index` and an explicitly supplied local wheel directory on offline RHEL.
Do not run `pip install -r snmpsim/requirements.txt` by itself; that would try the
internet. An exact simulator bundle requires the target RHEL release, architecture
and installed Python version and is not represented as included here.

## Verification

The package includes `tests/offline_assets_test.php` and
`tests/plugin_navigation_test.php`. These standalone PHP checks need no network or
database. The offline test renders each shared module shell, checks its linked
assets exist locally, scans CSS for external resources and verifies the same-origin
topology request constraint. These tests do not replace a live RHEL/SELinux check.

After deployment, open browser Developer Tools → Network and reload NMS with the
internet connection unavailable but the Cacti LAN connection working. CSS, scripts,
images and topology saves should target your Cacti server only. Check that a
topology drag/save persists after refresh. Existing Cacti core settings or other
plugins can have their own network behavior; NMS does not change those settings.
