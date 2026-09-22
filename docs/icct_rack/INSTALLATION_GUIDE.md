# ICCT Rack Topology — Installation Guide

## 1. What this ZIP changes

Only one new directory is added to Cacti:

`<CACTI_ROOT>/plugins/icct_rack/`

**No default Cacti PHP file is edited.** No change is required in `host.php`, `include/config.php`, `lib/*`, or the Cacti database schema itself.

The plugin creates its own three MariaDB tables when installed:

- `plugin_icct_rack_racks`
- `plugin_icct_rack_placements`
- `plugin_icct_rack_audit`

It also stores two plugin settings in Cacti's existing `settings` table.

## 2. Before installing

1. Confirm the Cacti web application itself works.
2. Confirm you can log in with an administrator account.
3. Back up the Cacti database before installing any new plugin.
4. Do not rename the folder. It must remain `icct_rack` because the plugin name, hooks, realms and paths use that name.

## 3. Windows 11 home laptop

Your Cacti root is expected to be:

`C:\Apache24\htdocs\cacti`

Extract/copy the folder so this exact file exists:

`C:\Apache24\htdocs\cacti\plugins\icct_rack\setup.php`

Also verify:

`C:\Apache24\htdocs\cacti\plugins\icct_rack\INFO`

Do not put the files one directory too deep. This is WRONG:

`...\plugins\icct_rack\icct_rack\setup.php`

## 4. RHEL office server

Copy the same `icct_rack` folder into the `plugins` folder under the Cacti root used by your RHEL installation.

Examples seen in Cacti deployments include:

- `/usr/share/cacti/plugins/icct_rack`
- `/var/www/html/cacti/plugins/icct_rack`

Use the path that contains your office Cacti `include/`, `lib/`, `host.php`, and `plugins/` directories. Do not hard-code one of the example paths if your RPM package uses another path.

Set ownership/permissions to match the other installed plugin folders. On systems where Apache runs as `apache`, a typical example is:

```bash
chown -R apache:apache <CACTI_ROOT>/plugins/icct_rack
find <CACTI_ROOT>/plugins/icct_rack -type d -exec chmod 755 {} \;
find <CACTI_ROOT>/plugins/icct_rack -type f -exec chmod 644 {} \;
```

If your package files are intentionally owned by another service/package account, match that existing ownership instead.

## 5. Install inside Cacti

1. Log in to Cacti as an administrator.
2. Open **Console → Configuration → Plugins**.
3. Find **ICCT Rack Topology** / `icct_rack`.
4. Click **Install**.
5. Then click **Enable**.
6. The install function creates the plugin tables automatically.
7. A **Rack** entry/icon should be available through the registered Cacti top-header hook.

If the plugin is not listed, check that both `INFO` and lowercase `setup.php` exist directly inside `plugins/icct_rack/`.

## 6. Permissions

The plugin registers two Cacti Realm Permissions:

- **View ICCT Rack Topology**
- **Manage ICCT Rack Topology**

The installing/admin account receives access by default. For other users, open the Cacti user/realm permission screen and assign the appropriate realm.

Recommended mapping for the ICCT requirement:

- Network Operator: View only.
- Network Administrator: View, and Manage only if rack placement/configuration is part of the approved network-admin duties.
- System Administrator: View + Manage.

The plugin deliberately uses Cacti authentication and realm permissions instead of creating another username/password/role table.

## 7. First use

1. Add real or simulated devices to Cacti first from Cacti's normal **Devices** page.
2. Open **Rack → Manage racks**.
3. Create a rack, e.g. code `ICCT01-R01`, 42U.
4. Choose a Cacti device in **Place Cacti device in rack**.
5. Enter Start U and device height.
6. Choose Front/Rear and device category.
7. Save.
8. Open the Rack topology viewer.
9. Device status is read from the normal Cacti `host` record, so the rack view follows Cacti monitoring status rather than maintaining a second status database.

## 8. Drag and drop

Users with **Manage ICCT Rack Topology** can drag a device block onto another U position. The browser sends the requested move to `ajax.php`; the backend re-validates:

- rack exists,
- U range fits inside the rack,
- no overlap on the selected face,
- the device is a valid Cacti host.

A failed move is rejected and no database update is performed.

## 9. Database notes

Normally, do not manually import SQL. Cacti Plugin Management calls the schema creation code in `lib/database.php`.

`database/schema.sql` is included for review/recovery/testing only.

The plugin does not create a foreign-key constraint against Cacti's `host` table. It stores `host_id` as a logical reference. This reduces coupling to Cacti core schema upgrades while still using `host` as the canonical device record.

## 10. Moving the plugin from home Windows to office RHEL

For code deployment:

1. Disable the old `icct_rack` plugin in office Cacti if you are replacing an earlier version.
2. Back up the old plugin directory and database.
3. Copy/replace only the `plugins/icct_rack` folder.
4. Restore normal file ownership/permissions.
5. Re-enable the plugin.
6. Open Plugin Management and run/check upgrade if Cacti indicates one is required.

**Do not uninstall just to update the folder.** Uninstall/reinstall can be destructive for many Cacti plugins, and this plugin intentionally preserves its own tables on uninstall as an additional safeguard.

The same PHP/JS/CSS files are designed to run on both Windows and RHEL. The plugin uses Cacti's database connection, relative plugin paths and Cacti APIs; it contains no `C:\...` or `/usr/share/...` runtime path dependency.

## 11. Important: plugin code vs rack data

Copying the plugin folder transfers the **code**, not the rack records from your home database.

On the office server, the plugin will create empty rack tables and then use the office Cacti device IDs. This is normally safer because the `host.id` values can differ between your home simulation and the office system.

Do not import home placement rows into office unless the Cacti host IDs have been deliberately synchronized/mapped.

## 12. Manual removal of all plugin data

The normal plugin uninstall intentionally leaves the three plugin tables in place to protect data from an accidental uninstall.

If you intentionally want to permanently erase all Rack Topology data, first back up the database and then run `database/uninstall.sql` manually.

## 13. Upgrade compatibility

The plugin's minimum target is Cacti 1.2.31 and it avoids Cacti core modifications so it can be tested against newer 1.2.x community releases.

As of 2026, Cacti 1.2.31 contains security fixes for vulnerabilities affecting 1.2.30. Upgrade the home and office Cacti installations to 1.2.31+ before deploying this plugin; do not use 1.2.30 in production.

## 14. Troubleshooting

### Plugin does not appear

Check:

- folder is exactly `plugins/icct_rack/`
- `INFO` exists
- `setup.php` is lowercase and readable
- no nested `icct_rack/icct_rack` folder

### Install reports configuration issue

Confirm MariaDB user used by Cacti has CREATE/ALTER/INSERT/UPDATE permissions for the `cacti` database, then check Cacti's log.

### Rack opens but has no devices to place

Create devices in normal Cacti first. The plugin intentionally does not create a duplicate inventory.

### Device status is Unknown

Run the Cacti poller and confirm the device has a normal Cacti availability status. The Rack plugin does not ping/SNMP-poll devices independently.

### Drag/drop rejected

Most common causes are overlap with another device, moving a multi-U device too near the rack top, or insufficient Manage realm permission.

---

## Upgrade from 1.0.0 to 1.0.1 (navigation fix)

Version 1.0.0 registered only the top-header hook and did not register Cacti's `config_arrays` hook, so the plugin could be Active in Plugin Management while no Console sidebar item appeared. Also, `index.php` intentionally redirected to the Cacti home page.

To ensure Cacti rebuilds the plugin hooks cleanly:

1. In **Console > Configuration > Plugins**, Disable `icct_rack`.
2. Click **Uninstall** for `icct_rack`.
   - The plugin's rack/placement/audit tables are intentionally preserved by the plugin uninstall routine.
3. Replace the old `plugins/icct_rack/` directory with the 1.0.1 directory.
4. Return to **Console > Configuration > Plugins**.
5. Click **Install** and then **Enable**.
6. Log out and log back in (or hard-refresh with Ctrl+F5).
7. You should now see:
   - **Console > Management > Rack Topology**
   - **Console > Configuration > Rack Topology Management** (for authorized users)
   - a Rack tab/icon in the Cacti header
8. Direct URL: `/cacti/plugins/icct_rack/icct_rack.php`

No Cacti core file needs to be edited.

## Upgrade from 1.0.1 to 1.0.2 (security and data-integrity fixes)

1. Back up the Cacti database and the existing `plugins/icct_rack/` directory.
2. Replace the plugin folder with the 1.0.2 folder from this package.
3. Restore the same ownership, permissions, and SELinux context as the other Cacti plugins.
4. In Cacti Plugin Management, run the plugin upgrade/check and keep the plugin enabled.
5. Verify drag/drop, disabled-rack visibility, deleted-host handling, and device details using `docs/TEST_CHECKLIST.md`.

The upgrade preserves the existing rack, placement, and audit tables. No Cacti core file needs to be edited.
