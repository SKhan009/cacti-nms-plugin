# RHEL administrator commands for ICCT NMS

## Choose the host and Cacti path

Run collector commands on the RHEL host that runs Cacti. The RPM installation commonly uses `/usr/share/cacti`. Our RHEL VM uses `/var/www/html/cacti`. Set these variables once in the shell where you will run the commands.

```bash
CACTI_ROOT=/usr/share/cacti
PLUGIN="$CACTI_ROOT/plugins/icct_nms"
WEB_USER=apache
SYSLOG_DIR="$PLUGIN/protocols/syslog/rhel"
cat /etc/redhat-release
uname -m
sudo test -f "$CACTI_ROOT/include/cli_check.php" && echo "Cacti root found"
```

## Install or upgrade offline

**Complete installer with RPMs.** Extract the entire ZIP outside the web root and open a shell in the directory containing `install.py`. Use a release matching the target OS and architecture. The RHEL 9.8 ARM64 archive targets `aarch64`. The plan verifies the release and its local media. Review the plan before running install.

```bash
sudo find icct_nms/installation/bundles/media -name bundle.json -print
sudo /usr/bin/python3 install.py --cacti-root "$CACTI_ROOT" --web-user "$WEB_USER" --plan
sudo /usr/bin/python3 install.py --cacti-root "$CACTI_ROOT" --web-user "$WEB_USER"
```

**Source-only release with prerequisites already installed.** From the extracted ZIP use the following alternative. It provisions services, including the Syslog port runner, using the installed tools.

```bash
sudo /usr/bin/python3 install.py --cacti-root "$CACTI_ROOT" --web-user "$WEB_USER" --no-packages --plan
sudo /usr/bin/python3 install.py --cacti-root "$CACTI_ROOT" --web-user "$WEB_USER" --no-packages
```

**Plugin files already deployed.** Use one applicable setup command. The first uses installed dependencies. The second uses a verified base bundle mounted at the example path. Save the installation check after setup.

```bash
sudo /usr/bin/python3 "$PLUGIN/installation/cli/setup.py" install --cacti-root "$CACTI_ROOT" --web-user "$WEB_USER" --no-packages
# Alternative with a separate local RPM bundle
sudo /usr/bin/python3 "$PLUGIN/installation/cli/setup.py" install --cacti-root "$CACTI_ROOT" --web-user "$WEB_USER" --bundle /media/icct-packages
sudo php "$PLUGIN/installation/cli/check.php" --save
```

## Syslog receiver, port editing and local logs

On an existing collector, the receiver installer sets up rsyslog, the importer and the offline port runner. Use **Presets → Protocols → Syslog → Receiver port → Save** for subsequent port changes. Device senders must use the chosen port.

```bash
sudo env WEB_USER="$WEB_USER" bash "$SYSLOG_DIR/install_syslog_rhel.sh" "$CACTI_ROOT"
```

To set a port from the shell, substitute a port permitted by the host’s SELinux and firewall policies. If this host manages its firewall and both transports should be opened, add `OPEN_FIREWALL=1` to this command.

```bash
sudo env WEB_USER="$WEB_USER" SYSLOG_PORT=20514 bash "$SYSLOG_DIR/install_syslog_rhel.sh" "$CACTI_ROOT"
```

To collect the collector’s own OS messages, configure its device binding for loopback TCP, then enable local forwarding.

```bash
sudo bash "$SYSLOG_DIR/enable_local_syslog_rhel.sh"
```

**Verify reception.** The diagnostic sends a tagged UDP test message. Use the device’s Syslog history to confirm the expected sender, severity and facility.

```bash
sudo rsyslogd -N1
sudo systemctl is-active rsyslog icct-nms-syslog-worker icct-nms-package-runner.timer
sudo cat /etc/icct-nms-syslog-port
sudo ss -ltunp
sudo bash "$SYSLOG_DIR/diagnose_syslog_rhel.sh" "$CACTI_ROOT"
sudo tail -n 5 /var/log/icct-nms/remote.ndjson
sudo journalctl -u icct-nms-syslog-worker -n 30 --no-pager
```

**Repair the runner.** Choose the first command for Syslog port management using installed prerequisites. Choose the second for full package management when verified media is installed. The full runner expects a root-owned media directory with mode 0700. Check the runner journal if a Presets save stays queued.

```bash
sudo /usr/bin/python3 "$PLUGIN/installation/cli/package_runner.py" provision --cacti-root "$CACTI_ROOT" --web-user "$WEB_USER" --syslog-only
# Alternative with complete installed media
sudo /usr/bin/python3 "$PLUGIN/installation/cli/package_runner.py" provision --cacti-root "$CACTI_ROOT" --web-user "$WEB_USER" --no-packages
sudo journalctl -u icct-nms-package-runner.service -n 30 --no-pager
```

## Optional protocol services

Run the section for the feature you use. Replace example paths, addresses, accounts and origins with the values configured at your site.

**SNMP traps.** Supply the private receiver authorization file and the intended listener address. Loopback receives local tests. For remote traps use the collector’s reachable address.

```bash
sudo bash "$PLUGIN/protocols/snmp/traps/rhel/install_traps_rhel.sh" "$CACTI_ROOT" /path/to/receiver-authorizations.conf udp:127.0.0.1:162
sudo journalctl -u icct-nms-trap-worker.service -n 30 --no-pager
# Restart after changing worker settings
sudo systemctl restart icct-nms-trap-worker.service
```

**NetFlow.** Install nfdump and nfcapd from the approved offline media first. This script takes the plugin directory.

```bash
sudo bash "$PLUGIN/protocols/netflow/rhel/install_netflow_rhel.sh" "$PLUGIN"
sudo systemctl status icct-nms-netflow.service --no-pager
```

**NTP over SSH.** On the monitored RHEL device, copy the plugin’s `installation/cli/ntp_access.py` there and run the following from its directory. Use the SSH account saved for that device. The helper provisions the limited chrony read permissions.

```bash
sudo /usr/bin/python3 ntp_access.py install --user monitoring_account
```

**SSH broker.** For a complete release with the native Guacamole bundle, supply the site’s HTTPS origin during installation. To configure an already installed plugin separately, use the second command with its verified native bundle.

```bash
sudo /usr/bin/python3 install.py --cacti-root "$CACTI_ROOT" --ssh-origin https://nms.example.org
# Alternative for an existing installation
sudo /usr/bin/python3 "$PLUGIN/protocols/ssh/configure.py" --cacti-path "$CACTI_ROOT" --origin https://nms.example.org --web-user "$WEB_USER" --web-group apache --poller-id 1 --native-bundle /media/guacamole-native
```

**Discovery, location and diagnostics.** Standard setup writes units for the selected Cacti path. To enable existing discovery and location units or check collector diagnostics:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now icct-nms-discovery.service icct-nms-location.service
sudo systemctl status icct-nms-discovery.service icct-nms-location.service icct-nms-diagnostics.service --no-pager
```

**MIB simulator on a test collector.**

```bash
sudo bash "$PLUGIN/protocols/snmp/mibs/simulator/install_rhel.sh" "$WEB_USER"
sudo journalctl -u icct-nms-mib-simulator.service -n 30 --no-pager
```

## Package preparation and connected installations

Build the base RPM bundle on a connected build host matching the offline target’s release and architecture. On a connected collector, the explicit online setup command uses configured repositories. The native install command takes a previously verified native bundle.

```bash
# Connected build host
sudo /usr/bin/python3 "$PLUGIN/installation/cli/setup.py" bundle --bundle /tmp/icct-packages
# Connected collector only
sudo /usr/bin/python3 "$PLUGIN/installation/cli/setup.py" install --cacti-root "$CACTI_ROOT" --online
# Collector with a local native bundle
sudo /usr/bin/python3 "$PLUGIN/installation/cli/native.py" install --bundle /media/guacamole-native --web-group apache
```

See the [installation guide](installation/README.md) for release building, component prerequisites, and installation checks.
