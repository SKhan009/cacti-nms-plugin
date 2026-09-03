# NMS — generic offline installation

This working version is not yet verified for deployment. Read
[the 1.10.0 upgrade gates](UPGRADE_1.10.0.md) and [current evidence](GOAL_PROGRESS.md)
first. Do not infer compatibility or successful migration from the version number.

## What is portable

The plugin uses Cacti's configured base path, URL, database connection, session,
permissions, native APIs, polling and local assets. Normal monitoring needs no
Python, systemd, npm, Composer, CDN or internet service. Device connectivity is
still required. Imported samples never substitute for live readings.

Target validation is Cacti 1.2.31 with PHP 8.0 and MariaDB 10.5 on the confirmed
RHEL VM. Newer versions and other operating systems require their own integration
tests. Windows/POSIX path tests do not prove a working Windows/macOS Cacti server.

## Install or upgrade

1. Confirm the active Cacti root and database. Back up existing code and affected
   database records outside the web root. Preserve any VM-only changes.
2. Follow the migration preflight in the upgrade guide. Older category namespaces
   and unsupported legacy rule metrics require explicit resolution, not deletion.
3. Transfer only reviewed plugin files to `<CACTI_ROOT>/plugins/nms`, preserving
   runtime data and environment configuration. Avoid a nested `nms/nms` directory.
   Exclude Git metadata, development output, secrets and Python bytecode.
4. Grant the actual PHP and poller identities read/traverse access to source.
   Keep source administrator-writable only; runtime storage has separate narrowly
   scoped access. Use POSIX permissions or NTFS ACLs appropriate to the server.
   Do not assume apache/www-data, use chmod 777, or disable SELinux/AppArmor.
5. Use Cacti's supported Plugin Management install/upgrade lifecycle. Do not
   uninstall to repair or upgrade NMS: uninstall deletes plugin tables. If the
   installed Cacti release does not offer Upgrade, inspect its lifecycle/CLI
   behavior before choosing another action; enable alone is not proof of migration.
6. Grant the NMS view realm through native user permissions. Writes also require
   the relevant native management/template realm, and device-specific operations
   must respect native device visibility.
7. Verify the completed schema marker, native logs, authenticated pages and actual
   polling. Reload the relevant PHP worker pool only if required for opcode cache
   refresh, through the server's normal administrator workflow.
8. Compare code hashes with documented configuration/runtime exclusions. Record
   acceptance results and the exact backup/rollback paths.

Use the registered NMS entry point relative to your existing Cacti URL:
`<CACTI_URL>/plugins/nms/nms.php`. A directory-only 403 does not prove a PHP
entry-page failure. Do not enable directory listing. No Cacti core source or
core table schema change is part of this installation.

## Optional simulator: explicit configuration without a core edit

Skip this section for real devices. Install SNMPSim separately using approved
packages, offline if required. The plugin does not install Python or launch a
responder from a web request.

Create an administrator-controlled JSON file outside the public web/Cacti tree.
Select it explicitly using the `NMS_SNMPSIM_CONFIG` environment variable for the
relevant PHP-FPM/web process and CLI processes. PHP-FPM may clear inherited
environment variables: configure its actual pool/service environment explicitly,
then verify visibility from that runtime. Do not expose a public phpinfo page.

Example content only—replace the directory, address and port with reviewed values:

```json
{
  "activation": "manual",
  "data_dir": "/srv/nms-lab/records",
  "client_address": "192.0.2.20",
  "port": 10161,
  "poller_id": 7
}
```

The address and collector ID are examples, not defaults. Replace `poller_id` with
the existing, enabled Cacti collector that will poll the endpoint. The web probe
requires this to match the web process's native collector identity; other collectors
must be verified through their own poll cycle. On Windows,
use a valid local path such as `C:/NMS Lab/records`. Spaces are supported; roots,
relative paths, traversal, URI wrappers and UNC shares are rejected.

The selected record directory must exist, be outside publicly served content, be
writable by the import process, and be readable by the responder. Configure the
responder with the same directory and chosen endpoint. Loopback is valid only when
the querying process and responder share the relevant host/network namespace.

Manual mode supports IPv4 endpoints and administrator-managed activation. It does
not deploy files to remote machines. A remote responder requires a separately
managed mechanism to make the reviewed records available there.

NMS saves validated uploads but never starts/reloads a manually managed responder.
After activating the new community using the server's normal method, use the
explicit live SNMP check. Import success is not proof of activation or collection.

Existing administrator-supplied `$config['nms_snmpsim']` arrays remain recognized
for compatibility and take precedence over the environment-selected JSON. Do not
add an edit to Cacti core for a new installation. Remove ambiguity deliberately
if migrating from an existing array. An invalid explicit source produces an error,
not a search for an alternate configuration.

## Optional Linux/systemd adapter

See [SNMPSim setup](../snmpsim/README.md). The generator requires explicit responder,
Python, systemctl, helper, configuration and lock paths; account/group; endpoint;
service name and a new output directory. It generates a reviewable bundle only,
without installing files or changing service state.

There is no implicit `/etc/cacti-nms/snmpsim.json` lookup. Existing installations
using that path must explicitly select it and supply the new required fields.
Keep service configuration/helpers root-owned and non-writable by the web or
responder identities. Apache needs no sudo or service-management privilege.

Review old units/timers before switching modes. Do not leave two responders on the
same endpoint or discard pending upload markers. A stopped responder must remain
stopped until deliberately started by its administrator.

## Verification limits

Local standalone tests cover selected forms, permissions, freshness, storage
contracts, metadata, template routing, paths and configuration behavior. PHP
WebAssembly is not native PHP/FPM or database integration evidence. Native RHEL
migration, service, SELinux, poller, browser and deployment checks remain required.
