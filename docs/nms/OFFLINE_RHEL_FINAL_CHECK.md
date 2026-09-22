# Offline RHEL final verification

Verified on 22 September 2026 for NMS 1.10.75.

The plugin supports offline operation after Cacti, the collector and the required operating-system packages are installed. The GitHub download contains plugin code; it does not include the diagnostic RPMs or guarantee that a different server has the required permissions.

Repository: https://github.com/SKhan009/cacti-nms-plugin

## Checks completed

| Check | Result |
| --- | --- |
| Fresh GitHub checkout | Current NMS plugin files present |
| Test operating system | RHEL 9.8 aarch64 |
| SELinux | Enforcing during verification |
| Execution account | apache collector account through the plugin CLI diagnostic function |
| Ping | Passed against loopback |
| Traceroute | Passed against loopback |
| Collector ARP lookup | Passed; returned collector neighbour-cache output |
| iPerf3 | Bounded loopback self-test passed |
| Netperf | Bounded loopback self-test passed |
| Temporary bandwidth servers | No remaining iperf3 or netserver processes after tests |
| Diagnostic regression checks | Passed on PHP 8.2 and PHP 8.5 |
| Pathchar | Not installed or verified |

Loopback bandwidth measures the collector itself. These checks do not certify remote network throughput or every feature on every RHEL installation. The final checks did not disconnect the VM network; they used local targets and collector data without requiring internet access.

## Errors addressed from the supplied document

- Diagnostics execute through the existing Cacti collector, instead of running network tools inside PHP-FPM.
- Standard output and error output are captured, execution is bounded, and partial output is retained on timeout.
- ARP uses the full collector neighbour cache rather than filtering it to the selected device address.
- Executable lookup checks standard bin and sbin locations, including /usr/local.
- Loopback iPerf3 and Netperf tests use temporary local servers with cleanup.
- Failed bandwidth connections show an explanation. A missing remote server remains a real failure; the plugin cannot create one on the remote device.
- Saved results have Description and Technical output tabs. Available measurements are extracted from the output, and missing readings are labelled Not reported.
- Reload clears diagnostic URL parameters without deleting saved test history.

The older document includes proposed TCP preflight checks and SELinux policy commands. Those are not installation instructions for this release: current diagnostics use bounded client execution and collector-side handling. Do not apply the older policy snippets merely to install this plugin.

## Requirements on the offline host

1. An existing compatible Cacti installation and a functioning scheduled Cacti poller.
2. NMS enabled or upgraded through Cacti Plugin Management so its tables and hooks are installed.
3. A ready diagnostic collector runner. After installation or reboot, allow the next normal poller cycle to start it.
4. Matching packages for the target RHEL version and CPU architecture:
   - iputils for Ping.
   - iproute for collector neighbour lookup.
   - traceroute, or the supported tracepath alternative.
   - iperf3 for iPerf3 tests.
   - Netperf for Netperf tests, with its dependencies.
   - Pathchar only if a compatible executable is available; it is optional.
5. The collector account must be able to execute these tools and perform their network operations under the existing host policy.
6. Devices must have a diagnostic profile that permits the requested tool.

The plugin does not override SELinux, firewall rules or missing execution permissions. Moving execution out of PHP-FPM addresses the web-runtime restriction shown in the supplied report, but a separately restricted collector can still require administrator attention.

## Architecture matters

The supplied error report identifies an x86_64 RHEL host. The verified VM is aarch64 (ARM64). The PHP plugin source can be transferred to either architecture, but RPMs must match the destination. Do not install the prepared ARM64 Netperf bundle on an x86_64 host.

The original x86_64 server has not been retested in this final verification. Its runtime permissions and remote endpoint connectivity remain to be verified on that server.

## Remote bandwidth tests

- iPerf3 requires an authorised iPerf3 server on the destination, normally TCP 5201.
- Netperf requires netserver on the destination, normally TCP 12865, plus its negotiated data connection.
- Ping or SNMP success does not mean either bandwidth server is running.
- Internet access is unnecessary when the collector and test endpoint are reachable on the local network.

## Install from GitHub

1. Download the repository on an internet-connected machine and transfer it to the offline host.
2. Back up the existing plugin and database before an upgrade.
3. Copy the contents of repository folder plugins/nms into the existing Cacti plugins/nms folder. Preserve local configuration and runtime data. Do not install the repository root as the plugin.
4. Enable or upgrade NMS in Cacti Plugin Management.
5. Allow the next collector poll, then open Protocol checks and check tool availability.
6. Run Ping and ARP, then bandwidth tests against an endpoint with the required server. Review both result tabs and save the results as deployment evidence.

See [Netperf offline RHEL 9 guide](Netperf_Offline_RHEL_9_Guide.docx) for offline package preparation, root-shell commands without sudo, signatures, architecture selection and official download links.
