# Installation and dependency setup

Install Cacti itself first, then copy the complete `icct_nms` directory into its `plugins` directory. All application pages, PHP vendor libraries, browser scripts, fonts and standard MIB files are shipped locally. No npm/composer/CDN download runs during page use.

Cacti 1.2.31 and PHP 8.0 are the tested baseline. Older Cacti/PHP versions are rejected before schema installation. Newer releases must pass checks and their own compatibility testing; this does not claim compatibility with every Cacti version, Windows service manager or arbitrary Unix distribution.

## Provisioned Linux setup

Run inside the **target** Cacti installation:

```sh
python3 plugins/icct_nms/installation/cli/setup.py plan --cacti-root /path/to/cacti
sudo python3 plugins/icct_nms/installation/cli/setup.py install --no-packages --cacti-root /path/to/cacti
```

Provision dependencies from approved offline media first, or supply a verified bundle as shown below. An install without `--bundle`, `--no-packages` or explicit `--online` is rejected and never contacts a repository. For an online target, use `sudo python3 plugins/icct_nms/installation/cli/setup.py install --online --cacti-root /path/to/cacti`; this installs from the configured trusted repositories. The explicit install command installs/enables the plugin through Cacti's native lifecycle, makes the two upload/work directories writable, installs worker units with this installation's paths and account, and configures/capture-tests Syslog. Source code, database credentials and device credentials are not copied into a second configuration. Existing service units are backed up before replacement. Existing devices, presets, timezone, NTP peers, SSH keys and host firewall policy are preserved. No global sudoers rule or privileged web agent is added.

Supported package-manager adapters: RHEL/Rocky/AlmaLinux with DNF; Debian/Ubuntu with APT; both require systemd for automatic worker setup. Extra repositories may be needed for nfdump, hping3 or netperf. The installer does not silently enable third-party repositories, skip unavailable packages or claim success when a service fails. Provision approved repositories first. Use `--web-user` and `--web-group` for a custom PHP service account. Use `--no-packages` when packages are already supplied by the administrator.

Open **Settings → Installation Checks** with native Installation/Upgrades permission. It shows real binaries, bundled assets, current web PHP extensions and worker state. Run `php plugins/icct_nms/installation/cli/check.php` for the CLI PHP environment; web and CLI can have different extensions. Repeat setup on every remote collector using that collector's own Cacti configuration/poller ID.

## Offline bundle

Build on an online machine with the **same OS ID, release and CPU architecture** as the offline target. Copy the plugin there before building. On DNF systems install `dnf-plugins-core` first; on APT systems the build requires root to resolve packages.

```sh
sudo python3 plugins/icct_nms/installation/cli/setup.py bundle --bundle /tmp/icct-packages
```

The bundle includes dependencies even when they are already installed on the build server. It contains `bundle.json` with OS/release/architecture, requested packages and SHA-256 hashes. Transport the entire directory through your approved media together with this plugin release. Store packages outside the web root; `installation/bundles` is denied by Apache but is not a public download location.

On the offline server:

```sh
python3 plugins/icct_nms/installation/cli/setup.py plan --bundle /media/icct-packages
sudo python3 plugins/icct_nms/installation/cli/setup.py install --bundle /media/icct-packages
```

Installation refuses a mismatched target, missing/changed packages or an empty bundle. Offline DNF disables repositories. Offline APT uses empty repository sources/lists and only the verified local DEBs; no remote package repository can be used. Hashes detect transport changes; they are not a substitute for obtaining the bundle from your trusted build system. Never reuse an ARM VM package bundle on an x86 physical RHEL server.

## Requirements which packages cannot finish

- SSH uses the existing strict-auth Guacamole adapter, HTTPS origin and dedicated account. A stock guacd package is insufficient. Follow `protocols/ssh/README.md`; checks do not mark this ready just because a binary exists.
- Pathchar needs a compatible `pchar` binary from an approved source; it is not available in every base repository. No arbitrary binary download is performed.
- NTP monitoring and TACACS+ protocol backends are currently unimplemented. Chrony provides OS time synchronization only; no time source is invented or overwritten.
- Devices still need valid credentials and protocol assignments; exporters must point to the reachable collector IP/port. Listening sockets or installed packages alone do not prove actual device data collection.
- Serial/USB access, raw-socket diagnostic permissions and externally managed firewall rules depend on local policy; verify them on the target. Runtime checks report missing components instead of simulating success.


## One archive with plugin and dependencies

Build a release outside the plugin directory:

```sh
python3 installation/cli/package.py --bundle /path/to/verified-packages --output /tmp/icct-nms-offline.zip
```

Extract the archive outside the web root, then run its single bootstrap:

```sh
python3 install.py --cacti-root /path/to/cacti --plan
sudo python3 install.py --cacti-root /path/to/cacti
```

The bootstrap verifies the plugin files and every bundled package, rejects mismatched OS/release/CPU bundles, backs up an existing plugin to a private archive, copies application code atomically and runs the native plugin/package/service installer. Package files remain in the extracted release outside the web root. Existing local configuration, MIB sessions and uploaded device images are excluded from release builds and retained during deployment. Developer tests and caches are not shipped. Source-only archives require explicit `--online` or `--no-packages`.

Debian 12's Netperf package requires the official `non-free` repository component. Have the administrator configure approved repositories before building/installing. The installer does not silently enable components or omit requested tools. Debian package-adapter tests are distinct from systemd service validation on a full Debian host.

For application-only deployment into an existing externally managed Cacti installation, use `python3 install.py --cacti-root /path/to/cacti --application-only`. This installs pages/schema through native Cacti APIs without touching OS packages or worker services. The full installer archive is verified first.

Chrony is not a required plugin package: automatically installing a time daemon could replace the host’s existing time-sync service. Existing OS synchronization is retained; the unimplemented NTP monitoring backend remains clearly unavailable.
