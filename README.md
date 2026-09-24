# Cacti NMS plugins

Installable Cacti plugins are maintained under `plugins/`. The current NMS version is **1.10.99**.

- `plugins/nms/`: device management, topology, discovery, diagnostics and SSH monitoring.
- `plugins/topology/`: topology runtime.
- `plugins/icct_rack/`: rack topology runtime.
- `docs/`: installation and development documentation, outside plugin runtime folders.
- `tests/`: development checks.

See [project layout](docs/README.md), [NMS documentation](docs/nms/README.md), and the [Netperf offline RHEL 9 guide](docs/nms/Netperf_Offline_RHEL_9_Guide.docx).

Recent updates provide collector-side diagnostic execution, stored test history with filters and pagination, topology click details, consistent save notifications, safe refresh behavior, and bounded iPerf3/Netperf loopback self-tests. Loopback tests measure the collector itself; remote bandwidth tests require the corresponding server on the endpoint.

Only copy the required plugin folder into Cacti's `plugins` directory. Enable or upgrade it through Cacti Plugin Management. OS RPMs, VM images, local credentials and generated verification screenshots are not part of this repository.

## Offline RHEL requirements

The plugin runs without internet access after Cacti and its required packages are installed. Download the repository on a connected machine and transfer `plugins/nms` into the existing Cacti installation, then enable or upgrade NMS in Plugin Management. Keep existing local configuration and data when upgrading.

Diagnostics require the existing Cacti poller to run, a ready collector runner, and executable tools with permission to run under the collector account. Install iputils (Ping), iproute (neighbour cache), traceroute, iperf3 and optionally Netperf from matching offline packages. Pathchar is optional; a locally built pchar alternative has been installed and exercised on the test VM. See docs/nms/PCHAR_OFFLINE_RHEL.md. Remote bandwidth tests require the corresponding server and permitted network connections at the target.

Validation used RHEL 9.8 aarch64 with SELinux enforcing. Ping, Traceroute, ARP and loopback iPerf3/Netperf passed under the apache collector account. This does not certify every RHEL policy or remote endpoint. For x86_64 RHEL, obtain x86_64 RPMs; the prepared aarch64 bundle is not compatible. OS packages are not included in the Git download.

NMS 1.10.98 adds explicit ICMP/TCP Traceroute, MTR, Nping and hping3 profile options. TCP probes use port 443; hping3 is IPv4 only. Upgrade NMS through Plugin Management, then enable the desired checks in the assigned diagnostic profile. See [protocol checks](docs/nms/diagnostic-runner.md#tcp-and-icmp-checks-11098) for requirements and validation limits.

NMS 1.10.99 adds manually managed nodes with live member data, consistent diagnostic results, topology drag fixes and an offline geographic map using bundled Leaflet, India state boundaries and local GeoServer. See [node setup](docs/nms/NODE_SETUP.md), [map setup](docs/nms/TOPOLOGY_MAP.md), and the [GeoServer and Pathchar RHEL 9 guide](docs/nms/GeoServer_and_Pathchar_RHEL_9_Installation_Guide.docx).

The node editor now shows accessible devices before site selection and provides a compact searchable list with multi-selection and explicit bulk controls. Selecting a site enables same-site assignments; membership remains manual.
