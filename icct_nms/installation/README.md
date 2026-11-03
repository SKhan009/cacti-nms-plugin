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


### Optional protocol dependencies

Installation Checks now inventories Redis, Python WebSocket support, chrony, TACACS+ tools, Apache Guacamole and FTP/TFTP/Telnet clients separately from application integration. Installed dependencies have no Install button. Package-only setup does not configure plugin features, PAM, time sources or firewall rules. Repository package scripts may apply their distribution defaults.

Use `setup.py install --component redis --online` (also `websocket`, `ntp`, `tacacs`, `guacamole`, `transfer`). Build an offline dependency bundle with `setup.py bundle --component redis --bundle /outside/webroot/redis-bundle`; install with `setup.py install --component redis --bundle /outside/webroot/redis-bundle`. Each bundle is pinned to its component, OS release and architecture; existing base bundles remain compatible.

Guacamole custom builds under `/opt/nms-guacamole/1.6.0` are recognized without replacing the running daemon. A stock guacd package does not satisfy the SSH strict-auth policy. Debian has no automatic Guacamole repository profile; use the approved native build guide. Debian TACACS+ profile installs a client library, while RHEL installs server tools; neither changes login authentication.

BLR §5.2.2.3 requires Telnet, SSH, FTP, TFTP, NTP, TACACS+, NetFlow/IPFIX and SNMP/discovery protocols. §5.3.3.3 requires NTP node statistics (sync, source, offset, jitter, delay), which remain distinct from chrony being installed. Redis and WebSocket are supporting implementation dependencies requested for this project, not named BLR requirements.

Permission checks cover required writable upload directories and critical source files. Keep application source owned by an administrator and not writable by the HTTP/collector account; keep only the documented storage directories writable. Existing service accounts, SELinux enforcement and pchar raw-socket capability are preserved.

### Native dependencies in complete offline releases

Pinned Guacamole Server 1.6.0 and pchar 1.5 source archives are shipped under `installation/native-source`. Guacamole's Apache signature was verified using signing-key fingerprint `F467E54ACC52F1D2778826865B2977AEE5E4518F`; source and patch SHA-256 pins are enforced by `cli/native.py`. pchar includes a two-line modern C++ correction for ambiguous unsigned `abs()` calls. Upstream license/NOTICE/README files are retained in native bundles.

Build without root on a matching OS release/CPU:

```sh
python3 installation/cli/native.py build --component guacamole-strict --bundle /tmp/guacamole-native
python3 installation/cli/native.py build --component pathchar --bundle /tmp/pathchar-native
```

Guacamole build dependencies: C/C++ compiler, make, patch, pkg-config, cairo/png/jpeg/pango/OpenSSL/libssh2/libuuid development headers. Pathchar needs a C++ compiler and make. No build downloads are performed; missing build tools stop the build. Sources are extracted with traversal/link checks. Bundles contain regular files only and are pinned to OS release and architecture.

Install finished bundles with `sudo python3 installation/cli/native.py install --bundle /tmp/guacamole-native --web-group apache`. Substitute the real collector group. Native files go under `/opt/icct-nms/native`; conflicting existing builds are rejected. Pathchar receives only `cap_net_raw` and group execution access, never setuid-root. Existing `/usr/local/sbin/pchar` and running Guacamole services are retained.

Add either/both `--native-bundle` options when building a complete release with `package.py --bundle /path/to/os-packages`. The release bootstrap verifies native artifacts before mutation and forwards them to setup automatically. Runtime libraries must be present in the matching OS package bundle. Direct setup also accepts repeated `--native-bundle` arguments. Build/install operations preserve application credentials and HTTPS configuration; SSH broker activation still requires a real HTTPS origin.

### One installer with all protocol package profiles

For online setup, `setup.py install --online --all-components` installs the core dependencies plus all available protocol package profiles. This includes Redis, Python WebSocket support, NTP tools, TACACS+ tools and FTP/TFTP/Telnet clients. RHEL also offers the distribution Guacamole package; Debian uses the verified native build instead. A package being installed does not imply its application backend is implemented.

For a complete offline release, add repeated `--component-bundle /path/to/profile-bundle` arguments to `package.py` together with the core `--bundle` and native bundles. The single resulting archive carries the profile manifests and packages outside the web root. Its bootstrap validates every selected profile's OS/CPU, component ID and SHA-256 hashes before updating source or invoking a package manager, then automatically forwards them to setup. The read-only `install.py --plan` lists the selected components.

Direct offline setup accepts the same repeated `--component-bundle` arguments. Adding `--all-components` requires every available profile bundle and rejects incomplete sets before making changes. Existing Cacti pages, credentials and records are preserved. Debian's package manager is instructed not to remove existing packages; a conflicting installed time daemon must be resolved by the administrator rather than silently replaced.
