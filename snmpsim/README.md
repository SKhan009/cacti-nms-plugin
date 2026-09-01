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

## VM layout

- Python environment: `/opt/snmpsim/venv`
- data records: `/var/lib/snmpsim/data`
- service: `/etc/systemd/system/snmpsim.service`
- listener: UDP `127.0.0.1:1161`

After activating a validated record, the upload handler writes `.reload.pending` inside
the data directory. The root-owned `snmpsim-reload.timer` checks that marker every ten
seconds and restarts `snmpsim.service`. Apache receives no sudo or service-management
access. The data directory is writable by Apache and readable by the unprivileged
`snmpsim` account. SELinux is limited to that directory through the
`httpd_sys_rw_content_t` label. The directory uses its set-group-ID bit so files created
by Apache inherit the `snmpsim` group.

The Python packages and the explicit runtime dependency required by the responder are
pinned in `requirements.txt`.

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
