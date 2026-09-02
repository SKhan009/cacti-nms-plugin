# NMS SNMP simulator

This directory is the version-controlled backup for the SNMPSim instance used by the
Cacti VM. It does not change Cacti core. The simulator listens only inside the VM on
`127.0.0.1:1161`; the existing Net-SNMP daemon keeps port `161`.

## Simulated devices

| Cacti device | Address | SNMP | Community | Main readings |
| --- | --- | --- | --- | --- |
| NMS Simulated Router | `127.0.0.1:1161` | v2c | `sim-router` | state, interfaces, traffic and errors |
| NMS Simulated Server | `127.0.0.1:1161` | v2c | `sim-server` | memory, swap, load and CPU |
| NMS Simulated Sensor | `127.0.0.1:1161` | v2c | `sim-sensor` | temperature, humidity and alarm text |

Each `.snmprec` filename is its SNMP community. This lets Cacti represent multiple
devices through one loopback simulator endpoint.

## Dynamic server configuration

There are no embedded responder, account, directory, address, or port defaults. Generate
a separate bundle on each server. For the existing `bstc` lab:

```sh
python3 configure.py \
  --executable /home/bstc/.local/bin/snmpsim-command-responder \
  --data-dir /usr/share/cacti/snmpsim/data/ups-device/ups \
  --listen-address 127.0.0.1 --client-address 127.0.0.1 --port 1161 \
  --user bstc --group bstc --service-name snmpsim \
  --output-dir /tmp/nms-snmpsim-install
```

`listen-address` is the bind address. `client-address` is the address Cacti collectors
use. Loopback is valid only for the local collector. Review the generated files, then:

```sh
sudo install -d -o root -g root -m 0755 /etc/cacti-nms /usr/local/libexec
sudo install -o root -g root -m 0644 /tmp/nms-snmpsim-install/snmpsim.json /etc/cacti-nms/snmpsim.json
sudo install -o root -g root -m 0755 /tmp/nms-snmpsim-install/nms-snmpsim-*.py /usr/local/libexec/
sudo install -o root -g root -m 0644 /tmp/nms-snmpsim-install/*.service /tmp/nms-snmpsim-install/*.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now snmpsim.service snmpsim-reload.timer
sudo systemctl restart snmpsim.service
sudo systemctl status snmpsim.service
```

The generator validates inputs, refuses to overwrite a bundle, and changes no system
state. The web application accepts only a root-owned, non-writable configuration. PHP
displays health and performs explicit live SNMP checks, but never executes the responder
or controls systemd. The timer consumes `.reload.pending` without losing uploads that
arrive during restart and respects an administrator stop. Apache needs no sudo access.
The data directory must be writable by Apache and readable by the configured service user.
Use a shared group with set-group-ID directories and appropriate SELinux policy on RHEL.
There is no saved-value fallback.

Stop any manually launched responder on the selected port before starting the managed
service. Existing record files are reused; do not reinstall SNMPSim or recreate devices.

Upgrade note: install this configuration before using imports on NMS 1.9.30. The old
database `snmprec_runtime_dir` value is no longer used. Existing Cacti hosts are not
rewritten when the endpoint changes; review them explicitly in Cacti. Back up existing
service files before replacing them. If using another service name, disable the old
timer/service after review to prevent two responders competing for the same port.

After import, **Add device** uses this record's community/template and the shared configured
endpoint. Every community is a separate simulated device on that endpoint. **Check live
SNMP** verifies an actual response; record values are never returned as fallback.

Regression tests:

```sh
python3 -m unittest -v test_configure.py
php ../tests/snmpsim_config_test.php
```

Useful checks:

```sh
sudo systemctl status snmpsim
snmpwalk -v2c -c sim-router 127.0.0.1:1161 1.3.6.1.2.1.1
snmpwalk -v2c -c sim-server 127.0.0.1:1161 1.3.6.1.4.1.2021
snmpwalk -v2c -c sim-sensor 127.0.0.1:1161 1.3.6.1.2.1.99
```

The readings are deterministic lab data. Change the value field in a record and restart
`snmpsim` to reproduce a healthy or faulty device state for NMS rule testing.

The `examples/` directory contains small files intended for testing the NMS upload page.
They are not loaded merely by deploying the plugin; importing one validates it, creates
the Cacti templates, and then activates its community in the simulator.

`examples/nms-device-demo.snmprec` is the general UI test file. It has 24 records and 18
graphable readings. Download it directly from the Upload SNMP Record page, then use:

- simulator community: `nms-device-demo`
- host template: `NMS Demo Environmental Device`
- category: `Sensors & Instrumentation`
