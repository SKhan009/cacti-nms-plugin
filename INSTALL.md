# ICCT NMS complete installation

The GitHub main ZIP contains the plugin, `install.py`, and all bundled offline media under `icct_nms/installation/bundles/media/{packages,components,native}`. Extract the whole ZIP outside the Cacti web root. Do not copy only the plugin folder when installing or upgrading collector services.

The supplied RPMs and native binaries target **RHEL 9.8 aarch64 (ARM64)**. The source supports RHEL-family and Debian-family package selection; offline bundles must match the destination OS ID, release and architecture exactly. This archive is not an x86_64 or universal binary installer. A mismatched offline target is rejected before deployment.

From the extracted main ZIP:

```sh
python3 install.py --cacti-root /usr/share/cacti --plan
sudo python3 install.py --cacti-root /usr/share/cacti
```

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

An explicit `--online` installation uses the destination's configured repositories. It is never an automatic fallback from an incompatible offline archive. `--application-only` installs the application without provisioning collector services.
