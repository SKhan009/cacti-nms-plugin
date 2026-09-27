# NMS serial monitoring and configuration on RHEL 9

This guide is for the administrator of a Cacti collector. It explains how to connect serial equipment, read supported values and apply a reviewed change through NMS. Run the installation commands on the collector that physically reaches the equipment. Devices keep their own Cacti IDs, sites and permissions; a node is a manually maintained group and has no shared device IP.

The examples use `/var/www/html/cacti` and the `apache` collector account, matching the QA VM. Replace these with the actual installation path and service account where different. Commands that contain YOUR values require replacement before use. Changes remain local; no Git publication is required.

## 1 Prepare the collector

Supported serial transport is Modbus RTU through a Linux serial port, or transparent RTU frames over a TCP gateway. The gateway must preserve RTU frames and CRC. Native Modbus TCP with an MBAP header, proprietary serial commands, GNSS and automatic equipment backup or restore are not implemented.

Use the existing Cacti installation and its supported PHP version. Install the additional packages from your approved RHEL repositories. For offline installations, transfer matching signed RPMs and dependencies for the collector architecture into a dedicated directory and use the second command instead.

```sh
sudo dnf install python3 python3-pyserial php-snmp
# Offline alternative with prepared matching RPMs
sudo dnf --disablerepo='*' install /mnt/nms-rpms/*.rpm
```

Verify the packages and the account that runs the existing listener. PHP CLI and the PHP SNMP extension must belong to the same PHP installation.

```sh
rpm -q python3 python3-pyserial php-cli php-snmp
sudo systemctl show nms-diagnostic-runner.service \
  -p User -p Group -p ExecStart
sudo -u apache /usr/bin/python3 -c \
  'import serial; print(serial.VERSION)'
sudo -u apache /usr/bin/php -r \
  'echo extension_loaded("snmp") ? "SNMP available\n" : "SNMP missing\n";'
```

QA output was pySerial `3.4` and `SNMP available`, with Python 3.9.25 and PHP 8.0.30 on RHEL 9 aarch64. These are observed versions, not a requirement to downgrade another supported Cacti installation. No internet access is needed by the serial adapter after installation.

## 2 Grant port access and persistent locks

The collector account must have read and write access to its serial port. Prefer a stable `/dev/serial/by-id/` path. Discover the real port and its current group; do not substitute a network loopback address for serial equipment.

```sh
ls -l /dev/serial/by-id/
readlink -f /dev/serial/by-id/YOUR_ADAPTER
stat -Lc '%n %a %U %G' /dev/serial/by-id/YOUR_ADAPTER
```

If your approved device group is `dialout`, add only the collector account to it. If the device has a different group, use the administrator-approved group instead. USB permissions must be maintained by an appropriate persistent udev rule; chmod on a temporary device file does not survive reconnecting the adapter.

```sh
sudo usermod -aG dialout apache
sudo systemctl restart nms-diagnostic-runner.service
sudo -u apache test -r /dev/serial/by-id/YOUR_ADAPTER
sudo -u apache test -w /dev/serial/by-id/YOUR_ADAPTER
```

Both `test` commands succeed silently with exit code 0. They prove filesystem access only, not an equipment response. Restart the actual poller/listener service if your installation uses a different launcher, so it receives updated group membership. Never use world-writable serial ports or disable SELinux as an installation step.

Install the supplied lock-directory rule from the project checkout. Change the account names in this file first if the collector does not run as apache.

```sh
sudo install -o root -g root -m 0644 \
  docs/nms/deployment/nms-serial-tmpfiles.conf \
  /etc/tmpfiles.d/nms-serial.conf
sudo systemd-tmpfiles --create /etc/tmpfiles.d/nms-serial.conf
sudo -u apache test -w /run/cacti-nms-serial
stat -c '%a %U %G' /run/cacti-nms-serial
```

Expected QA output is `700 apache apache`. The tmpfiles rule recreates this directory on boot. Direct-port locks also use the underlying device identity so alternate paths to the same port cannot bypass the lock. An RTU gateway endpoint has one collector owner; do not run an unrelated master against the same bus.

## 3 Configure profiles and devices in NMS

Back up the Cacti database and NMS plugin before upgrading. Deploy the validated plugin files on each assigned collector and use Cacti Plugin Management to upgrade its schema. Enable NMS only when ready to run the configured reads. The listener and configuration worker require an enabled plugin and compatible schema.

In Presets, create a Serial profile using the equipment manual. Enter its manufacturer and model, baud rate, eight data bits, parity, stop bits, flow control, timeout and bounded read retries. A saved profile supplies defaults. Editing it does not change an existing connection. Use the affected-member review and explicit refresh to adopt revised defaults.

In Device management, open Add serial device and its shared-connection setup. Select the assigned collector, saved profile and either a direct port or an RTU-over-TCP gateway IP and TCP port. Configure the gateway itself to the same physical serial settings; NMS does not remotely reprogram its baud rate or wiring mode.

Create each device with its name, Cacti site, connection and unique Modbus unit address from 1 to 247. Devices on one bus share the connection settings but have distinct unit addresses. Separate buses may use different settings. Choose a node manually if required. Cacti stores the actual connection endpoint as native hostname metadata; SNMP and network availability probes are disabled for a new serial-only device.

Create an Equipment profile under Configuration profiles. It describes the values inside the instrument, separately from the communication profile. Use only the manufacturer’s documented register/OID map, with a manual reference. The implemented Modbus map supports function 3 holding-register reads, function 4 read-only input registers, and function 6 single-register writes. Values are unsigned or signed 16-bit integers with validated limits. Offsets are zero-based; convert manual register notation according to that manual. Do not guess that a printed register number is the wire offset.

Assign the equipment profile in the device’s Configuration page. Set its collection interval. Assigned equipment profiles cannot be edited in place: create a revised profile and explicitly reassign it. This prevents existing graphs or reviewed writes from silently changing meaning. Native Modbus floating-point, multi-register values and vendor scaling need a separately implemented adapter or mapping extension before use.

## 4 Read and apply a reviewed change

Open the device’s Configuration page and read a supported setting. A collector job checks device permissions, the assigned collector, disabled state and the current profile/connection signature before execution. The web request never opens the serial port.

After a successful read, enter the requested value and select Preview. Check the device, setting, previous value and requested value, then apply the reviewed change. Read evidence expires after five minutes and is usable for one write only. The collector rereads the value before writing; if it changed since review, no write is sent. It sends the write once and reads back the result.

Verified means the returned value equals the requested value. Failed means an admission, validation or execution failure was reported. Unverified means the outcome is uncertain or does not match; inspect the observed value and read again before deciding what to do. A timeout or interrupted worker never triggers an automatic write replay. An acknowledgement alone does not establish success. History records the operator ID, device, times, previous/requested values and result.

For SNMP equipment, native Cacti credentials supply reads. Define supported writable numeric OIDs in an SNMP equipment profile. Writes use a collector-side credential reference restricted to explicit Cacti host IDs. Store the secret PHP file outside the web document root, owned by root and readable only by the collector group. Load it from the collector’s Cacti configuration for CLI execution. Never store write secrets in profile JSON, jobs or source control.

Use `/etc/cacti/nms/write-credentials.php` for the protected file (root ownership, collector group and mode 0640; parent directory 0750). Add the following include inside the PHP section of the collector's existing Cacti `include/config.php`, after the core configuration is loaded. Preserve its existing content.

```php
if (PHP_SAPI === 'cli') {
    require '/etc/cacti/nms/write-credentials.php';
}
```

Example structure for the protected credential file is shown below. Replace every YOUR value. Use the existing native device ID as an integer and the actual approved SNMP version and credentials.

```php
<?php
$config['nms_configuration_credentials']['YOUR_REFERENCE'] = [
    'host_ids' => [YOUR_CACTI_HOST_ID],
    'snmp_version' => 2,
    'snmp_community' => 'YOUR_WRITE_COMMUNITY',
];
```

For SNMPv3, use the approved username, authentication and privacy fields supported by the collector PHP SNMP library. Test the chosen version against the real device before allowing changes. Isolated QA verified SNMPv2c and SNMPv3 SHA/AES authPriv reads, writes and matching read-back, including credential device scope and result redaction. Other SNMPv3 combinations and manufacturer hardware still require validation.

## 5 Monitor devices and nodes

The existing NMS listener launches configuration jobs and scheduled reads on the assigned collector. Do not add a second independent serial polling loop. A standalone systemd listener is supplied in the deployment directory for installations that use that launcher; the QA VM already has it. For a dedicated service, add the following setting inside the PHP block of Cacti's administrator-managed `include/config.php` on that collector. It prevents poller startup from competing with systemd. Without a dedicated service, omit it or use `poller`.

```php
$config['nms_diagnostic_listener_launcher'] = 'service';
```

The listener lock prevents two active listeners. After deployment, confirm that its PID matches the service MainPID and that the restart count stays stable. Restart only after current configuration writes finish.

```sh
sudo systemctl show nms-diagnostic-runner.service \
  -p MainPID -p ActiveState -p NRestarts
sudo journalctl -u nms-diagnostic-runner.service -n 40 --no-pager
sudo tail -n 60 /var/www/html/cacti/log/cacti.log
```

When NMS is disabled, its worker does not collect or apply changes. A service repeatedly restarting while the plugin is disabled is not proof of equipment failure. Verify Plugin Management and the schema before troubleshooting ports. Queue processing and sequential field reads are bounded; allow for the collection interval and the number of devices and fields.

Use Create numeric graphs from device Configuration to create native Cacti data inputs, templates and graphs. These read cached numeric samples. They do not send another equipment request for each graph. An unavailable, stale or changed-profile sample becomes `U`, leaving an RRD gap rather than a fabricated zero. Old graphs are bound to the equipment profile and revision; create compatible new graphs after reassignment.

Serial response status is independent of gateway connectivity or Cacti’s no-ping state. Device readings shows the connection, current values and collection times. Use Serial to filter these rows; All and Problems include them. Stale, failed and unavailable readings show no current value. A valid reply appears as Responding in node and topology details. Network latency is not inferred from serial replies. Disabled devices remain disabled.

In Nodes, add or remove members manually. Each device retains its collector, protocol and unit address. For a bulk configuration change, manually select compatible members, read them, review every previous/requested value and apply. Compatibility includes protocol, meaning, units and representation; device-specific addresses and limits still apply. Results are independent. Moving or removing one member after review rejects that member without rolling back verified changes to others.

## 6 Verify and troubleshoot

Before real equipment writes, verify its manufacturer/model, wiring, unit address, port settings, register map and permitted range. Start with a known read-only value and compare it with the device’s own display or management interface. Record the observed value and time. A gateway accepting a TCP connection does not prove that an instrument on its serial bus responded.

If there is no reply, check the physical wiring and interface type, baud/parity/stop settings, address, port permissions and selected collector. An RTU frame/CRC error can indicate incompatible gateway mode or bus settings. If the connection is busy, stop the competing master and allow the existing bounded operation to finish; do not remove a lock file to bypass an active process.

For SELinux denials, inspect the actual denial and obtain an approved policy change for the collector service. Keep enforcement enabled. Do not grant blanket sudo access to the PHP worker.

```sh
sudo ausearch -m AVC -ts recent
sudo systemctl status nms-diagnostic-runner.service --no-pager
sudo -u apache test -w /run/cacti-nms-serial
```

If values are stale, check listener execution, the assigning operator’s permissions, disabled devices/collectors and the configured interval. A collector move requires an explicitly compatible serial connection; jobs cannot silently execute through another collector. Device deletion removes assignments and cached readings while retaining configuration audit history.

QA passed profile/conflict/permission and freshness tests. All 16 RHEL transport tests passed, including exact retry budgets, recovery from a bad reply, no retry after a device exception, timeouts and port locking. Browser workflows verified node writes 17 → 23 and single-device writes 17 → 24, rejected an out-of-range value, and applied preset changes only after review. Native Cacti graph APIs retained values, recorded unknown gaps and rendered SVG; the scheduled poller wrote simulator value 17 into its RRD. Browser checks verified responding and failed states in nodes/topology, serial value 17 in Device readings, and Serial/Problems filtering. Temporary-table tests covered stale, missing and failed values and matching counts. Labelled synthetic browser fixtures also verified stale/unavailable readings, Unknown node counts and topology details. Native Device Edit successfully saved metadata before disabling monitoring. Isolated SNMPv2c and SNMPv3 SHA/AES GET/SET, SSH transport/parser and ticket tests passed. The existing SSH service collected CPU, memory and uptime; its original stopped state was restored. These are test fixtures, not production maps or hardware results.

Restricted-account browser checks denied serial profiles, device configuration, node configuration and connection setup without management permission. A manager with no device visibility could not open another device’s configuration or connection. The temporary account and sessions were removed. A profile submission after permission revocation created no profile. Eighteen isolated controller GET/POST cases also passed. Worker tests confirmed that pre-dispatch rejection is Failed and interrupted writes remain Unverified. Coordinated reboot recovery and manufacturer hardware validation remain outstanding. SSH browser console acceptance requires a trusted HTTPS certificate; the QA certificate error was not bypassed. Package installation, process health and simulator tests do not prove that real equipment is correctly configured. Full section 5.3.2 compliance also requires review of its remaining requirements.

The implementation references are `plugins/nms/collector/serial_transport.py`, `includes/configuration/runner.php`, `monitoring.php` and `graphs.php`. The test sources are under `tests/nms/`. This guide documents the local implementation; use the project’s verification record to identify which changes have been deployed to a particular QA collector.
