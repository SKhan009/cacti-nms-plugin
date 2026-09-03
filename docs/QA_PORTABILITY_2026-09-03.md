# NMS 1.9.34 portability verification

All plugin PHP files passed PHP 8.0.30 syntax checks in the local WebAssembly CLI.
Eight standalone suites passed: portable_config, offline_assets,
plugin_navigation, core_form_options, device_metadata, graph_item_options,
inventory_counts and snmpsim_config. Git whitespace checks passed.

The new portable tests cover POSIX and Windows drive paths (including spaces),
rejection of root/relative/traversal/URI/UNC paths, explicit invalid-configuration
failure rather than a legacy fallback, manual import file creation, duplicate
record protection, absence of an automatic reload marker, and rendering the
manual-activation UI without missing-key warnings. Manual configuration does not
load the Linux adapter. Temporary records created by the test were cleaned up.

Existing Linux JSON validation tests still pass. The systemd process query now
uses a literal argument array rather than a shell string. Its live execution on
RHEL and new remote server database migrations were not exercised in this turn.

This is not a certification of every OS or every Cacti release. Windows/macOS
web-server integration, ACLs and live SNMP polling remain untested. The package
requires an already working compatible Cacti 1.2.31+ / PHP 8.0+ installation.
No dependencies were installed on the target RHEL machine, and no server deployment
was performed. Offline deployment archives exclude Git, tests and macOS metadata.
