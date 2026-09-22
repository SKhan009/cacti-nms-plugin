# Topology setup — 0.12.0

Open **Topology Configuration → Setup Guide**.

1. **Device Categories:** use the header’s **+** to create a category and choose its color. Edit existing categories with the row link.
2. **Port Profiles:** use **+**, choose the category, then enter a profile name, label prefix, first number, count and connector. For example, prefix `Port `, first number `1`, count `24` produces Port 1–Port 24. Different device models can use separate profiles within one category.
3. **Assign Devices:** all accessible Cacti devices appear immediately. Select a category in the filter, choose its port profile, check the devices and select **Apply to Selected Devices**. Search, native Site and row-count filters narrow the list. The category filter includes devices without physical ports and devices already in that category.

There is no Site, Node or reference-device setup step. Create devices and configure their Site, address, SNMP and templates in **Cacti → Management → Devices**. The + in the assignment list opens Cacti’s native device form. Site is displayed and filtered from the native device record.

Assignments and generated physical ports are saved together. A failed device validation rolls back the whole selection. Reapplying the same profile retains matching port IDs, interface mappings and cables. To change the category of a device that already has ports, disconnect its cables and clear its ports first. Changing a reusable profile does not silently change already configured devices; explicitly apply it again.

## Topology View

Open the view directly from the sidebar or after assignment. Filter by Device Category, drag device headers, then Save Layout. Drag between physical ports to create a configured cable. The canvas has no secondary tabs. Use − / + to zoom (20–200%) and Fit to Screen to fit the configured devices. Zoom changes the view only; Save Layout stores device positions. Select a connected port or cable, then use Disconnect to remove that configured connection. Escape clears the selection. Category color, Cacti device health and configured cable labels describe different things. Filtering out a peer does not make its connected port available.

Each device’s Configure ports button opens its port settings. Map interfaces from Cacti’s native interface query cache; a numbered physical port is not automatically an SNMP ifIndex.

## Discovery

Discovery uses four native Cacti tabs:

- **Collection Settings:** choose the schedule, interval and stale threshold, or load a saved profile.
- **Device Protocols:** choose LLDP, CDP, both or none for each assigned device.
- **Run & Results:** run discovery using saved settings and browse recent jobs with native pagination.
- **Saved Profiles:** create reusable settings with the header **+**, or edit an existing profile through its row link.

Switching tabs retains unsaved collection and protocol inputs. **Save** applies both sections together and stays on the selected tab. **Save & Discover Now** saves both sections and opens Results with the queued job. **Discover Now** on Results uses saved settings. New assignments start with no neighbor collection; existing protocol choices are preserved. SNMP configuration remains in the native Cacti device editor.

Optional **Load Profile** fills the configuration form without saving. Check **Use profile protocol for all devices** only when all listed devices should receive that protocol. Saving a profile does not apply it automatically. Invalid configuration is rejected before settings are changed; a changed assigned-device list requires reloading the form.

Enable LLDP/CDP on the device and allow the required MIB reads separately. The plugin reads IF-MIB and LLDP-MIB or CISCO-CDP-MIB. It does not enable protocols on switches. Neither protocol nor credential fallback is attempted.

One collection policy applies to the assigned topology. Applying shared policy requires visibility of all assigned devices. Read-only views respect native device permissions. Discovery currently supports up to 32 visible assigned devices on the local collector, with bounded collection time and object limits.

## Simulator

Use Simulator → Upload SNMP Record, select a Device Category, and upload a static record. Node selection is removed. Saving queues activation and automatic creation of the native device, data sources and graphs. Refresh the Imports list to see Pending or Provisioned; failed imports show the error and Retry Provisioning. Test SNMP remains available. Use Assign Devices to apply a physical port profile. New simulator devices have Cacti’s Unassigned Site; edit their Site in the native device form if needed.

Use unique advertised identities in uploaded records. Duplicate identities remain unresolved; no guessed links are created. Numeric metrics become native data sources/graphs; static counters may graph zero rates.

## Upgrade preservation

Version 0.8.0 consolidates existing plugin assignments into one internal collection and layout scope. Existing native device records, Sites, categories, physical port IDs/mappings, cables, current device positions, imports, data sources and graphs are preserved. Legacy node records and positions remain stored for history but are not an operator prerequisite.

Identical legacy discovery policies carry forward. If legacy policies conflict, the plugin archives them and leaves the new policy unconfigured until explicitly saved in Discovery. Stop the discovery timer and allow any active job to finish before running the plugin upgrade; restart the timer afterward. Keep a database and plugin backup before upgrading.
