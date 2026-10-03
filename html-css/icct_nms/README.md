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
