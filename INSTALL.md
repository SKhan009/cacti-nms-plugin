# ICCT NMS complete installation

The GitHub main ZIP contains the plugin, installer entry points at `install.py` and `icct_nms/install.py`, and bundled offline media under `icct_nms/installation/bundles/media/{packages,components,native}`. Both entry points use `icct_nms/installation/cli/launcher.py`; there is one installer implementation. Extract the whole ZIP outside the Cacti web root. Retain the complete plugin folder, including its installer and media; copying PHP files alone cannot provision collector services.

The launcher detects the destination Linux distribution, release and CPU architecture using the existing installer detection. It searches bundled media manifests for an exact match, including media trees in subdirectories. Folder names do not determine compatibility. When no matching offline bundle exists, the launcher uses the destination's configured DNF/APT repositories and requests only missing packages, including repository-supported optional component profiles; it reuses installed dependencies and Cacti's existing installation and lifecycle APIs. It does not add another Cacti instance or poller.

The currently supplied RPMs and native binaries target **RHEL 9.8 aarch64 (ARM64)**. Other supported RHEL-family and Debian-family Linux systems use their own repositories unless matching offline media is supplied. Wrong-OS or wrong-CPU bundles are never installed. Windows, macOS and unrecognized Linux families are rejected; automatic selection does not create missing native builds or enable unsupported integrations. The plan lists `repository_components` and `native_components_not_bundled`. Existing verified native runtimes can still be reused; strict-auth Guacamole and Pathchar need matching builds when they are absent.

## Installation steps for `/var/www/html/cacti`

Use these steps on the Linux collector where Cacti is already installed at `/var/www/html/cacti`. Python 3 and an administrator account with `sudo` are required. Run the commands from the extracted download, outside the Cacti web root.

1. Download the latest main ZIP and extract the complete archive, including offline media:

   ```sh
   unzip cacti-nms-plugin-main.zip
   cd cacti-nms-plugin-main
   ```

2. Preview the detected OS, architecture, selected packages and destination:

   ```sh
   python3 install.py --cacti-root /var/www/html/cacti --plan
   ```

   This is a read-only installation plan. Review whether matching offline media or configured repositories will be used.

3. Install or upgrade the plugin and configure its supported collector services:

   ```sh
   sudo python3 install.py --cacti-root /var/www/html/cacti
   ```

   To require matching offline media without repository access, use this command instead:

   ```sh
   sudo python3 install.py --cacti-root /var/www/html/cacti --offline
   ```

4. Wait for the installer to finish successfully. It backs up the existing plugin, preserves local configuration/uploads, and uses Cacti's plugin lifecycle commands to install and enable ICCT NMS.

5. Open Cacti, then **ICCT NMS → Settings → Installation**. Review Checks and Offline Packages for worker, receiver and media readiness. Configure the actual device credentials and any receiver authorization still marked **Needs setup**. Native components absent from the selected media require a matching build; automatic OS detection does not supply one.

## Install from inside `icct_nms` on `/usr/share/cacti`

Run these commands on the RHEL collector where Cacti is already working at `/usr/share/cacti`. Use the latest [main ZIP](https://github.com/SKhan009/cacti-nms-plugin/archive/refs/heads/main.zip). The commands assume the ZIP is saved as `$HOME/Downloads/cacti-nms-plugin-main.zip`.

1. Extract the complete download outside the web root and enter the plugin folder:

   ```sh
   ICCT_INSTALL_DIR="$HOME/icct-nms-install-$(date +%Y%m%d-%H%M%S)"
   mkdir -p "$ICCT_INSTALL_DIR"
   unzip "$HOME/Downloads/cacti-nms-plugin-main.zip" -d "$ICCT_INSTALL_DIR"
   cd "$ICCT_INSTALL_DIR/cacti-nms-plugin-main/icct_nms"
   ls install.py installation/cli/launcher.py
   ```

2. Preview installation from inside `icct_nms`:

   ```sh
   python3 install.py --cacti-root /usr/share/cacti --plan
   ```

   Review the detected OS, release, architecture, package source and destination. The bundled ARM64 RPMs cannot be used on x86_64. If matching offline media is absent, automatic selection uses the server's configured repositories.

   For an offline-only plan, run:

   ```sh
   python3 install.py --cacti-root /usr/share/cacti --offline --plan
   ```

   If matching media is stored elsewhere, replace `/path/to/matching-media` with its actual directory:

   ```sh
   python3 install.py --cacti-root /usr/share/cacti --media /path/to/matching-media --offline --plan
   ```

3. Install or upgrade using automatic package selection:

   ```sh
   sudo python3 install.py --cacti-root /usr/share/cacti
   ```

   Alternatively, install using the matching offline media reviewed in step 2:

   ```sh
   sudo python3 install.py --cacti-root /usr/share/cacti --media /path/to/matching-media --offline
   ```

   Choose one installation command. Wait for successful completion before verification. The installer retains existing local configuration and uploads, uses Cacti's lifecycle APIs and provisions supported collector services. If provisioning times out waiting for the runner lock, allow the current job to finish before retrying; do not delete its lock.

4. Save fresh installation observations and print every check:

   ```sh
   sudo php /usr/share/cacti/plugins/icct_nms/installation/cli/check.php --save > "$HOME/icct-install-checks.json"
   ```

   ```sh
   python3 - <<'PY'
   import json
   from pathlib import Path

   checks = json.loads((Path.home() / "icct-install-checks.json").read_text())
   for row in checks:
       print(f"{row['feature']} | {row['item']} | {row['status']} | {row['detail']}")
   PY
   ```

5. Verify common workers, the Syslog receiver and timers:

   ```sh
   sudo systemctl is-active \
     icct-nms-diagnostics.service \
     icct-nms-discovery.service \
     icct-nms-location.service \
     icct-nms-netflow.service \
     rsyslog.service \
     icct-nms-syslog-worker.service \
     icct-nms-syslog-retention.timer \
     icct-nms-package-runner.timer
   ```

   Each listed unit should report `active`. Inspect all ICCT NMS services, timer schedules and failed units:

   ```sh
   sudo systemctl list-units --all --type=service 'icct-nms-*' --no-pager
   sudo systemctl list-timers --all 'icct-nms-*' --no-pager
   sudo systemctl list-units --all --state=failed 'icct-nms-*' --no-pager
   ```

   `icct-nms-package-runner.service` is a oneshot and can be `inactive` between runs. Its timer and fresh status report are the health indicators. Syslog port-application instance services are also short-lived and need not stay active.

6. Verify that package-runner status belongs to this Cacti installation and is fresh:

   ```sh
   sudo python3 - <<'PY'
   import json
   import time
   from pathlib import Path

   path = Path("/var/lib/icct-nms-package-runner/public/status.json")
   status = json.loads(path.read_text())
   age = time.time() - status.get("time", 0)
   job = status.get("job") or {}
   limit = 930 if job.get("status") == "running" else 40

   print("Cacti root:", status.get("root"))
   print("Platform:", status.get("target"))
   print("Status age:", round(age, 1), "seconds")
   print("Syslog port:", status.get("syslog_port"))
   print("Inventory-only mode:", status.get("syslog_only"))
   print("Media error:", status.get("media_error") or "None")
   print("Job:", job.get("status", "None"))

   if Path(status.get("root", "")).resolve() != Path("/usr/share/cacti").resolve():
       raise SystemExit("FAIL: runner belongs to a different Cacti installation")
   if age < 0 or age >= limit:
       raise SystemExit("FAIL: runner status is stale or invalid")
   print("PASS: runner status is fresh and matches this Cacti installation")
   PY
   ```

   A missing status file means runner provisioning has not completed. `Inventory-only mode: True` means offline package operations are unavailable; inspect the media error and Installation page. Fresh status does not establish that every optional bundle is present.

7. Verify optional services after configuring their features:

   ```sh
   sudo systemctl is-active \
     icct-nms-trap-receiver.service \
     icct-nms-trap-worker.service \
     icct-nms-ssh.service \
     icct-nms-guacd.service
   ```

   Configured services should be `active`. Fresh installations may still require Trap sender authorization/listener settings and SSH origin/native-runtime setup. See the Trap configuration instructions below. If the MIB simulator is configured, check it separately:

   ```sh
   sudo systemctl status icct-nms-mib-simulator.service --no-pager
   ```

8. Inspect recent runner logs and confirm results in the UI:

   ```sh
   sudo journalctl \
     -u icct-nms-package-runner.service \
     -u icct-nms-diagnostics.service \
     -u icct-nms-discovery.service \
     -u icct-nms-location.service \
     -u icct-nms-netflow.service \
     -u icct-nms-syslog-worker.service \
     -u icct-nms-trap-worker.service \
     -n 100 --no-pager
   ```

   Open **ICCT NMS → Settings → Installation**, refresh Checks and inspect Offline Packages. Resolve any reported failure or **Needs setup** item for an enabled feature. Verify an authorized test Syslog message and SNMP trap appear in the plugin; active services alone do not prove delivery. Netperf and Pathchar also require suitable destinations and execution tests, beyond binary detection.

For an already-installed plugin with root-private media, its read-only plan can be run using:

```sh
sudo python3 /usr/share/cacti/plugins/icct_nms/install.py --cacti-root /usr/share/cacti --offline --plan
```

Receiver detection is permanent in the plugin: standard executable directories are searched, receiver presence is distinct from execution permissions, and the web UI can reuse fresh collector observations. No manual JSON path substitutions are needed. Package fields must contain package names, not executable paths; the installer validates every platform's lists before packaging or setup.

Reinstalling the runner waits up to 60 seconds for an existing runner lock without interrupting its job or replacing the lock. If it remains busy, retry after the operation completes. A direct `package_runner.py provision` invocation can adjust this using `--lock-timeout` (0–900 seconds).

## Building installer ZIPs

The package builder automatically includes bundled media when no media options are supplied:

```sh
python3 icct_nms/installation/cli/package.py --output /outside/plugin/icct-nms-offline.zip
```

For a deliberately partial media set, include only available verified profiles:

```sh
python3 icct_nms/installation/cli/package.py --media /approved/offline-media --allow-partial-media --output /outside/plugin/icct-nms-partial.zip
```

Matching base media remains required. Missing optional bundles leave only their affected Install actions unavailable. Present but corrupt, malformed or mismatched bundles are rejected. The installer retains existing runtimes; it does not claim an absent profile was installed. Verify media checks included profiles and reports absent optional ones.

To intentionally build without packages, select source-only explicitly:

```sh
python3 icct_nms/installation/cli/package.py --source-only --output /outside/plugin/icct-nms-source.zip
```

The builder reports `archive_type`, target and included components. A source-only archive is not a complete offline installer.

Require offline installation or select a directory containing matching media:

```sh
sudo python3 install.py --cacti-root /usr/share/cacti --offline
sudo python3 install.py --cacti-root /usr/share/cacti --media /approved/offline-media --offline
```

`--online` explicitly skips bundled media. `--no-packages` retains pre-provisioned dependencies. `--application-only` deploys the application without setting up services. Selection is also shown by `--plan`; it does not install packages or modify Cacti.

Use the actual Cacti path, for example `/var/www/html/cacti`. The main-ZIP launcher detects a unique installation at these standard paths when `--cacti-root` is omitted. Release ZIPs include the prepared manifest and use the same installation process without rebuilding it.

The installer verifies every payload hash, backs up the existing plugin, retains local configuration/uploads, installs all bundled dependencies and native components, enables the Cacti plugin, configures Syslog before unrelated collectors, and provisions the root-owned package runner and its timer. Subsequent Verify media, Install, and receiver-port operations run from Cacti without operator shell commands. Copying PHP files cannot create or upgrade the system's privileged runner; this one-time administrator installation is required on each system.

SNMP traps require the collector's real authorization and listener. Supply these once:

```sh
sudo python3 install.py --cacti-root /usr/share/cacti \
  --trap-auth-file /path/to/authorized-senders \
  --trap-listener udp:192.0.2.10:162
```

Replace the example address and file with the collector's actual settings. The dedicated authorization file must contain explicit `authCommunity` or `authUser` entries. The installer retains authorization and listener settings for later upgrades. Existing trap units are enabled and restarted automatically. With no authorized configuration, Installation Checks accurately shows Needs setup; the installer does not invent credentials or accept arbitrary senders.

The runner automatically adopts matching, fully verified bundled media when all source paths are root-owned and protected from other users. Otherwise the complete installer creates a private verified snapshot outside the web root. Package actions remain disabled for corrupt, mismatched, stale or untrusted media. Installed packages are not falsely declared runner-owned for uninstall.

Syslog and SNMP receiver checks use standard executable search directories plus fresh, host-matched collector observations. Active receiver services can establish availability when PHP-FPM cannot inspect their binary. Service startup, worker health and actual message delivery remain separate checks.

For another supported target, build its offline package and native bundles on that target using `icct_nms/installation/cli/setup.py bundle`, `native.py` and `native_rpm.py`, then create a complete archive with:

```sh
python3 icct_nms/installation/cli/package.py --media /matching/media --output /outside/plugin/installer.zip
```

The main-ZIP launcher selects matching media or the destination repositories automatically. Prepared target-specific release ZIPs still reject mismatched targets and require explicit `--online` on source-only releases. `--offline` on the main-ZIP launcher prevents all repository fallback. `--application-only` installs the application without provisioning collector services.

## Cacti data reuse (1.4.27)

The plugin observes Cacti's native `poller_output` hook without changing its update array or consuming its output tables. Exact raw samples are associated dynamically with the owning device, collector, SNMP connection, data source and numeric OID. Fresh compatible samples are reused by inventory, interface collection and direct SNMP alarm reads. Native `rrdtool_function_fetch()` remains the source for graph-based readings.

Fresh untransformed query-cache input fields are matched using native query definitions and actual indexes. Rows predating an observed SNMP connection/security change are excluded; filtered index queries are not treated as complete interface inventories. Partially covered columns request only missing instances. Additional observations are shared across plugin collectors within one collection cycle. Unknown or late samples owned by a compatible native polling item remain unknown/stale; they do not cause a second poll or become zero. Sources with a slower cadence than the requested collection, ambiguous values, or lost Counter64 precision do not replace the required measurement.

Query reindex values are not live Counter64 samples. Formatted labels also cannot prove raw octets, so the original byte-accurate interface identity checks still collect raw bytes where Cacti has not retained them. Standard MIB OIDs and ASN.1 syntax remain protocol definitions; device IDs, query IDs, indexes, paths, credentials and polling settings are never inferred from example devices. No additional worker or daemon is introduced.

Source timestamps, real zero traffic, reboot/discontinuity checks, stale states, interface mappings and native Cacti authorization are retained. Existing graphs, protocol settings and page behavior are unchanged.

## Discovery evidence reuse (1.4.28)

Extended network discovery waits for native Cacti automation to finish before enriching its results. Native scheduling, notification and automatic device creation remain in charge. Jobs bind the network and credential configuration by signature, wait through native startup, and report a timed-out or changed native run without launching a competing scan. Per-network locks prevent concurrent Discover Now requests; scheduled-run markers prevent a second enrichment job for the same run.

Existing devices are matched by an unambiguous endpoint on the assigned collector and checked against native device permissions. Compatible native identities, ICMP observations and completed automation results are consulted before additional requests. Discovery requests only missing identity fields, requested TCP scans and neighbour information that has no assigned collector. Fresh assigned CDP/LLDP snapshots are reused; pending, failed or stale assigned snapshots remain owned by that collector. Native absence is not fabricated into a successful discovery. Data that native Cacti has discarded or never retained may still require collection; explicit retries and different SNMP security contexts are separate observations.

GNSS SNMP readings use the same native sample bridge, retaining the oldest actual source timestamp for each fix. A faster configured GNSS interval collects only the cadence that the native source cannot supply. The existing location worker refreshes its in-memory evidence each iteration. Inventory observers serialize change detection per collector to prevent duplicate events.

The discovery UI and native template matching rules are unchanged. Unknown device models still require appropriate native matching rules/templates. Physical GNSS receivers and a second remote collector require site-specific acceptance testing; fixture tests do not replace hardware validation.
