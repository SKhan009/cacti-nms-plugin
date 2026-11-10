# Installation and dependency setup

Install Cacti itself first, then copy the complete `icct_nms` directory into its `plugins` directory. All application pages, PHP vendor libraries, browser scripts, fonts and standard MIB files are shipped locally. No npm/composer/CDN download runs during page use.

Cacti 1.2.31 is the tested release. Other Cacti 1.2 releases can install when their native database and plugin APIs are available. PHP 8.0 or newer and the required PHP extensions are needed by the application. Installation errors identify the detected PHP runtime and missing extensions or Cacti APIs.

## Provisioned Linux setup

Run inside the **target** Cacti installation:

```sh
python3 plugins/icct_nms/installation/cli/setup.py plan --cacti-root /path/to/cacti
sudo python3 plugins/icct_nms/installation/cli/setup.py install --no-packages --cacti-root /path/to/cacti
```

Provision dependencies from approved offline media first, or supply a verified bundle as shown below. An install without `--bundle`, `--no-packages` or explicit `--online` is rejected and never contacts a repository. For an online target, use `sudo python3 plugins/icct_nms/installation/cli/setup.py install --online --cacti-root /path/to/cacti`; this installs from the configured trusted repositories. The explicit install command installs/enables the plugin through Cacti's native lifecycle, makes the two upload/work directories writable, installs worker units with this installation's paths and account, and configures and validates the Syslog receiver without injecting test messages into production history. Source code, database credentials and device credentials are not copied into a second configuration. Existing service units are backed up before replacement. Existing devices, presets, timezone, NTP peers, SSH keys and host firewall policy are preserved. NTP monitoring grants the collector account only the fixed chrony tracking, source-statistics and peer-data reads through `/run/chrony/chronyd.sock` as the chrony user; no wildcard sudo commands or privileged web agent are added.

Supported package-manager adapters: RHEL/Rocky/AlmaLinux with DNF; Debian/Ubuntu with APT; both require systemd for automatic worker setup. Extra repositories may be needed for nfdump, hping3 or netperf. The installer does not silently enable third-party repositories, skip unavailable packages or claim success when a service fails. Provision approved repositories first. Use `--web-user` and `--web-group` for a custom PHP service account. Use `--no-packages` when packages are already supplied by the administrator.

Open **Settings → Installation Checks → Checks** with native Installation/Upgrades permission. It shows real binaries, bundled assets, current web PHP extensions and worker state. Run `php plugins/icct_nms/installation/cli/check.php` for the CLI PHP environment; web and CLI can have different extensions. Repeat setup on every remote collector using that collector's own Cacti configuration/poller ID.

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

The installer provisions the verified packages before checking PHP CLI. On RHEL, `php-mbstring`, `php-mysqlnd` and `php-xml` supply the corresponding PHP extensions. If Plugin Management reports an extension missing after provisioning, check the web PHP service separately from CLI and reload the web PHP runtime that serves Cacti so it loads the installed modules.

## Requirements which packages cannot finish

- SSH uses the existing strict-auth Guacamole adapter, HTTPS origin and dedicated account. A stock guacd package is insufficient. Follow `protocols/ssh/README.md`; checks do not mark this ready just because a binary exists.
- Pathchar needs a compatible `pchar` binary from an approved source; it is not available in every base repository. No arbitrary binary download is performed.
- RHEL NTP monitoring needs read access to the existing chrony socket. TACACS+ tools are available as packages, while plugin authentication integration is pending.
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

### Optional protocol dependencies

Installation Checks now inventories Redis, Python WebSocket support, NTP client tools, TACACS+ tools, Apache Guacamole and FTP/TFTP/Telnet clients separately from application integration. Installed dependencies have no Install button. Package-only setup does not configure plugin features, PAM, time sources or firewall rules. Repository package scripts may apply their distribution defaults.

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

Build a fresh system installer from a matching media directory with `packages/`, every available `components/<profile>/` bundle and both `native/` bundles:

```sh
python3 installation/cli/package.py --media /path/to/extracted-media --output /tmp/icct-nms-complete-offline.zip
```

Extract the ZIP outside the web root. Run `python3 install.py --cacti-root /path/to/cacti --plan` to check the target and list the package and native profiles, then run the same command as root without `--plan`. On RHEL this also provisions the **Offline Packages** runner using the verified media. On a fresh RHEL collector, add `--trap-auth-file /path/to/private-authorization-file --trap-listener udp:COLLECTOR_IP:162` with the actual collector IP to enable the authenticated SNMP trap receiver and worker. Add `--ssh-origin https://nms.example.org` when the SSH broker has a real HTTPS origin. Site authorization files stay separate from the release.

BLR §5.3.2.5.1 includes SFTP. Rebuild the transfer profile to include the OpenSSH client alongside FTP, TFTP and Telnet tools. Device file transfer and Telnet workflows, TACACS+ authentication and vendor-specific adapters require application integration and device acceptance tests.

For online setup, `setup.py install --online --all-components` installs the core dependencies plus all available protocol package profiles. This includes Redis, Python WebSocket support, NTP tools, TACACS+ tools and FTP/TFTP/Telnet clients. RHEL also offers the distribution Guacamole package; Debian uses the verified native build instead. A package being installed does not imply its application backend is implemented.

For a complete offline release, add repeated `--component-bundle /path/to/profile-bundle` arguments to `package.py` together with the core `--bundle` and native bundles. The single resulting archive carries the profile manifests and packages outside the web root. Its bootstrap validates every selected profile's OS/CPU, component ID and SHA-256 hashes before updating source or invoking a package manager, then automatically forwards them to setup. The read-only `install.py --plan` lists the selected components.

Direct offline setup accepts the same repeated `--component-bundle` arguments. Adding `--all-components` requires every available profile bundle and rejects incomplete sets before making changes. Existing Cacti pages, credentials and records are preserved. Debian's package manager is instructed not to remove existing packages; a conflicting installed time daemon must be resolved by the administrator rather than silently replaced.

### Runtime and distribution compatibility

Python 3.9+ is required by setup and the collectors. Installation Checks obtains the measured Python version from the local collector snapshot; missing, old, unmeasured or non-executable runtimes are not reported ready. The web page does not execute Python to probe it. Setup, native build/install, archive packaging and release deployment reject an old Python runtime before mutations.

Package profiles resolve distribution-specific names rather than applying Debian 12 names to every APT system. Ubuntu uses `libjpeg-turbo8`; Ubuntu 24.04+ and Debian 13+ use `libssh2-1t64`. The resolved package list is included in and checked against the offline bundle manifest. Debian 12 and RHEL package lists remain unchanged. Ubuntu source builds and bundles must be produced on Ubuntu with the matching release and CPU, not reused from Debian.

APT NTP profiles use `ntpsec-ntpdig`, a client-only query tool, so installing protocol dependencies does not replace an existing systemd-timesyncd, chrony or ntpd service. Checks also recognize existing chronyc/ntpdig/ntpq tools. RHEL retains its chrony package profile. Setup never invokes a command to set the system clock or rewrite time peers.

### Preserve the working plugin during setup

The release source is aligned with the running VM's public application source, including its database-backed diagnostics queue and shared UI helpers. Installing a Redis dependency does not switch diagnostics to another backend. Local credentials, MIB sessions, generated plugin query XML and uploaded device images are excluded from release archives and rejected as deployment targets. Existing source is backed up before replacement, and the bootstrap refuses a plugin version downgrade.

Setup provisions only the required writable application paths: MIB storage, uploaded device images and Cacti's `resource/snmp_queries/icct_nms` directory. On SELinux systems it preserves enforcement and applies persistent writable labels only to those paths. Other application source stays administrator-owned and read-only to the web account.

## Offline SSH service setup

Include a verified `guacamole-strict` native bundle and matching OS package bundle in the release, then add `--ssh-origin https://nms.example.org` to `install.py`. The installer forwards the exact origin, verifies the strict build and starts the dedicated broker and loopback Guacamole services. RHEL bundles include SELinux policy build tools; enforcement stays enabled. See `protocols/ssh/README.md` for explicit existing-store and account options. Without an explicit origin, setup leaves SSH configuration unchanged and Installation Checks reports its measured state.

## Read-only NTP statistics on offline RHEL

The standard installer provisions chrony, sudo and an exact read-only permission for the collector account. It never edits chrony sources, enables/restarts chronyd or adjusts a clock. Existing offline bundles must be rebuilt for this release's dependency manifest.

For a remote RHEL device, copy the bundled `installation/cli/ntp_access.py` to that device and run it for the **saved SSH monitoring account**, after provisioning chrony and sudo from approved offline media:

```sh
python3 ntp_access.py plan --user monitoring_account
sudo python3 ntp_access.py install --user monitoring_account
```

The fixed policy permits only tracking, source-statistics and peer-data reads as `chrony`, through the explicit `/run/chrony/chronyd.sock` Unix socket. There are no wildcard arguments or UDP/transport fallbacks. Existing SSH host-key approval remains required. NTP collection does not enable SSH health monitoring. Missing permission, insufficient samples or a source change produces an explicit collection error without substitute measurements.

Jitter is chrony's selected-source residual offset standard deviation (`sourcestats`, in milliseconds), with its sample count and span stored alongside it. Peer delay is the measured round-trip delay to that same selected NTP peer (`ntpdata`), distinct from root delay to the ultimate reference. Tracking brackets both reads; a source/reference update invalidates the collection. All measurements and errors are stored per inventory device. Older history retains missing jitter/delay as unknown and is never backfilled with zero or RMS offset.

## Offline Packages in the browser

**Checks** reports application and collector readiness. **Offline Packages** is the RHEL package manager. The page stays read-only on Windows WAMP and other hosts without the RHEL runner.

Keep the complete media folder outside the web root. It has `packages/` for core dependencies and `components/{redis,websocket,ntp,tacacs,guacamole,transfer}/` for the individual verified bundles. See the bundle commands above to build media for a different OS release or CPU. Native Guacamole and Pathchar bundles, when supplied in `native/`, use the native installer described above.

After installing or enabling the plugin, provision its package runner once as a system administrator. Substitute the actual Cacti root, PHP account and extracted media directory.

```sh
sudo /usr/bin/python3 /var/www/html/cacti/plugins/icct_nms/installation/cli/package_runner.py provision \
  --cacti-root /var/www/html/cacti --web-user apache --media /media/icct-rhel98-aarch64-package-media
```

The runner verifies all bundles, installs missing runner prerequisites from the local media, keeps a private copy of the media and starts a systemd timer. The target needs Cacti, PHP and the RHEL system Python/DNF runtime for this initial provisioning. The browser submits only an allowlisted operation through Cacti's authenticated Installation/Upgrades page. It shows the runner's actual RPM checks, transaction selection and verification. RPM operations use local media only and stop if installation would replace an installed package. Service configuration and plugin activation are separate.

**Uninstall** applies to eligible tools installed by this runner. It protects pre-existing packages and core dependencies and rejects transactions that would remove additional packages. Runner failures appear in the progress dialog and in `journalctl -u icct-nms-package-runner.service`.


In **Presets → Protocols → Syslog**, enter the receiver port and select **Save**. This control requires the updated RHEL offline
runner, provisioned by the complete offline installer. The default is 514.
The runner updates TCP, UDP, and any enabled local journal forwarding together.
Use the same port on sending devices and in the site firewall. On SELinux
Enforcing hosts, the selected port must be permitted for rsyslog before applying
it. The change requires no repository or internet access.
