# Cacti Core Changes

**None.**

This plugin does not modify any default Cacti PHP, JavaScript, CSS, database schema, or core menu file.
Navigation is added through official Cacti plugin hooks:

- `config_arrays` for Console sidebar entries
- `top_header_tabs` for the Console header
- `top_graph_header_tabs` for the Graphs header
- `draw_navigation_text` for breadcrumbs
- `api_plugin_register_realm()` for permissions

All plugin runtime code remains under `plugins/icct_rack/`.
