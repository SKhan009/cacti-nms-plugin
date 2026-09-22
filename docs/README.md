# Project layout

- `plugins/nms/` — current NMS runtime and installation support.
- `plugins/topology/` — topology plugin runtime.
- `plugins/icct_rack/` — rack plugin runtime.
- `docs/nms/`, `docs/topology/`, `docs/icct_rack/` — documentation grouped by plugin.
- `docs/nms/vendor/` — reference documentation for bundled SSH libraries. Licence files remain with their libraries.
- `tests/nms/`, `tests/topology/` — development checks and fixtures, outside installable plugins.

Copy only the required folder under `plugins/` into a Cacti installation. Documentation and tests are maintained separately.

Cacti-facing PHP pages, `setup.php`, `index.php`, and `INFO` remain at each plugin root because Cacti hooks, page permissions and existing URLs depend on those paths. Shared code, page templates, CSS, JavaScript and images remain in their existing dedicated directories. Runtime contents were verified unchanged during this cleanup.

No Markdown or Word documents remain inside `plugins/`. Removed clutter is recoverable from macOS Trash. Hidden Git history is retained.

Run the NMS admission check from the project root with:

```sh
php tests/nms/diagnostic-admission.php
```
