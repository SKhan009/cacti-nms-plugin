# NMS 1.11.8 repository preparation

Prepared 2026-09-27. Includes the accumulated readings workspace, diagnostics, discovery, topology, serial configuration/graphs and offline MIB changes.

## Structure and cleanup

- Installable code stays under `plugins/`; feature services, templates, CSS and JavaScript remain in their dedicated directories.
- Cacti route and worker entry points retain their existing paths.
- Guides and verification notes stay under `docs/`; tests and fixtures under `tests/`.
- Generated outputs, Python caches and macOS metadata remain ignored.
- Updated the root README version to match `plugins/nms/INFO` (1.11.8), added feature guide links, corrected stale layout wording and cleaned a whitespace error.
- Updated the map regression fixture to supply saved appearance metadata required by the current map service. Runtime ACL checks remain exercised.

## Checks

- 793 PHP files passed PHP 8.0 syntax checks in an isolated source staging directory on the RHEL QA server.
- Six changed JavaScript files passed `node --check`; five changed Python files passed AST parsing.
- 34 selected independent PHP regression suites passed: configuration admission; diagnostic admission, heartbeat, history, launcher, loopback, protocols, summary and worker revalidation; disabled-device access; discovery address transport, identity and management addresses; equipment profiles; nodes; serial scheduling, inventory and profiles; topology diagnostics and map; workspace admission, candidates, consolidation preflight/RRD, evidence, management IP, networks, onboarding, paused identity, process cleanup, reviews, scan targets, service checks and SNMP settings.
- The initial map test failed because its database fixture lacked the new appearance-metadata query. Added that narrowly scoped fixture and reran successfully.
- Recognizable private-key, GitHub-token and AWS-key patterns were not found in changed text files. This is a limited automated scan, not a full security audit.
- See `SERIAL_QA.md` and `MIB_OFFLINE_QA.md` for earlier live/native integration checks and their limitations.

These checks do not certify every device model, hardware interface, collector configuration or end-to-end workflow. Database/native integration tests were not indiscriminately run against the live installation as part of this preparation.
