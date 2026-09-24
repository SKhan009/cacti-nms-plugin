# Device address audit — 24 September 2026

Reviewed the owned NMS, topology and rack plugin source for literal IPs, URLs,
VM hostnames, machine-specific paths, device IDs and collector selection.
Third-party dependencies and generated output documents were not modified.

## Findings

- Diagnostic targets come from the selected Cacti device hostname. No runtime
  fallback to `127.0.0.1` was found in the audited device command paths.
- Topology SNMP sessions use the selected device address, SNMP port, credentials
  and collector assignment. Network discovery uses the submitted discovery target.
  Rack views read the associated Cacti host instead of a fixed device address.
- Local SSH transport and guacd deliberately bind to loopback. The topology
  simulator deliberately supports a configured loopback-only endpoint. These
  values are service boundaries, not substitutions for device addresses.
- Documentation addresses, placeholders, fixtures, standard MIB OIDs and example
  configuration are literals by design. Deployment paths in service installers
  are installation conventions, not device targets.
- Fixed diagnostic service ports remain: TCP probes use 443, iPerf3 uses 5201,
  and Netperf uses 12865. They are not configurable per profile in this version.
  This audit does not claim the project contains no constants.

## Correction

The bandwidth self-test classifier only recognized the compressed IPv6 loopback
spelling. It now compares packed addresses and recognizes equivalent IPv6
loopback spellings and IPv4-mapped loopback, consistent with result summaries.
The selected target is preserved; no target is rewritten. Remote addresses and
hostnames are not guessed to belong to the collector.

Added regression coverage that varies the target across documentation IPv4,
IPv6 and DNS addresses for every supported targeted diagnostic. ARP is explicitly
collector-cache-only; hping3 rejects IPv6 by design. These tests send no packets.

## Verification

- 160 owned local PHP files passed syntax validation.
- All five NMS fixture suites passed locally and against deployed RHEL PHP 8.0.
- Five independent topology PHP suites passed: records, SNMP security,
  discovery transport, neighbors and interface types.
- Four topology JavaScript suites passed: arrangement, history, dragging and save prompts.
- Updated the VM bandwidth helper, preserving the previous file under
  `/root/nms-diagnostic_iperf.before-address-audit.php`.
- 179 deployed PHP files passed syntax validation across the three plugins.

No new remote-device network tests were run during this audit. Tests demonstrate
argument selection and classification, not reachability of every deployed device.
Existing live loopback protocol results are recorded separately in
`PROTOCOL_VERIFICATION_1.10.98.md`.
