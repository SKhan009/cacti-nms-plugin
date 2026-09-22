# Cacti NMS plugins

Installable Cacti plugins are maintained under `plugins/`. The current NMS version is **1.10.70**.

- `plugins/nms/`: device management, topology, discovery, diagnostics and SSH monitoring.
- `plugins/topology/`: topology runtime.
- `plugins/icct_rack/`: rack topology runtime.
- `docs/`: installation and development documentation, outside plugin runtime folders.
- `tests/`: development checks.

See [project layout](docs/README.md), [NMS documentation](docs/nms/README.md), and the [Netperf offline RHEL 9 guide](docs/nms/Netperf_Offline_RHEL_9_Guide.docx).

Recent updates provide collector-side diagnostic execution, stored test history with filters and pagination, topology click details, consistent save notifications, safe refresh behavior, and bounded iPerf3/Netperf loopback self-tests. Loopback tests measure the collector itself; remote bandwidth tests require the corresponding server on the endpoint.

Only copy the required plugin folder into Cacti's `plugins` directory. Enable or upgrade it through Cacti Plugin Management. OS RPMs, VM images, local credentials and generated verification screenshots are not part of this repository.
