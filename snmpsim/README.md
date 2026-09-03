# Optional NMS SNMP simulator

SNMPSim is a separate lab dependency, not a requirement for real-device monitoring.
The plugin never launches a responder from a web request. Record values become
observations only after an actual SNMP response; imported samples do not mask failure.

No endpoint is activated by installing this directory. Files in `data/` and
`examples/` are development fixtures, not a list of devices installed on your server.

## Portable manual activation

Follow [generic installation](../docs/GENERIC_INSTALL.md) to select an explicit
administrator-owned JSON file through `NMS_SNMPSIM_CONFIG`. Use `activation:
manual`, your actual record directory, reachable IPv4 client address, integer
UDP port and integer `poller_id` selected from Cacti's Data Collectors. NMS validates
that this collector exists and is enabled; it never assumes collector 1. The responder must separately be configured to serve that directory and
endpoint. An optional executable path is metadata in manual mode, not permission
for PHP to execute it.

Each uploaded record has its own reviewed community/filename and native template
link. New simulated devices use that record's mapping. Existing real devices keep
their native Cacti settings. Changing the configured simulator endpoint does not
silently rewrite existing Cacti hosts.

The current fixture importer uses SNMP v2c communities; this is not an SNMPv3
credential provisioning system. Keep lab communities off public networks and out
of diagnostic reports. Bind/listen addresses and client addresses have different
purposes. A loopback address is correct only for a querying process on that host.

After upload, activate/reload the responder through your OS's normal administrator
workflow and use **Check live SNMP**. The displayed result is an actual protocol
check, not proof that every imported OID or remote collector is operational.
The web probe runs only when the web process's native Cacti collector ID matches
the configured simulator collector. Otherwise it explains that verification must
use that collector's actual poll cycle; it does not test a different network vantage
point. Probe timeout/retries use native Cacti settings. Remote record deployment
still requires review. An import-based Add device pins the reviewed collector along
with the endpoint/community; use normal device management for intentional changes.

## Optional Linux/systemd generator

This generator is Linux-only. It writes a new bundle without installing it, changing
permissions or starting services. Use approved offline dependencies when the target
has no internet access. Do not reinstall a working responder merely to change NMS.

Determine the actual account, group, executable and storage paths first. All bracketed
values below are required placeholders, not defaults or literal shell commands:

```text
<PYTHON_EXECUTABLE> configure.py
  --executable "<RESPONDER_EXECUTABLE>"
  --data-dir "<EXISTING_RECORD_DIRECTORY>"
  --listen-address "<BIND_IPV4>"
  --client-address "<REACHABLE_IPV4>"
  --port <CHOSEN_UDP_PORT>
  --poller-id <EXISTING_ENABLED_CACTI_COLLECTOR_ID>
  --user <EXISTING_UNPRIVILEGED_SERVICE_USER>
  --group <EXISTING_UNPRIVILEGED_SERVICE_GROUP>
  --service-name <CHOSEN_SERVICE_BASENAME>
  --python "<PYTHON_EXECUTABLE>"
  --systemctl "<SYSTEMCTL_EXECUTABLE>"
  --helper-dir "<ADMINISTRATOR_OWNED_HELPER_DIRECTORY>"
  --config-path "<ADMINISTRATOR_OWNED_JSON_FILE>"
  --lock-path "<LOCK_FILE_IN_ADMINISTRATOR_OWNED_DIRECTORY>"
  --output-dir "<NEW_BUNDLE_DIRECTORY>"
```

Paths may contain spaces. The generator validates paths and installed executables,
checks the selected account, and refuses to overwrite an output bundle. Inspect the
generated JSON, responder service, reload service/timer, and all three Python files:
`nms-snmpsim-run.py`, `nms-snmpsim-reload.py`, and `runtime_config.py`.

Installation is an administrator action:

1. Back up existing configuration, units, helpers and record metadata. Confirm no
   manually launched responder already owns the selected address/port.
2. Install the generated JSON at exactly `--config-path`, and all three Python files
   in exactly `--helper-dir`. Configuration, helper code and their directories must
   be root-owned and not writable by the web or simulator accounts.
3. Provision the selected lock parent directory as root-owned and non-group/world
   writable. If it is under volatile runtime storage, arrange recreation at boot
   through the platform's supported mechanism. The reload helper creates its lock
   file with mode 0600 and refuses symlinks.
4. Install the units in the actual systemd administrator-unit directory. Review their
   hardening and runtime access on the installed systemd version. The responder runs
   unprivileged; the reload timer helper runs as administrator with a fixed,
   administrator-controlled configuration.
5. Grant import-write access only to record storage, and responder-read access to
   those records, using reviewed groups/ACLs. Keep storage outside the web root.
   Maintain SELinux enforcing and configure only the necessary labels/policy.
6. Explicitly select the same JSON for PHP through `NMS_SNMPSIM_CONFIG`. PHP-FPM
   service/pool environment and CLI environments may differ; verify both as needed.
   There is no automatic lookup of an old `/etc` path. No Cacti core edit is needed.
7. Have the administrator reload systemd units and intentionally enable/start the
   reviewed responder and timer. Verify logs, service identities, access and actual
   SNMP results using the configured endpoint. Do not grant Apache sudo privileges.

The timer consumes upload markers and uses try-restart: it must not start a service
that an administrator stopped. Requests arriving during a restart retain their own
pending marker. Failed or inactive checks retain processing state. PHP may query
service status through the explicitly configured systemctl executable but cannot
control service state. A service being active is not proof of a successful SNMP poll.

When upgrading older managed installations, supply explicit `poller_id`, `activation`,
`systemctl` and `lock_path` settings as well as the other generated fields. Preserve
records and pending markers. Review obsolete timers/units before replacing them;
do not leave multiple responders competing for the same endpoint.

## Verification

Local standalone checks from this directory:

```sh
python3 -m unittest -v test_configure.py
php ../tests/snmpsim_config_test.php
php ../tests/portable_config_test.php
php ../tests/snmpsim_collector_test.php
```

The PHP and Python executable names above refer to your verified local tools, not
hardcoded application paths. Generator/marker tests do not prove a running systemd
service, native PHP-FPM access, SNMP communication, remote collector behavior, or
SELinux compatibility. Those remain mandatory target-VM acceptance tests.

Use a reviewed example record to test a new community/device through Cacti. Record
a successful poll timestamp, intentionally stop the lab responder, and verify that
later collection is failed/stale without a sample-file substitution. Restart only
through the authorized lifecycle and verify genuine recovery. Never stop a
production SNMP daemon as part of this simulator check.
