# Dynamic connection data — NMS 1.10.86

The VM's missing interface types and speeds were caused by expired discovery snapshots. Running the configured NMS collector refreshed real SNMP data. The rendered connections list then contained seven Ethernet interface classifications and seven SNMP-reported 1 Gbps readings. The lab includes simulated devices; their configured SNMP responses are not physical throughput measurements.

Interface speed / capacity now uses dynamically scaled units. Equal endpoint speeds show the SNMP speed; unequal or one-sided readings identify the endpoint. Missing readings are explicitly unavailable. Manual capacity remains an operator-entered value, marked configured. Protocol-check throughput is kept separate, with a link to diagnostics and test history.

The table has readable column minimum widths, consistent cell alignment and horizontal overflow on smaller screens.

Audit: reviewed first-party discovery, topology, diagnostics and UI code for literal device addresses. Retained loopback detection and the SSH helper's local-only listener because these are safety/runtime semantics, not hardcoded monitored-device destinations. Removed a literal address from a tooltip example. Protocol OIDs, standard ifType identifiers, unit conversion factors, bounds and UI defaults remain necessary constants. No synthetic replacement measurement was added. This was not an exhaustive audit of third-party dependencies.

Validation: interface parsing, missing values, endpoint type matching, stale-state preservation, dynamically scaled units, unequal-speed provenance and manual-connection regression checks passed. VM-rendered output confirmed the refreshed readings. Existing periodic Cacti polling must continue for current data; expired data is not presented as live.

Repository: https://github.com/SKhan009/cacti-nms-plugin
