# Main reconciliation QA — 2026-11-03

Reconciled `3ae4fdb` (latest main) with `7a33545` (QA/SSH fixes), retaining
main's `icct_nms/` release layout and bundled offline installer components.
PHP token/literal and template structure comparisons separated formatting from
behavioral changes. The duplicate Redis fallback and duplicate test wrapper remain
retired; managed strict-auth Guacamole replaces the old manual service unit.

Validation ran on RHEL 9.8 aarch64, Cacti 1.2.31 and PHP 8.0.30. Integration
fixtures used a disposable database and private mount/network namespaces with
loopback-only SNMP/SSH agents. No fixture records were added to the live inventory.

| Check | Result |
| --- | --- |
| PHP syntax | 721 passed |
| PHP standalone suites | 57 passed |
| Python protocol/transport suites | 4 passed |
| Installer lifecycle, packaging, native dependencies and SSH setup suites | 7 passed |
| Native discovery/core, alarms, MIB generation, racks, sites and shapes suites | 11 passed |
| JavaScript regression suites | 20 passed |
| JavaScript production syntax | 29 passed |
| Broker console, strict native Guacamole, NetFlow wire suites | 3 passed |
| PHP-CS-Fixer, Prettier, Black and Git whitespace checks | Passed |
| Python AST equivalence after formatting | 21 files unchanged semantically |

The SSH transport tests include explicit password/private-key authentication,
wrong-password rejection, changed-host-key rejection, missing credentials and
server-accepted SSH none authentication rejection. Discovery checks include
SNMPv1/v2c/v3, IPv4/IPv6, negative credentials and retained CDP/LLDP warnings.
NetFlow wire tests cover v5, v7, v9 and IPFIX.

Three JavaScript tests now locate source relative to their own directory, so they
run from the canonical repository layout without a checkout-specific prefix.
The shape test's required device-type assignment existed only in the disposable
QA database. The QA database, account, files and fixture processes were removed.

Live verification retained exactly two localhost devices, 19 graphs and 20 data
sources, with no scan jobs, Syslog events or flow records introduced by QA. The
plugin is enabled; localhost SSH is enabled, monitoring is off and no SSH session
remains active.

Browser limitation: the connected Chrome profile still displays a certificate
warning for the existing HTTPS terminal URL. The visual terminal session could
not be completed without the user's certificate review. Backend terminal/broker
and strict transport tests passed. This is not recorded as a browser pass.
