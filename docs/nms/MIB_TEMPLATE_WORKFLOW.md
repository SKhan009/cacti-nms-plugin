# MIB templates before device creation

File repository → MIB files → Upload MIB opens the upload dialog. Choose a device type (existing NMS segment), enter a unique device-template name, and upload vendor MIBs with dependencies. Validation uses Net-SNMP without a device or SNMP session. Review the supported scalar metrics, then Create templates.

Preparation creates native Cacti device, data and graph templates with device-type associations, using the existing Generic OID template APIs. Prepared template reports are stored under `mib_bundle_<host-template-id>` in the existing NMS metadata table. No schema migration is required. Add device is available only while the prepared templates exist; it preselects the device template and type. Native graph/data-source instances are created when the device is saved enabled, with the existing reconciliation worker retrying missing instances.

Limits: up to eight files, 1 MiB each / 4 MiB total, 128 definitions and 64 numeric scalars. Indexed columns, enums and nonnumeric objects are listed as skipped. A MIB cannot supply live readings or device-specific table indexes; preparation is not proof that a device exposes those OIDs. Original uploaded contents are not archived. Existing device-linked imports and their objects remain available.

Files:
- `includes/mib_import.php`: bounded parsing and existing live-device import compatibility.
- `includes/mib_templates.php`: preparation, native template links and report storage.
- `templates/repository/mib.php`: dialog, previews, prepared-template list and legacy import history.
- `includes/device_manager.php`: activates prepared graphs/data sources after device creation.

Verification: PHP lint; browser popup open/close and responsive layout; CLI `tests/nms/mib-template-preparation.php` against QA Cacti with temporary-table isolation. Native parsing, template creation/linking, duplicate-name rejection, report storage and absence of premature host/local data/graph creation passed. No real-device polling was asserted by this test. QA backup: `/var/backups/nms-before-mib-first.tar`.
