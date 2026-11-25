# ICCT NMS complete installation

The GitHub main ZIP contains the plugin, `install.py`, and all bundled offline media under `icct_nms/installation/bundles/media/{packages,components,native}`. Extract the whole ZIP outside the Cacti web root. Do not copy only the plugin folder when installing or upgrading collector services.

The launcher detects the destination Linux distribution, release and CPU architecture using the existing installer detection. It searches bundled media manifests for an exact match, including media trees in subdirectories. Folder names do not determine compatibility. When no matching offline bundle exists, the launcher uses the destination's configured DNF/APT repositories and requests only missing packages, including repository-supported optional component profiles; it reuses installed dependencies and Cacti's existing installation and lifecycle APIs. It does not add another Cacti instance or poller.

The currently supplied RPMs and native binaries target **RHEL 9.8 aarch64 (ARM64)**. Other supported RHEL-family and Debian-family Linux systems use their own repositories unless matching offline media is supplied. Wrong-OS or wrong-CPU bundles are never installed. Windows, macOS and unrecognized Linux families are rejected; automatic selection does not create missing native builds or enable unsupported integrations. The plan lists `repository_components` and `native_components_not_bundled`. Existing verified native runtimes can still be reused; strict-auth Guacamole and Pathchar need matching builds when they are absent.

From the extracted main ZIP:

```sh
python3 install.py --cacti-root /usr/share/cacti --plan
sudo python3 install.py --cacti-root /usr/share/cacti
```

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
