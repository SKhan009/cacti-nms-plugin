# Detected interface types — NMS 1.10.85

Discovery now retains IF-MIB ifType (1.3.6.1.2.1.2.2.1.3). The Connections list separates Detected interface type from Assigned connection type / label; topology link details also show the detected interface type.

Supported reported types: Ethernet (including legacy Fast/Gigabit Ethernet values), Wi-Fi, PPP, VLAN, Layer 3 VLAN, tunnel, MPLS tunnel, LAG, loopback, Frame Relay, ATM, SONET, Fibre Channel, HDLC and proprietary point-to-point wireless. Fibre Channel is not a generic optical-fiber Ethernet link. These describe endpoint interfaces, not guaranteed end-to-end services.

Each endpoint is matched by an exact, nonzero interface index against the topology's valid successful interface snapshot. Matching types on both ends show one detected type. One-sided or mixed evidence explicitly identifies the endpoint. Missing or unsupported data stays Unknown. Port names, speeds and LLDP/ARP alone never imply Ethernet.

Existing snapshots without ifType require a successful discovery refresh. Detection does not change freshness, adjacency evidence or status. Assigned VSAT/LOS/carrier labels remain separate. ARP is address mapping; bridge forwarding-table correlation is inferred attachment rather than proof of a direct cable.

Validation: typed SNMP parsing, missing-data handling, exact-index matching, mixed and one-sided evidence, stale-state preservation, manual transport preservation; existing neighbor tests and classification integration checks passed. VM deployed without OS changes.

Reference: https://www.iana.org/assignments/smi-numbers
Repository: https://github.com/SKhan009/cacti-nms-plugin
