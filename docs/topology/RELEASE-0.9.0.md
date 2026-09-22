# Version 0.9.0 — single topology workspace

Removed the Topology View, Configured Connections, Physical Ports and LLDP/CDP Evidence tabs from the canvas page. Existing canvas bookmarks, including the old connections-tab URL, now open the same workspace.

Added native − / + zoom controls (20–200%), zoom percentage and Fit to Screen. The view initially fits the assigned devices. The drawing area adapts to window height. Zooming preserves the view center and does not change stored device coordinates. Device dragging and cable endpoints use canvas coordinates at every zoom level.

Select a connected port or its cable to enable Disconnect in the toolbar. Device port configuration remains available on each device; evidence remains available through the sidebar.

Validation: PHP syntax and JavaScript syntax passed. Browser checks verified no canvas tabs, both existing devices and cables, 80% / 125% zoom, correct scaled dragging (80 screen pixels at 80% = 100 canvas units), restoring the saved layout after the unsaved drag test, cable selection and Fit to Screen. Existing device positions and two configured cable records were left unchanged. No core changes or schema migration were required.
