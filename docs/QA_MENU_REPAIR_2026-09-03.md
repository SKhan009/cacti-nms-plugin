# NMS 1.9.32 menu repair — local verification

Scope: repair the missing Cacti NMS sidebar and incomplete lifecycle hook repair,
based on the supplied RHEL command log. No remote deployment was performed.
Existing unrelated local changes were preserved and included in the full package.

## Passed

- Syntax checks on every plugin PHP file with PHP 8.0.30 CLI (WebAssembly runtime).
- `tests/plugin_navigation_test.php`: seven hooks, valid lifecycle registration
  caller, repeatable repair, disabled-state preservation, four sidebar links,
  preservation of core menu entries, realm checks on the top tab, custom Cacti URL
  prefix, active tab and correctly structured breadcrumb arrays.
- Existing standalone tests: core_form_options, device_metadata,
  graph_item_options, inventory_counts and snmpsim_config.
- Git whitespace validation.

## Not verified locally

- RHEL Apache/PHP-FPM requests, file-context enforcement and browser rendering.
- Database migration execution and the affected user's realm assignments.
- Cacti version on the affected server (not provided in the log); required minimum
  remains 1.2.31. PHP 8.0.30 compatibility alone does not establish Cacti compatibility.
- The database-writing graph-item integration test was not run.

Upstream Cacti 1.2.31 plugin registration, menu rendering and CLI code were checked
to confirm the hook/API contracts. The standalone navigation test uses test doubles
for those APIs; it is not a full live Cacti integration test.
