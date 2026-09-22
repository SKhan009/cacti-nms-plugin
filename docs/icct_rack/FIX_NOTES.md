# ICCT Rack Topology 1.0.2 Fix Notes

This file records the fixes applied to the package. The inline `FIX 2026-09-15` comments in the source are intentionally retained so the reason for each security/data-integrity change is visible beside the code.

| File | Lines | What was fixed |
|---|---:|---|
| `INFO` | 3, 8-9 | Bumped the package to 1.0.2 and raised the Cacti compatibility baseline to patched 1.2.31+. |
| `README.md` | 24 | Documented the 1.2.31+ requirement. |
| `setup.php` | 13-14 | Made the installer reject Cacti versions below the patched 1.2.31 baseline. |
| `setup.php` | 98-107 | Added a dedicated visible Rack Topology sidebar section and protected its menu registration when the plugin is disabled. |
| `INSTALLATION_GUIDE.md` | 158, 210-218 | Required the patched Cacti baseline and documented the safe 1.0.2 upgrade procedure. |
| `CHANGELOG` | 1-7 | Recorded the 1.0.2 security, authorization, host-filtering, schema-version, and installer-guard fixes. |
| `database/schema.sql` | 1 | Updated the schema package marker to 1.0.2. |
| `lib/database.php` | 65-66 | Aligned the stored schema version with the package version. |
| `lib/functions.php` | 52-53, 63-64, 79-91, 97-99 | Treated deleted Cacti hosts as missing and prevented deleted hosts from being selected or placed. |
| `icct_rack.php` | 17-21, 29, 171-176 | Prevented direct access to disabled racks and rendered a server-side CSRF token for drag/drop. |
| `js/icct_rack.js` | 10, 132-133 | Used the server-rendered Cacti CSRF token for drag/drop requests. |
| `ajax.php` | 23-24, 56-67, 94-97 | Blocked disabled-rack status refreshes, restricted device details to hosts placed in enabled racks, and explicitly verified CSRF on moves. |
| `icct_rack_admin.php` | 21-25, 127, 149-150, 206-207, 224-225, 247-248, 327-328 | Explicitly verified CSRF on admin writes and included the token in every POST form. |

## Validation performed locally

- All PHP files pass PHP 8.2.33 syntax checks.
- The JavaScript file passes Node.js syntax checks.
- Every file listed in `MANIFEST.sha256` matches its checksum after the changes are regenerated.
- Live RHEL/Cacti validation is recorded separately after deployment.

## Live RHEL/Cacti validation

- VM: `Cacti-RHEL9`, Cacti `1.2.31`, PHP `8.0.30`.
- Deployed to `/var/www/html/cacti/plugins/icct_rack` and enabled through Cacti Plugin Manager.
- Database tables were created and the schema marker is `1.0.2`.
- Rack view rendered the QA rack `NMS-ICCT-LAB-01` with `NMS Core Switch` at U40-U41.
- NMS topology view rendered 12 devices and 7 discovered links, including the same core switch.
- Backend move/restore passed, and the out-of-bounds U42-U43 request was rejected.
- Browser console and Apache checks showed no new ICCT Rack PHP errors.
