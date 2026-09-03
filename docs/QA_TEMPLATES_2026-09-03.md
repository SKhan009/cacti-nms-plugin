# Templates workspace QA — 2026-09-03

Deployment: NMS 1.9.35, existing RHEL VM / Cacti 1.2.31. No core files changed.
Backup: `/var/backups/nms-templates-M7s8tAQR` (plugin and database before deployment).

## Verified in the browser

- All five sidebar sections open the corresponding native Cacti list and creation form.
- No Templates tab in the top header. Templates submenu expands/collapses.
- Device management no longer offers the graph builder; legacy graph links redirect to Templates.
- New-form control names, types, required flags and every select option exactly match
  the undecorated Cacti forms: input 6, query 7, source 24, graph 71, device 5 controls
  (excluding CSRF and presentation context).
- All 18 native graph-item types were selected in both UIs. Visible conditional
  field IDs match for every type, including GPRINT variants, TEXTALIGN, TICK and rules.
- Native nested input-field/output-field and graph-item editing remains available.
- Created an unassigned temporary device template through the UI. Cacti displayed
  “Save Successful.” in its Operation successful dialog. It appeared in the native
  list as ID 34 with zero devices. Deleted only that QA template through Cacti's
  explicit confirmation screen; confirmed it no longer appears.
- Corrected inherited native floats/fixed heights that overlapped form labels and
  section headings. Sidebar labels render white; selected Templates uses dark text
  on its white active background. Desktop editor has no horizontal page overflow.
- Final five-section smoke check: matching active submenu, all five submenu entries,
  no Templates top-header tab, and no horizontal overflow. Fresh QA tab error log empty.
- At a 390px viewport, the input editor uses one column and has no page overflow,
  with the sidebar both expanded and collapsed. Temporary viewport override reset.
- PHP syntax, workspace-route/context tests, plugin-navigation tests, offline-asset
  tests, JavaScript syntax, neutral-theme checks and `git diff --check` passed.
- No new PHP-FPM errors compared with the pre-test log baseline; httpd, php-fpm and
  mariadb remained active. Unused initial CSS prototype moved into the backup folder.

## Scope

Presentation uses core controllers rather than reproducing their fields. Native
save/error/confirmation dialogs are retained; success is not inferred from clicks
or HTTP status. Production devices, templates and simulator records were not edited
during these browser checks. The temporary QA template contained no associations.
