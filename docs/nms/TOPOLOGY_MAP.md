# Topology map

## Current offline map

The active map now uses bundled Leaflet, bundled India state/UT GeoJSON and the
configured local GeoServer WMS via the authenticated NMS endpoint. MapLibre, its
Leaflet bridge, the OpenFreeMap style and the external CSP allowance have been
removed. There are no online tile, font or sprite requests. English state labels
use local label coordinates and system fonts. Country boundaries come from the
local Natural Earth layer. Street-level detail requires additional locally
installed data; selecting a site now uses overview zoom 8. Device coordinates
and monitoring data still come from native Cacti records. Map help is removed.

The sections below retain the implementation history; references to online
street providers describe superseded behavior.

Topology has **Network view** and **Map view** tabs. Network view continues to show
LLDP/CDP and saved device layouts. Map view groups permitted devices by their
native Cacti site. It does not create a second site or coordinate database.

Set latitude and longitude in Cacti **Sites**, then assign the site in Cacti
**Devices**. Coordinates must be within latitude −90…90 and longitude −180…180.
Cacti's default `0,0` is treated as unset. Unlocated devices remain listed below
the map. Disabled devices remain visible with their disabled state. Site names,
counts and device status include only devices the current Cacti user can access.
Refresh reloads the current native records; there is no copied location cache.

## GeoServer configuration

Install a supported GeoServer release and publish a world basemap layer. The VM
setup uses GeoServer 2.28.5, Java 17 and Natural Earth 1:110m country boundaries.
Keep GeoServer's administration endpoint private. In Cacti's administrator-owned
`include/config.php`, configure the WMS endpoint and published layer:

```php
$config['nms_geoserver_wms_url'] = 'http://127.0.0.1:8090/geoserver/wms';
$config['nms_geoserver_layer'] = 'nms:countries';
```

The loopback address above is the GeoServer service endpoint on this collector,
not a device target or assumed site location. Use the actual service URL for
another installation. PHP needs cURL and permission to reach that endpoint.
Leaflet 1.9.4 is bundled locally. The GeoServer overview uses only the local
service; the optional Streets layer loads map images directly from its configured
online provider. Authenticated `topology.php?tab=map&map_tile=1` relays bounded PNG WMS
GetMap requests. A single viewport image avoids country labels repeating across adjacent tiles. Users cannot choose the upstream URL, layer, projection, service operation. Image dimensions are limited to 1–2048 pixels per axis. Browser requests contain viewport bounds, not device
names or addresses. The map displays an explicit error if GeoServer is unavailable.

Sources: [GeoServer release](https://geoserver.org/release/maintain/),
[Leaflet](https://leafletjs.com/download.html),
[Natural Earth](https://www.naturalearthdata.com/about/terms-of-use/).

## VM demonstration data

Three existing simulated devices were assigned to native test sites:

| Native site | Existing device |
|---|---|
| Demo — Bengaluru | NMS Simulated Router (3) |
| Demo — Mumbai | NMS Simulated Server (4) |
| Demo — Delhi | NMS Simulated Sensor (5) |

These are demonstration locations, not measured equipment locations. Their
original assignments are saved in `/root/nms-map-original-sites.json` on the VM.
All three originally belonged to site 3 (Topology Lab Location 01). To undo the
demonstration, restore those device site assignments through Cacti Devices and
remove the three now-unused Demo sites through Cacti Sites. Other device
assignments and existing site coordinates were not changed.

The plugin backup before deployment is `/root/nms-before-map.tar.gz` on the VM.

## Checks

Run `php tests/nms/topology-map.php` for coordinate validation, permission-filtered
query grouping, and fixed WMS parameter checks. Also verify both tabs in a browser,
select every demo site, inspect device/status popups, and confirm GeoServer PNG
tiles load. Test with a restricted Cacti user before widening access to other users.

## Verified VM deployment — 24 September 2026

- GeoServer 2.28.5 runs as `nms-geoserver.service`, enabled at boot, bound only
  to VM loopback port 8090. The Java heap is capped at 512 MB.
- Natural Earth countries are published as `nms:countries` with the `nms-world`
  style. The initial admin password was rotated; its replacement is stored only
  in `/root/nms-geoserver-admin.json` (mode 0600) on the VM.
- All 20 visible authenticated WMS tiles loaded as 256×256 PNG images in the
  browser. Three site selectors opened their correct simulated-device popups.
- Network view continued to load its canvas. Its existing native site-3 scope
  now contains nine devices because three simulated devices were moved to the
  demo sites. No network layout configuration was changed.
- PHP syntax, bounded-WMS/coordinate fixtures, and existing drag/undo/save
  regression checks passed. Anonymous page/tile requests returned Cacti login
  content and no map data. A restricted real-user login was not available;
  permission filtering is covered by the query fixture and shared Cacti ACL.
- Deployment helper scripts are saved in `outputs/topology-map/`. These are
  one-time VM setup records, not plugin runtime dependencies.

Official binary archive SHA-256:
`77cdc75f94c017fd9b8b85273136ebe0056e8436658d610890a24ddb6f3e6620`.
ZIP integrity was checked before installation.

## Location display correction

Site names stay visible beside markers. Popups show the native site address,
city/state/country and WGS 84 latitude/longitude to six decimal places. Marker
positions use the same native coordinates, in latitude/longitude order. The three
demo positions remain illustrative city centres, not discovered equipment GPS
positions. The Natural Earth basemap is a coarse country-boundary map, not a
street or building map. Real equipment positions require actual site coordinates.

Verified on the VM: Bengaluru 12.971600,77.594600; Mumbai 19.076000,72.877700;
Delhi 28.613900,77.209000. Names are rendered as text, never interpreted as HTML.

## Detailed street layer

The VM defaults to **Streets** (OpenStreetMap). It displays city/neighbourhood
names, roads, parks, points of interest and mapped buildings as you zoom in.
Selecting a native Cacti site opens its saved coordinates at zoom 16. The
**Country overview (GeoServer)** option retains the local offline overview.

Administrator settings in Cacti `include/config.php`:

```php
$config['nms_street_tiles_url'] = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
$config['nms_street_tiles_attribution'] = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';
```

Only the configured HTTPS image hostname is added to the map page's `img-src`
policy. Other CSP restrictions and other pages are unchanged. The browser uses
normal caching and sends its normal referrer; there is no bulk fetching, offline
street download, or anonymous tile proxy. Observe the provider's
[tile policy](https://operations.osmfoundation.org/policies/tiles/). Online street
view requires internet connectivity and depends on provider availability.

Locations remain native Cacti data. No IP geolocation or automatic coordinate
overwrite occurs. Existing non-demo sites Edge, Core and Topology Lab Location 01
have empty address fields and default 0,0 coordinates on the checked VM. They
need real location data in Cacti before their devices can be mapped.

Verified visually on 24 September: Bengaluru at 12.971600,77.594600 displays
Vittal Mallya Road, nearby buildings, Cubbon Park and Sree Kanteerava Stadium.
This verifies geographic alignment with the stored site coordinate; it does not
certify that the simulated device is physically at that address.

## Current map mode: India, English only

The street renderer now uses OpenFreeMap vector data through bundled MapLibre
GL JS 5.24.0 and MapLibre GL Leaflet 0.1.4. The earlier OpenStreetMap raster
settings are superseded because raster text cannot be translated. The local
`css/nms-map-style.json` selects only `name:en` / `name_en` values for named
labels; missing English names are omitted instead of falling back to local script.
Road reference numbers remain visible. Cacti site names are preserved as entered.

The map opens on India, has a minimum country zoom, and restricts panning to the
India region. An inverse mask hides other countries using Natural Earth 1:10m
India geometry (`css/nms-map-india.json`), including its mapped islands. The
**India** button resets the view. Geometry is a cartographic display dataset,
not a survey or a change to saved Cacti coordinates.

Only `https://tiles.openfreemap.org` is added to this page's image/connect CSP.
Scripts and the CSP-compatible worker are bundled locally; worker-src remains
self. Vector tiles/fonts/sprites require internet connectivity. GeoServer remains
the local overview option, with the same country mask.

Verified India overview, English street labels around Bengaluru, saved coordinate
popup, visible attribution, and no browser console errors.

Sources: https://openfreemap.org/quick_start/ and the public-domain Natural Earth
vector repository (`ne_10m_admin_0_countries.geojson`).

## Compact device popup

The map popup now shows only device name, recorded address/system description,
availability, category, memory, serial number, response time, packet loss, CPU,
and the most recent active alarm. Coordinates and location guidance are removed
from the popup. A selector appears only when a site contains multiple devices.

All queries reuse the Cacti host ACL. Metrics must be captured within two native
poll intervals; unsupported, stale and invalid percentages display Not available.
CPU uses an explicit percentage source or 100 minus UCD CPU idle percentage,
never load average. Memory is an explicit percentage or physical non-free/total
memory, including cache. Packet loss is never inferred from poll availability.
The serial uses saved asset metadata or successful observed inventory. Device
response time is Cacti's current value, only for a recently polled up device.
An empty active incident query displays No active alarms. Browser verification
confirmed the requested compact fields and absence of the old coordinate text.

### Screen-fitting India view

The map page fills the viewport below the NMS navigation. A compact heading and toolbar leave the remaining space for the map; help and unlocated-device lists expand over it. India is fitted with fractional zoom and padding on opening and via the India button. Resizing the viewport or sidebar recalculates the country fit; selected-site and manual zoom views retain their context. English street labels and native Cacti device popups remain available.

Verified on the RHEL VM: at 1280×720 the document height is exactly 720 pixels with the sidebar both expanded and collapsed; the map remains entirely visible. Selecting Mumbai opens the monitoring popup, and India restores the country view. PHP and JavaScript syntax checks pass; browser console contains no errors.

### State overview and compact popup

The English street map includes a bundled, colored 36-feature state/union-territory layer, independent of vector-tile zoom thresholds. Labels use English transliterations. Collision handling reveals smaller-area labels as users zoom in. Site labels appear on hover so they do not cover state names. The mask uses the union of the same polygons and is drawn before state labels, preventing clipped border labels.

Source: geoBoundaries gbOpen IND ADM1, boundaryID IND-ADM1-1811400, pinned release 9469f09, simplified GeoJSON. Original source: DataMeet India community / Election Commission of India. License: CC BY 2.5 IN (https://creativecommons.org/licenses/by/2.5/in/). API metadata: https://www.geoboundaries.org/api/current/gbOpen/IND/ADM1/ . The metadata reports boundaryYearRepresented 2011 and a December 2023 build; the supplied 36 features include Ladakh, Telangana, and merged Dadra and Nagar Haveli and Daman and Diu. This is a visualization dataset, not a newly surveyed or independently certified boundary map. Simplified at 0.008 degrees with topology preservation for the country overview; label points come from the largest polygon of each feature. Attribution is shown on the map. `css/nms-map-states.json` contains polygons; `nms-map-india.json` is their union.

Device popups are 260 pixels wide, anchored inside the map's upper-right corner, with scrollable content on short screens. Verified the Delhi marker at country zoom: popup rectangle 260×283 lies fully inside the 1026×482 map; it no longer moves above the map. Checked 36 unique state/UT names and valid geometries with Shapely, plus JavaScript syntax.

### Neighbours and island navigation

The India-only masks and pan restriction were removed at the user's request. The initial India bounds still include the full island extent; surrounding countries and their English names are visible. The Island view selector provides fitted Andaman and Nicobar and Lakshadweep views, while India restores the national overview. These are geographic navigation extents, not device coordinates. Checked both island views on the VM and retained state colors, native Cacti markers, and the compact popup. PHP and JavaScript syntax checks passed.

### Simplified toolbar and anchored popup

The toolbar now retains Site, Fit sites and an accessible refresh icon only. Map style, India and Island view controls were removed; the English street map is the default and pan/zoom remains available. The compact popup is anchored to its selected marker using Leaflet auto-pan and keep-in-view, replacing the fixed corner panel. Verified the northern Delhi marker popup remains entirely within the map, and Fit sites and refresh operate correctly on the VM. PHP and JavaScript syntax checks passed.
