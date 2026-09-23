# BLR connection styles — NMS 1.10.81

In Topology → Device appearance → Connection types, each title now has an SVG preview. Changing the colour, line style or endpoint symbol updates the preview immediately. Save style applies it to that manual connection type on the map.

Added presets based on the legend in BLR.pdf, page 1:

| Type | Default line |
| --- | --- |
| VSAT, Leased-line link | Dash-dot |
| Optical fiber link | Fine dotted |
| Line-of-sight (LOS) link | Short dashed |

All three start without endpoint symbols, matching the legend. They are also selectable in Add/Edit connection. Existing Fiber, Ethernet, Wireless and Logical records and saved styles are preserved. The new presets do not reclassify existing links automatically.

Line-style and endpoint dropdowns include visual character samples. The SVG preview and topology map use the same stored dash patterns. These styles identify connection types, not live health or measured bandwidth.

Deployment: upgrade through Cacti Plugin Management. Only plugin-owned schema metadata and default type rows are updated. Verified 29 validation checks, PHP and JavaScript syntax, and RHEL schema seeding/server rendering.
