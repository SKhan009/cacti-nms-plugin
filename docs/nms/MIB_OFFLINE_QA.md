# MIB upload on offline RHEL

Updated 2026-09-27. Target: the existing RHEL QA Cacti installation.

MIB validation and device-template preparation run locally with Net-SNMP `snmptranslate`; they do not download definitions or require internet access. Live SNMP verification still requires connectivity to the selected device. Uploading definitions without selecting a device does not initiate SNMP reads.

Changes:
- Accept `.my` alongside `.mib` and `.txt`, retaining module-header validation and existing size/count limits.
- Report PHP upload errors separately: size limit, partial upload, missing file, missing temporary directory, write failure and extension rejection.
- Check temporary-file availability and parsing-file writes before invoking the parser.
- Identify missing imported module names and explain how to supply them offline.
- Explain local parser prerequisites when the executable cannot be started.
- Show offline usage and limits in the upload dialog.

Offline prerequisites:
- Install `net-snmp-utils` and its dependencies from matching RHEL installation media or an approved offline RPM repository. The QA server already has `/usr/bin/snmptranslate` and standard MIBs.
- Upload vendor dependencies together with the main module, or install them in the server's `/usr/share/snmp/mibs` directory. Module names in `IMPORTS` must match their definitions.
- Limits remain 1–8 files, 1 MB each, 4 MB total. PHP and the web server may impose additional limits. Do not disable SELinux to address upload-folder errors; correct the folder's permissions and labels.

Validation:
- PHP syntax checks passed for the two deployed files.
- `tests/nms/mib-offline.php` passed under `unshare --net`, including a missing-dependency rejection followed by a successful `.my` file plus dependency upload to OID `1.3.6.1.4.1.55555.1.0`.
- Repeated the test as the Apache user inside the network namespace.
- `tests/nms/mib-template-preparation.php` passed as Apache with networking disabled. Native template APIs used connection-local temporary tables. No persistent device, local data source or graph was created.
- The namespace isolated only the test processes; normal server networking was unchanged.

Deployed files: `plugins/nms/includes/mib_import.php` and `plugins/nms/templates/repository/mib.php`. Original copies are in `/var/lib/cacti/nms-mib-offline-backup` on the QA server.

The user's exact failing file/error was not supplied, so these tests confirm the corrected paths with fixtures, not reproduction of that particular failure. A standard MIB also passed before the changes; there was no demonstrated general internet dependency in the parser. Multipart browser submission and PHP-FPM SELinux confinement were not reproduced by these CLI tests.
