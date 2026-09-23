# Device connections — NMS 1.10.83

The Connections tab now lists both manual map connections and protocol-discovered connections. A Source column distinguishes them. Search and pagination apply to the combined list.

Manual rows retain Edit and Delete with consistent spacing. Auto-detected rows are read-only; discovery owns their records. The list uses the same site- and permission-filtered evidence as the topology map, including its discovery state and known interface names. Capacity is the configured value for manual rows and reported interface speed for discovered rows; unknown values remain Unknown.

Popup header close buttons use a shared centered SVG icon and a 44-pixel control across connection, connection-type, diagnostic-profile, discovery-preset, catalog and upload dialogs.

Validated PHP syntax, manual-connection helpers, discovered-row provenance and endpoint filtering, and transactional connection-type CRUD. VM rendering contained seven auto-detected read-only rows and the existing manual row. Browser visual verification was inconclusive because the browser automation returned a blank view after reload.

Repository: https://github.com/SKhan009/cacti-nms-plugin
