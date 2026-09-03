# RHEL missing NMS menu repair — offline package 1.9.33

## What the attached log establishes

- The installation is `/usr/share/cacti`, not `/var/www/html/cacti`.
- Apache and PHP-FPM run as `apache:apache`, not `www-data`, `www` or `wwwrun`.
- PHP is 8.0.30 and MariaDB is 10.5.22. The log does not establish the Cacti version.
- NMS tables already exist. Their presence does not prove the plugin is enabled,
  that its hooks are registered, or that the signed-in user has permission.
- A 403 from `/cacti/plugins/nms/` can mean directory listing is disabled, because
  the previous release had no index.php. Test `nms.php`, not just the directory.
- The disk-query RECACHE warnings and SNMP notification warning in the log do not
  demonstrate a failure of NMS menu registration. No PHP fatal error is shown.

The code previously had no Console sidebar hook, and enable/upgrade did not
re-register missing hooks. Version 1.9.32 repairs registration on those lifecycle
paths, adds the NMS sidebar, fixes breadcrumb definitions, uses absolute auth
include paths and supplies a directory redirect. Existing user permissions remain
in place; this does not grant every Cacti user administrative NMS access.

## Before starting

This release requires **Cacti 1.2.31 or newer**. Check your Cacti version under
Console → Utilities → System Utilities → Technical Support. If older, stop and
plan a supported Cacti upgrade; do not edit INFO to hide the requirement.

Take a database backup using your normal database backup procedure before running
plugin migrations. The file backup below is not a database backup. Do not uninstall
NMS: its uninstall routine removes plugin tables. Do not use chmod 777, disable
SELinux or change permissions on all of Cacti.

## Offline installation on the server in the log

Transfer `NMS-Plugin-1.9.33-RHEL-OFFLINE.tar.gz` by USB or your normal file transfer to
`/home/bstc/Downloads/`. The package contains an `nms/` directory. Run the following
in the RHEL terminal, not in the MariaDB prompt. No internet or Python is needed.

1. Confirm the target and extract the package into a new temporary directory:

```bash
test -f /usr/share/cacti/include/global.php || exit 1
test -f /usr/share/cacti/cli/plugin_manage.php || exit 1
NMS_STAGE=$(mktemp -d /tmp/nms-repair.XXXXXX)
tar -xzf /home/bstc/Downloads/NMS-Plugin-1.9.33-RHEL-OFFLINE.tar.gz -C "$NMS_STAGE"
find "$NMS_STAGE/nms" -type f -name '*.php' -exec php -l {} \;
```

Stop if any file has a syntax error. Keep using the same terminal so NMS_STAGE
still refers to this extracted package.

2. Back up the existing plugin and replace its code:

```bash
sudo install -d -m 0700 /var/backups/cacti-nms
NMS_BACKUP=$(sudo mktemp -d /var/backups/cacti-nms/pre-1.9.33.XXXXXX)
sudo cp -a /usr/share/cacti/plugins/nms "$NMS_BACKUP/"
echo "Plugin code backup: $NMS_BACKUP/nms"
sudo cp -a "$NMS_STAGE/nms/." /usr/share/cacti/plugins/nms/
```

This overlays code without uninstalling or deleting plugin data. Do not copy the
package's `nms` directory inside the existing `nms` directory.

3. Restore safe code permissions and the configured SELinux labels:

```bash
sudo chown -R root:apache /usr/share/cacti/plugins/nms
sudo find /usr/share/cacti/plugins/nms -type d -exec chmod 0750 {} \;
sudo find /usr/share/cacti/plugins/nms -type f -exec chmod 0640 {} \;
sudo restorecon -RF /usr/share/cacti/plugins/nms
sudo -u apache test -r /usr/share/cacti/plugins/nms/setup.php && echo 'Apache can read NMS'
```

Plugin PHP code should not be writable by Apache. These commands do not alter the
separate SNMPSim records/configuration, Cacti RRA directory or database credentials.
If SELinux denials persist, inspect `ls -ldZ /usr/share/cacti/plugins/nms` and
`sudo ausearch -m AVC -ts recent`. Have the administrator correct the file-context
policy for this web-content directory rather than disabling SELinux.

4. Repair hooks and enable the already-installed plugin through Cacti's own CLI:

```bash
sudo -u apache php /usr/share/cacti/cli/plugin_manage.php --plugin=nms --enable
```

For a genuinely new installation only, run `--plugin=nms --install` first, then
run the enable command above. Existing NMS tables alone are not an installation
status check. Do not use `--uninstall` or broad permission-grant commands.

Alternatively use Configuration → Plugins in Cacti: disable then enable NMS.
Enabling the updated code now repairs hooks as well as running schema setup.

5. Clear cached PHP code (briefly affects PHP requests), then sign out of Cacti
and back in and hard-refresh your browser:

```bash
sudo systemctl reload php-fpm
```

Open `http://localhost/cacti/plugins/nms/nms.php` on RHEL, or replace localhost with
the RHEL server address from another computer. Expected Console sidebar:

```text
NMS
  Device readings
  Devices
  Fault Configuration
  Topology
```

The top NMS tab remains available as well. If the plugin is enabled but hidden for
your account, a Cacti administrator must grant that account the realm
**View NMS Faults, Devices, Rules, and Topology** under user permissions. The separate
Configuration → Plugins menu itself requires Cacti's plugin-management permission;
NMS cannot override that permission.

## If it is still hidden

From your existing MariaDB administrative session, select the actual Cacti database
and run these read-only checks. Do not manually INSERT/DELETE plugin rows.

```sql
USE cacti;
SELECT cacti FROM version;
SELECT directory, version, status FROM plugin_config WHERE directory = 'nms';
SELECT hook, `function`, file, status FROM plugin_hooks WHERE name = 'nms';
SELECT id, file, display FROM plugin_realms WHERE plugin = 'nms';
```

Expected: plugin version 1.9.33 and status 1, seven enabled hooks (including
config_arrays and top_graph_header_tabs), and one realm covering all four pages.
If these are correct, check the specific user's realm permissions and PHP logs.

```bash
curl -I http://localhost/cacti/plugins/nms/nms.php
sudo tail -n 80 /var/log/httpd/error_log
sudo journalctl -u php-fpm -n 80 --no-pager
sudo tail -n 80 /var/log/cacti/cacti.log
```

A redirect to login is normal without a browser session. A 403 on the actual PHP
file needs Apache/SELinux/access-policy investigation; a 500 needs the PHP error
log. These have different causes from a disabled or unauthorized menu. Share only
relevant error lines and redact credentials/cookies.

## Regression checks and upstream contracts

`php tests/plugin_navigation_test.php` runs without a database. It checks repeatable
registration, Cacti's lifecycle caller restriction, enabled/disabled state,
realm-controlled tabs, native menu links, custom URL prefixes and breadcrumbs.

The implementation follows Cacti's own [plugin lifecycle API](https://github.com/Cacti/cacti/blob/release/1.2.31/lib/plugins.php)
and [plugin management CLI](https://github.com/Cacti/cacti/blob/release/1.2.31/cli/plugin_manage.php).
RHEL Apache, SELinux, database migrations and a live browser must still be verified
on the affected server after deployment; local PHP tests do not prove those checks.
