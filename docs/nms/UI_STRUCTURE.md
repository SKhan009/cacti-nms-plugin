# Readings and diagnostics UI structure

## Device readings

- `plugins/nms/includes/workspace/page.php`: readings controller and section-specific data loading.
- `plugins/nms/templates/workspace/index.php`: page shell and device selector.
- `plugins/nms/templates/workspace/overview.php`: shared summary/navigation entry.
- `plugins/nms/templates/workspace/reading_tabs.php`: single lower navigation bar.
- `plugins/nms/templates/devices/readings.php`: reading results and filtered collector evidence.
- Other workspace templates: network discovery, identity, duplicate review, neighbours/onboarding and their workflow histories. `history.php` renders only the change audit.

## Diagnostics

- `plugins/nms/diagnostics.php`: entry, existing test execution/history and profiles.
- `plugins/nms/includes/diagnosis_page.php`: service-check controller and legacy diagnostic submissions/result redirects.
- `plugins/nms/templates/diagnostics/header.php`: shared heading and tab layout.
- `plugins/nms/templates/diagnostics/diagnosis.php`: device selector and service-view entry.
- `plugins/nms/templates/diagnostics/services.php`: service form and history.
- Existing `run.php`, `history.php`, profile and result partials stay under `templates/diagnostics/`.

## Shared backend code kept intentionally

Workspace service/diagnosis helpers are still used by collector workflows or legacy submissions. `includes/workspace/evidence.php` retains route helpers and ping measurement validation used by topology. Historical diagnostic labels and command support retain saved-result compatibility; new submissions reject the retired duplicate probes.

## Cleanup scope

Removed the unused workspace result template and diagnosis wrapper; moved the service template into diagnostics. Removed three unused combined-finding functions and their obsolete tests. Removed redundant imports and history-tab queries for panels no longer rendered there. No schema, saved records, worker entrypoints or permission checks were removed.

## MIB repository

The template-first MIB workflow is documented in [MIB_TEMPLATE_WORKFLOW.md](MIB_TEMPLATE_WORKFLOW.md). Parsing remains in `includes/mib_import.php`; preparation is separated into `includes/mib_templates.php`; UI remains in `templates/repository/mib.php`.
