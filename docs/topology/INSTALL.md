# Installation and compatibility for 0.12.1

Use [the current RHEL installation and validation manual](RHEL-INSTALL-AND-VALIDATION.md) for package commands, online/offline installation, native template selection, systemd workers, SELinux, lab uploads, OID verification and functional acceptance tests.

The verified runtime is RHEL 9.8 aarch64 with Cacti 1.2.31, PHP 8.0.30, MariaDB 10.5.29, Apache and systemd. The production responder runs SNMPSim 1.2.2 under Python 3.11. Installers use Linux and RHEL-specific paths and are not portable installers for macOS, Windows or Debian. Other architecture/runtime combinations require acceptance testing. Browser clients can access the RHEL web UI from other operating systems.

Cacti must already be installed and polling. Do not duplicate its Sites, Devices, SNMP access or polling schedule. Back up plugin/runtime data and the database before upgrades; retain simulator.local.php. Enable or upgrade Topology through native Plugin Management.

The current VM is already configured at /var/www/html/cacti/plugins/topology. Its existing responder executable is /opt/snmpsim/venv/bin/snmpsim-command-responder and must be retained when rerunning the installer. A fresh server can use the separate path documented in the manual. Native template IDs are database-specific.

The optional SNMPv3 runner now uses private tests/fixtures/protocols. No public example or Evidence page is restored. Older phase documents describe historical setup and must not override the current manual.
