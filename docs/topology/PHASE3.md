# Phase 3 — local live-agent and SNMPv3 validation

Version **0.3.0**, validated on 7 September 2026 against the project's Cacti 1.2.31 / RHEL 9.8 VM. The user selected local-device and SNMPv3 validation before manual connections or saved layouts.

## Delivered

- Strict validation of native Cacti SNMPv3 selections, required keys, security name and context length. Missing keys fail before opening a session; they cannot silently select a weaker security level.
- Explicit checking of PHP `setSecurity()` success, including runtime algorithm rejection.
- One fresh PHP CLI process per SNMPv3 protocol collection. Live negative tests reproduced Net-SNMP key reuse when the same username was opened with changed credentials in one process. Isolation fixes this: correct credentials pass, then wrong authentication/privacy keys fail, then correct credentials pass again.
- Bounded child execution, bounded result size and anonymous pipes for credentials. An unavailable child process fails explicitly. Collection still uses Cacti's native SNMP session factory and selected core device settings.
- Repeatable local validation using temporary read-only Net-SNMP users and SNMPSim contexts, with automatic process/configuration cleanup. Tests run as `apache`, the topology worker account.

The native Cacti UI and core device, graph and data-source ownership remain as in Phase 2. No schema migration, manual link editor or new web interface was required.

Final regression checks passed for record parsing, neighbor reconciliation, bounded transport, native provisioning idempotency, restricted-user permissions, persisted lab evidence and live SNMPv2c LLDP collection. The deployed plugin is enabled at 0.3.0; the persistent node has one reciprocal connection and 48 native data sources plus 48 graphs. All 23 PHP files passed syntax checks. The original agents and polling timers remain active, private plugin files return HTTP 403, and SELinux remains enforcing. Browser verification reached the normal Cacti login page because the existing session had expired; no UI source files were changed in this phase.

## What was tested

| Target/check | Result |
|---|---|
| Core device 2, Cacti RHEL 9 Local, existing loopback port 161 | Real system and IF-MIB reads with its existing saved credentials |
| Temporary Net-SNMP agent, loopback port 1163 | Real VM interfaces over SNMPv3 SHA/AES and SHA-256/AES, both `authPriv` |
| Interface comparison | Same live interface indices and descriptions as port 161; the temporary v3 view also exposes interface names |
| Wrong user/authentication key/privacy key | Explicit failure; no successful cached-key substitution |
| Missing keys or unsupported selections | Explicit configuration failure; no security downgrade |
| Explicit weaker levels against a privacy-required live user | Rejected by the live agent |
| Unknown SNMP context | Failure; no retry using the default context |
| VM without LLDP/CDP MIBs | Required-object failure under v2c and v3; no fabricated topology |
| Temporary SNMPSim, loopback port 1164 | SHA/AES `authPriv`, separate `v3-switch-a` and `v3-switch-b` named contexts |
| Simulated dual-protocol topology over v3 | One reciprocal interface connection, four retained LLDP/CDP observations |
| Unknown simulator context | Failure; no substitute record selected |
| Original device configuration | Exact core device row unchanged after validation |

These tests validate a **live Linux VM agent** and **simulated switch MIBs**. They do not validate physical switch firmware, actual LLDP/CDP Ethernet advertisements, stacks or unsupported port-ID subtypes. The existing port-161 SNMP view omits `ifName`; SNMP access alone does not guarantee that all topology objects are readable.

## Configuration requirements

Use native **Console → Management → Devices** for the management address, port, SNMP version, username, algorithms, keys and context. The plugin does not maintain another credential database. On a real device, enable the selected neighbor protocol and grant its read-only SNMP user access to system uptime, IF-MIB, required IF-X-MIB identifiers, and the selected LLDP-MIB and/or CISCO-CDP-MIB objects.

For this VM, SHA-256 with AES and `authPriv` is a tested combination. Other native Cacti algorithms remain runtime dependent and are not all certified by these tests. Explicit `authNoPriv` and `noAuthNoPriv` profiles are recognized, but neither is substituted after an `authPriv` failure.

Leave the native authoritative engine-ID field empty for this plugin's supported configuration; discovery determines the remote engine. A nonempty field is rejected rather than ignored. PHP's final `setSecurity` argument is a **context engine ID**, which must not be substituted for Cacti's authoritative/security engine ID. See the [PHP API documentation](https://www.php.net/manual/en/snmp.setsecurity.php).

## Validation dependencies and rerun

The installed Cacti PHP SNMP extension and Net-SNMP 5.9.1 provide the actual collection transport. No new Cacti production transport package was needed. The test-only Python environment is **`/opt/cacti-topology-validation/venv`**, separate from the original NMS/SNMPSim environment. It adds the AES dependency `cryptography` and includes `pysmi`, with exact tested versions in `simulator/validation-requirements.txt`. No SNMPSim/PySNMP source patches were made. The simulator's supported security/context features are documented by [SNMPSim](https://pypi.org/project/snmpsim/1.2.2/).

To prepare the test environment on an equivalent RHEL VM with Python 3.11 already installed:

```sh
sudo python3.11 -m venv /opt/cacti-topology-validation/venv
sudo /opt/cacti-topology-validation/venv/bin/pip install \
  -r /var/www/html/cacti/plugins/topology/simulator/validation-requirements.txt
```

Run on the project VM, where core device 2 is explicitly the loopback Net-SNMP device and the Phase 1 installer has created the `topology-sim` account:

```sh
sudo python3 /var/www/html/cacti/plugins/topology/tests/run_local_validation.py \
  --cacti-root=/var/www/html/cacti --host-id=2
```

The runner requires free loopback UDP ports 1163 and 1164, or explicit alternatives. It creates random test keys in protected temporary configuration, passes simulator keys through stdin, launches only its own temporary agents, runs checks as `apache`, then terminates those agents and removes their configuration. It does not edit the native device's saved credentials or `/etc/snmp/snmpd.conf`. The installed test environment remains available for reruns; temporary test users do not remain available as login credentials. Read-only Net-SNMP user configuration follows the [Net-SNMP agent documentation](https://www.net-snmp.org/docs/man/snmpd.conf.html).

Pure checks:

```sh
php /var/www/html/cacti/plugins/topology/tests/security_test.php
php /var/www/html/cacti/plugins/topology/tests/records_test.php
php /var/www/html/cacti/plugins/topology/tests/neighbors_test.php
php /var/www/html/cacti/plugins/topology/tests/discovery_transport_test.php
```

Phase 2 lab/integration commands are retained in [PHASE2.md](PHASE2.md). The Phase 3 deployment backup is `/var/backups/topology-phase3-20260907/` on the VM.
