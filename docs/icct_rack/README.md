# ICCT Rack Topology for Cacti

`icct_rack` is a Cacti 1.2.x plugin that adds a physical rack topology view for the ICCT LNMS/CNMS project.

## Scope implemented in 1.0.0

- Front and rear rack visualization with numbered U positions.
- Create/edit/disable/delete rack definitions.
- Place existing Cacti devices (`host.id`) in a rack.
- Device height, start U, front/rear face, category, asset tag, power feed, notes.
- Backend overlap and rack-boundary validation.
- Live color/status display from Cacti's existing device status.
- Auto-refresh without reloading the full page.
- Drag-and-drop movement for users with Manage permission.
- Device details and cross-launch to the Cacti device page.
- Separate Cacti realm permissions for View and Manage.
- Audit trail for rack, placement and settings changes.
- No Cacti core source modifications.

See `INSTALLATION_GUIDE.md`, `docs/DB_DESIGN.md`, and `docs/CORE_CHANGES.md` before installation.

## Compatibility target

- Cacti 1.2.31+ in the 1.2.x line. Cacti 1.2.31 is required because it contains security fixes not present in 1.2.30.
- PHP 8.2 compatible code.
- MariaDB 11.4 compatible schema.
- Windows 11 + Apache/PHP and RHEL + Apache/PHP.

The plugin does not contain Windows-only or RHEL-only paths.
