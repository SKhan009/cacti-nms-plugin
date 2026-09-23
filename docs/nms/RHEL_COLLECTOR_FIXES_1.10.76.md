# RHEL collector fixes in NMS 1.10.76

The supplied RHEL report demonstrated an older Cacti background-launch API expecting a string, plus local-address detection failing because the collector hostname resolved only to loopback.

## Changes

- The diagnostic listener is launched using a quoted argument string compatible with the older Cacti API. Cacti core is unchanged.
- Local self-tests match the selected IP against actual interface addresses from bounded `ip -j address show` output. No site-specific IP is embedded. Hostnames are not assumed local from DNS alone.
- iPerf3 and Netperf temporary servers bind only the selected collector address and stop after the bounded test. Using a collector interface address remains a self-test, not a remote-link measurement. A non-loopback binding can be reachable on that interface during the short test, subject to existing firewall policy.
- Remote device addresses retain the normal client/server flow. The plugin does not start servers on remote devices.
- Path capacity tests now detect either pathchar or pchar. The pchar invocation uses its numeric-output option and the profile hop limit. Both have a 60-second execution limit; partial output remains visible.
- Missing Pathchar/pchar is reported explicitly. Neither binary is bundled or installed by this update. No raw-socket permission, SELinux policy or firewall rule is changed.

## Path capacity dependency

Obtain an approved build for the target RHEL version and CPU architecture and install it in a standard bin or sbin directory. The collector account needs permission to execute it and use raw sockets. Installation alone is not proof that probing is permitted. The listener refreshes its executable inventory every 60 seconds.

pchar is an independently written implementation of the pathchar measurement algorithms. Upstream is no longer actively developed; it is not a claim of modern RHEL compatibility. Validate an approved build on the target host before enabling it in profiles. Traceroute and tracepath are not capacity-test substitutes.

Upstream documentation and source: https://www.kitchenlab.org/www/bmah/Software/pchar/

## Validation

Diagnostic regression checks passed on PHP 8.2 and 8.5, including assigned IPv4/IPv6 address matching and rejection of unrelated addresses. Changed PHP files passed syntax checks on the RHEL VM. Netperf passed using the VM's dynamically discovered interface address. The first iPerf3 attempt reached its time limit; a repeated attempt completed successfully on that address. No permanent server was required. Pathchar/pchar is absent on the VM, so actual path-capacity execution is not verified.

The separate x86_64 host described in the report was not accessed or modified. Deploy this version there through the normal plugin upgrade process and allow the next collector poll to start the listener.
