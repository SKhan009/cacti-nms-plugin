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
- `images/`: supplied DRDO and Bharat Electronics SVG header logos.
- `js/inventory.js`: table, navigation and form display behavior.
- `js/preview.js`: static-preview form handling and HTML navigation.
- `inventory.csv`: saved inventory export from the VM.

HTML pages contain no PHP or authentication tokens. Password input values are blank. Forms are preview-only and never write device settings; their submit buttons explain that saving requires the deployed Cacti plugin. Graph and account links open the VM. The production PHP plugin remains in `plugins/icct_nms`.

Serve this folder with:

```sh
python3 -m http.server 8765 --bind 127.0.0.1 --directory html-css/icct_nms
```
