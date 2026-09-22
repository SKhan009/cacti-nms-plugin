# 0.12.1 — Installation and validation maintenance

Corrected the local SNMPv3 validation runner to read the private protocol fixture directory after public examples were removed. The RHEL simulator installer now creates /usr/local/libexec when needed on a clean system. Added explicit production responder version pins and an installation/compatibility manual.

Verified the current RHEL 9.8 aarch64 environment, PHP SNMP/process capabilities and running services. Reran parser, neighbor reconciliation, security and transport checks; retained mixed-OID provisioning verification passed with five native data sources and five graphs. Reran live local SHA/AES and SHA256/AES validation, incorrect credential/context rejection and dual-protocol v3 fixture reconciliation; all passed, temporary agents removed, native device configuration unchanged. Private plugin URLs returned 403. All Python sources parse.

The project does not certify physical switch firmware or other OS/architecture combinations based on these VM tests. No application schema change or topology UI behavior change in this maintenance release. Backup: /var/backups/topology-0121/plugin-before.tar.gz.
