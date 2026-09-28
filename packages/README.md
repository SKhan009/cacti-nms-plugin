# Offline installation packages

Download or clone this repository, then transfer `packages/` to the offline machine. These files are intentionally outside `plugins/`. Do not copy this directory into Cacti's plugin runtime.

| Folder | Contents | Platform |
| --- | --- | --- |
| `pchar/` | Pchar 1.5 source, GCC compatibility patch, locally built executable and build guide in one ZIP | Included binary: RHEL 9 aarch64; rebuild source for other CPUs |
| `netperf/` | Netperf 2.7 RPM, lksctp-tools dependency, public RPM signing keys and checksums in one ZIP | RHEL 9 aarch64 |
| `geoserver/` | GeoServer 2.28.5 platform-independent binary distribution, stored as three numbered ZIP parts | Requires a compatible installed Java runtime |

## Verify and extract

On RHEL, run `sha256sum -c SHA256SUMS` inside each package folder before use. On macOS, use `shasum -a 256 -c SHA256SUMS`.

Extract the Pchar and Netperf ZIPs with `unzip`. Inner checksums supplied with the original offline bundles are retained. The public `RPM-GPG-KEY-*` files are signing keys, not private credentials. Verify RPM signatures against a trusted key before installation.

GeoServer exceeds GitHub's 100 MiB normal-Git file limit. Reconstruct its original ZIP offline with Python 3:

```sh
python3 packages/geoserver/reassemble.py
unzip packages/geoserver/geoserver-2.28.5-bin.zip -d geoserver-2.28.5
```

The script verifies each part, the complete SHA-256 hash, size and ZIP CRCs. It refuses to overwrite an existing archive. The reconstructed ZIP is ignored by Git; the numbered parts are the tracked files. They are byte segments, not individually extractable ZIP files. All three must be transferred.

## Installation guides and prerequisites

- [Pchar build and collector integration](../docs/nms/PCHAR_OFFLINE_RHEL.md)
- [Netperf offline RHEL guide](../docs/nms/Netperf_Offline_RHEL_9_Guide.docx)
- [GeoServer and Pathchar guide](../docs/nms/GeoServer_and_Pathchar_RHEL_9_Installation_Guide.docx)
- [Offline topology map configuration](../docs/nms/TOPOLOGY_MAP.md)
- [Deployment service examples](../docs/nms/deployment/README.md)

This is the available application-package bundle, not a complete offline OS repository. Java, Cacti/PHP/MariaDB/RRDTool, compilers, Net-SNMP, Python serial dependencies and other diagnostic utilities are not included here. Obtain missing dependencies from matching RHEL media or an approved offline repository. The ARM64 RPMs and executable must not be installed on x86_64 machines.

GeoServer is the distribution archive used for QA, not a copy of the running instance's data directory. It includes upstream sample data and upstream default security configuration: follow the installation guide to configure credentials before exposing a new instance. Local VM images, SSH keys, application credentials, logs and obsolete QA source snapshots are excluded.

## Sources and redistribution notices

Third-party packages retain their own licenses and notices; inclusion does not change their licenses.

- Pchar source: https://www.kitchenlab.org/www/bmah/Software/pchar/pchar-1.5.tar.gz (source and license are inside the included archive).
- Netperf upstream source: https://github.com/HewlettPackard/netperf ; included RPM version: `2.7.0-2.20210803git3bc455b.el9.aarch64`.
- lksctp-tools included RPM: `1.0.19-3.el9_4.aarch64`; use the corresponding RHEL source package and notices for this build.
- GeoServer 2.28.5 release: https://geoserver.org/announcements/vulnerability/2026/08/14/geoserver-2-28-5-released.html
- GeoServer original binary URL and SHA-256 are in `geoserver/manifest.json`; original license files are retained inside the reconstructed ZIP. Source: https://github.com/geoserver/geoserver/tree/2.28.5

Validation performed: ZIP CRCs, package SHA-256 manifests, and GeoServer reassembly. These checks verify transfer integrity; they do not replace platform-specific installation tests or independent upstream signature verification.
