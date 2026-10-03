# ICCT NMS — separate HTML and CSS

Open http://localhost:8765/ or `index.html` directly. This folder contains static exports of the deployed ICCT NMS pages, captured from the authenticated VM. Inventory values are a snapshot of the 13 saved devices, not generated example records. Updating records on the VM does not automatically update these HTML files.

## Files

- `index.html`: inventory list with search, filters, paging and tree view.
- `rack-view.html`: standalone rack-view design from the supplied screenshot, with sample status/alarm totals, device details, zoom, fit, and fullscreen controls. It is a visual prototype, not live monitoring.
- `css/rack-view.css` and `js/rack-view.js`: rack styles and interactions.
- `device.html`: Add Device form.
- `device-<id>.html`: saved Basic Information for each exported device.
- `protocol-<id>.html`: saved protocol, diagnostic and Graphs steps for each device.
- `css/inventory.css`: separate responsive stylesheet.
- `images/`: supplied DRDO and Bharat Electronics SVG header logos, plus local device-type topology SVGs in `images/device-types/`.
- `js/inventory.js`: table, navigation and form display behavior.
- `js/wizard.js`: local wizard step navigation and unsaved-change handling.
- `js/preview.js`: static-preview form handling and HTML navigation.
- `inventory.csv`: saved inventory export from the VM.

HTML pages contain no PHP or authentication tokens. Password input values are blank. Forms are preview-only and never write device settings. Next and Previous retain edits in page memory, and Save accepts the preview draft with a toast that live configuration is unchanged. Validation errors appear inline and retain protocol selections. Cancel and Done ask to save or discard unsaved edits. Graph and account links open the VM. The production PHP plugin remains in `plugins/icct_nms`.

Serve this folder with:

```sh
python3 -m http.server 8765 --bind 127.0.0.1 --directory html-css/icct_nms
```

`device-types.html` previews the Presets Device Type table and inline editor. Native image uploads are saved under the plugin’s `assets/images/device-types/uploads/`; the static preview does not upload files or change saved profiles.

`network-connections.html` previews the Network Connections cards, style editor and live line preview. Save/delete actions in static previews do not change Cacti.

Device Type Add/Edit has a six-column local SVG icon picker and a 500 KB upload limit. Add/Edit Device previews load appearance details from the selected saved type profile.

`sites.html` previews the Site preset list and Add/Edit form using exported native Cacti site data. Preview saves do not write to Cacti.

`protocol-presets.html` previews the Protocols preset tab. Supported defaults (CDP, LLDP, SNMP, SSH and Serial) are copied into new protocol drafts. Saving a device preserves its own settings; changing defaults never updates existing device assignments. Credentials, serial endpoints and bus addresses are configured per device. Device serial settings use a private snapshot consumed by the collector; shared serial connection settings remain unchanged.

`nodes.html` previews the Node list and inline Add/Edit form with Node Name and native Cacti Site selection. Live Node CRUD shares rack topology records; nodes containing racks cannot be deleted or moved to another site through Presets. Static preview saves and deletes do not modify Cacti.

Add/Edit Device includes Port Config. The live assigned Cacti poller reads IF-MIB/IF-X-MIB over the device’s saved SNMP settings. Device Type presets supply expected physical capacity. Only real discovered interfaces appear in the list; guidance shows pending physical-port identification or asks for No. of Ports when the preset count is missing. Connector-less and unclassified interfaces remain separate. In use means link up, Available means enabled/link down, and Disabled means administratively down. Stale observations become Unknown, and changed connection settings discard old observations. Static previews include exported observations and never poll devices.

FCAPS now includes Fault, Configuration, Accounting, Performance and Security tabs. Fault rules select an associated graph template and its data source, copy numeric template data limits into new rules, and save independent per-device minimum/maximum thresholds and severity. The live assigned collector evaluates fresh native RRD AVERAGE samples before CDEF/VDEF transformations; missing values stay Unknown. Multiple rules support separate severity thresholds. Accounting/security policies and notification delivery are not configured by this screen. Static previews do not collect measurements or save live rules.
