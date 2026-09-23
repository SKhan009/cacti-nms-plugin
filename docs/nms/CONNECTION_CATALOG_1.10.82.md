# Connection catalog — NMS 1.10.82

Under Topology → Edit topology:

- Manual connections: use **Add connection** or **Edit** to open a popup. The bin icon asks for confirmation before deleting the map connection.
- Connection types: three columns on desktop, two or one on smaller screens. Each card shows the line and endpoint symbols.
- Use **Add connection type** to create a named style. **Edit** changes its name, colour, line pattern and endpoint symbol, with a live preview.
- Renaming a type updates existing manual connections. A type used by any connection cannot be deleted. Reassign those connections first.
- Changes are saved in the plugin database. Existing connections and their styles are preserved. Deleted types are not recreated by subsequent upgrades.

The popup supports Close, Cancel and Escape. Validation errors stay in the popup. Successful saves return to the list with feedback.

Verification: PHP and JavaScript syntax; 29 helper checks; transactional VM checks for custom creation, rename propagation, in-use deletion prevention and unused deletion. Temporary test changes are rolled back.

Repository: https://github.com/SKhan009/cacti-nms-plugin
