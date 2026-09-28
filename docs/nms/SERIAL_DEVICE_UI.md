# Serial device setup

Open **Device management → Add serial device**. The single-page panel layout follows `cacti-serial-corrected.html`; options come from the current Cacti installation and saved NMS profiles.

- **Device identity and connection:** Native device name, optional hostname/identifier (blank uses the endpoint), Device Template, site, collector, location and operational options. NMS short name, manual serial number and same-site node membership are saved with the device. Reuse a bus or create a named connection, with a unique Modbus unit address.
- **Serial line settings:** Choose a saved serial profile and inspect its effective settings. For a new connection, enable **Customize settings** to match the equipment. Overrides belong only to that connection; they do not edit the preset. Existing shared connections display their saved settings and collector. Use **Review shared connection settings** to explicitly apply a revised preset after inspecting all affected members.
- **Equipment and reading definitions:** Select a saved Modbus equipment profile and inspect its manufacturer, model, manual reference and register map. Definitions remain managed under Presets. Collection interval controls the NMS reading worker.
- **Cacti Data Input Method and storage:** Shows installed native Data Source Profiles and explains the existing graph provisioner. Each numeric reading uses its own script input/data template/data source; this implementation does not claim the prototype's shared multi-output RRD. Graph creation chooses a profile whose step is at least the Cacti poller interval and an exact multiple of it, preferring the largest compatible step no longer than max(reading interval, poller interval). A 60-second reader on a 300-second poller therefore graphs at 300 seconds.
- **Graph and Device Templates:** Search and add installed graph templates and compatible non-SNMP script queries. Associations are distinct from instantiated graphs; native automation may create graphs, and graph-specific inputs can be completed in Cacti after saving.
- **Create graphs and review:** Optionally create Cacti graph instances and data sources for numeric equipment readings. Review the live form summary and save once. Partial graph/query setup errors are reported against the saved device so retrying does not create another host.

Cacti remains authoritative for host records, sites, collectors, templates, data queries, graph instances and RRD storage. Core choices are loaded from its database and device creation uses the existing native API wrapper. NMS owns serial connection settings and register maps. Serial creation disables native SNMP and ping availability; collector read status reports serial health.

Only Modbus RTU through a direct Linux serial port or transparent RTU-over-TCP gateway is supported. Gateway IPs are literal addresses. Physical interface choice is determined by the adapter/wiring; the UI does not claim to switch RS-232/422/485 electrically. Gateway serial settings must be configured separately. Selecting or customizing a profile never programs the physical equipment.

After saving, **Test serial response** queues a read of one configured register through the assigned collector. Refresh displays the result; **View readings** displays collected values. The form contains no synthetic readings, simulated success, sample collectors, or arbitrary executable command inputs.

Validation includes pure profile/override checks and a CLI-only rendered fixture (`tests/nms/serial-form-fixture.php`) for browser interaction and responsive layout checks. Hardware acceptance and native device-creation integration require the QA Cacti environment.

## RS-232 and RS-485

Serial profiles and custom new-connection settings include a **Serial interface** choice: **RS-232** or **RS-485**. The saved choice is copied into the connection snapshot and displayed when reusing that bus. Existing records without a choice remain **Not specified (existing hardware)**; they are not automatically relabelled.

Modbus RTU remains the supported protocol on either interface. Select the physical port/adapter that matches the equipment. RS-485 currently requires automatic direction control provided by the adapter, or a transparent gateway configured for RS-485. Flow control must be None for RS-485; PHP admission and the collector both reject RTS/CTS in that combination. Selecting RS-485 does not switch a port's electrical mode or configure kernel/software RTS direction control. Gateway serial-side settings must be configured on the gateway itself.

Verification: profile validation/snapshot tests and all 19 transport tests passed on RHEL, including legacy settings, both interface selections through the local RTU TCP fixture, invalid-interface rejection, RS-485 flow rejection and existing pseudo-terminal tests. Real electrical RS-232/RS-485 hardware was not available for verification.

### Unified Add device entry

Use **Device management → Add device**, select **Network / SNMP** or **Serial — RS-232 / RS-485**, then click **Show settings**. Select the connection type before filling the form; switching opens a fresh form. Each route retains its existing Cacti save and graph provisioning pipeline. Legacy serial creation links redirect to the serial choice on Add device; saved serial device edit links remain supported.
