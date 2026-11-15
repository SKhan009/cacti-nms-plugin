<?php
/** Fixed read-only Linux inventory. Hardware identifiers never become IF-MIB indexes. */
function icct_nms_hardware_command()
{
    return <<<'SH'
LC_ALL=C
export LC_ALL
[ -d /sys/bus ] || exit 1
printf 'ICCT-HARDWARE-1\n'
read_field() { if [ -r "$1" ]; then tr '\t\r\n' '   ' < "$1"; fi; }
for p in /sys/bus/usb/devices/*; do
    [ -r "$p/idVendor" ] || continue
    case "${p##*/}" in
        usb[0-9]*)
            ports=$(cat "$p/maxchild" 2>/dev/null)
            case "$ports" in ''|*[!0-9]*) ;; *) printf 'USB host\t%s\t%s\tEnumerated\n' "${p##*/}" "$ports" ;; esac
            ;;
    esac
    printf 'USB\t%s\t' "${p##*/}"
    read_field "$p/manufacturer"; printf ' '; read_field "$p/product"
    printf ' [ '; read_field "$p/idVendor"; printf ':'; read_field "$p/idProduct"
    printf ' ]\tEnumerated\n'
done
for p in /sys/class/tty/*; do
    [ -e "$p/device" ] || continue
    case "${p##*/}" in ttyS*|ttyUSB*|ttyACM*|ttyAMA*|ttyTHS*)
        printf 'Serial\t/dev/%s\tOS serial interface\tConnection not reported\n' "${p##*/}" ;;
    esac
done
for p in /sys/bus/pci/devices/*; do
    [ -r "$p/vendor" ] || continue
    printf 'PCI/PCIe\t%s\tVendor ' "${p##*/}"; read_field "$p/vendor"
    printf ' device '; read_field "$p/device"; printf ' class '; read_field "$p/class"
    printf '\tEnumerated\n'
done
for p in /sys/class/drm/*; do
    [ -r "$p/status" ] || continue
    printf 'Display connector\t%s\tGraphics driver connector\t' "${p##*/}"
    read_field "$p/status"; printf '\n'
done
for p in /sys/class/power_supply/*; do
    [ -r "$p/online" ] || continue
    type=$(cat "$p/type" 2>/dev/null)
    case "$type" in Mains|USB|USB_*) ;; *) continue ;; esac
    online=$(cat "$p/online" 2>/dev/null)
    case "$online" in 1) state=connected ;; 0) state=disconnected ;; *) continue ;; esac
    printf 'Power input\t%s\t%s\t%s\n' "${p##*/}" "$type" "$state"
done
SH;
}

function icct_nms_hardware_parse($output)
{
    if (strlen($output) > 1048576 || strpos($output, "ICCT-HARDWARE-1\n") !== 0) {
        throw new RuntimeException('Hardware inventory response unavailable or too large.');
    }
    $rows = [];
    foreach (explode("\n", trim($output)) as $line) {
        $fields = explode("\t", $line);
        if (count($fields) !== 4 || !in_array($fields[0], ['USB', 'USB host', 'Serial', 'PCI/PCIe', 'Display connector', 'Power input'], true)) {
            continue;
        }
        [$type, $name, $description, $status] = array_map('trim', $fields);
        $rows[$type . ':' . $name] = compact('type', 'name', 'description', 'status');
    }
    return array_values($rows);
}

function icct_nms_hardware_view($host)
{
    $empty = ['ports' => [], 'message' => 'Hardware inventory requires Linux SSH monitoring or local Linux collection. It does not use ARP.', 'source' => '', 'fresh' => false];
    if (empty($host['id'])) { return $empty; }
    $snapshot = json_decode((string) db_fetch_cell_prepared(
        'SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',
        ['hardware_snapshot_' . (int) $host['id']]
    ), true);
    if (!is_array($snapshot) || ($snapshot['signature'] ?? '') !== icct_backend_ports_signature($host)) { return $empty; }
    $allowed = false;
    if (($snapshot['source'] ?? '') === 'Local Linux hardware') {
        $allowed = icct_backend_identity_local_endpoint($host['hostname']) &&
            (int) $host['poller_id'] === (int) icct_backend_inventory_collector_id();
    } elseif (($snapshot['source'] ?? '') === 'SSH Linux hardware') {
        try {
            $d = icct_backend_ssh_device((int) $host['id']);
            $allowed = !empty($d['monitoring']) && icct_backend_protocol_enabled($host['id'], 'ssh') &&
                ($snapshot['endpoint'] ?? null) === $d['endpoint'] &&
                ($snapshot['device_revision'] ?? null) === $d['device_revision'] &&
                ($snapshot['preset_revision'] ?? null) === $d['preset_revision'];
        } catch (Throwable $e) {}
    }
    if (!$allowed) { return $empty; }
    $age = time() - (int) ($snapshot['time'] ?? 0);
    $fresh = $host['disabled'] === '' && $age >= 0 && $age <= 2 * max(60, (int) read_config_option('poller_interval'));
    return ['ports' => $snapshot['ports'] ?? [], 'source' => $snapshot['source'], 'fresh' => $fresh, 'collected' => (int) $snapshot['time'],
        'message' => $fresh ? 'Latest OS inventory. Enumeration does not indicate that attached equipment is operational.' : 'Last observed hardware inventory is stale; waiting for the collector.'];
}

/** Hardware uses the unsigned range above the IF-MIB InterfaceIndex maximum. */
function icct_nms_hardware_port_id($name)
{
    return 2147483648 + (hexdec(substr(hash('sha256', $name), 0, 8)) & 2147483647);
}
function icct_nms_hardware_ports($inventory, $settings)
{
    $ports = [];
    foreach ($inventory['ports'] as $row) {
        if ($row['type'] === 'USB host') { continue; }
        $name = $row['type'] . ': ' . $row['name'];
        $index = icct_nms_hardware_port_id($name);
        if (isset($ports[$index]) && $ports[$index]['name'] !== $name) {
            throw new RuntimeException('Hardware identity collision; inventory cannot be used.');
        }
        $oper = match ($row['status']) {
            'Enumerated', 'Connection not reported', 'connected' => 1,
            'disconnected', 'Not present' => 2,
            default => null,
        };
        $ports[$index] = ['index' => $index, 'name' => $name, 'panel_port' => $row['name'],
            'description' => $row['description'] ?? '',
            'hardware' => true, 'admin' => null, 'oper' => $oper,
            'status' => $row['status'], 'interface_type' => $row['type'],
            'interface_kind' => 'Unclassified', 'fresh' => $inventory['fresh'],
            'source' => $inventory['source'], 'collected' => $inventory['collected'] ?? 0];
    }
    // Missing devices are down only after a successful, fresh, complete inventory.
    foreach ($settings['ports'] as $index => $rule) {
        if ((int) $index <= 2147483647 || isset($ports[$index])) { continue; }
        $ports[$index] = ['index' => (int) $index, 'name' => $rule['name'],
            'panel_port' => substr($rule['name'], strpos($rule['name'], ': ') + 2),
            'interface_type' => strstr($rule['name'], ': ', true) ?: 'Unclassified',
            'hardware' => true, 'admin' => null, 'oper' => 2, 'status' => 'Not present',
            'fresh' => $inventory['fresh'], 'source' => $inventory['source'],
            'collected' => $inventory['collected'] ?? 0];
    }
    return array_values($ports);
}

/** Only observed USB peripherals and power inputs belong on the socket panel. */
function icct_nms_visual_hardware_ports($ports)
{
    return array_values(array_filter($ports, static function ($port) {
        if (empty($port['hardware'])) {
            return false;
        }
        if (($port['interface_type'] ?? '') === 'Power input') {
            return true;
        }
        return ($port['interface_type'] ?? '') === 'USB' &&
            preg_match('/^\d+-\d+(?:\.\d+)*$/D', (string) ($port['panel_port'] ?? '')) === 1;
    }));
}

/** Use the root hub's reported port count for grey, unoccupied USB sockets. */
function icct_nms_usb_panel_ports($hosts, $observed)
{
    $byName = array_column($observed, null, 'panel_port');
    $panel = [];
    foreach ($hosts as $host) {
        if (($host['type'] ?? '') !== 'USB host' ||
            !preg_match('/^usb([1-9]\d*)$/D', (string) ($host['name'] ?? ''), $match)) {
            continue;
        }
        $count = filter_var($host['description'] ?? null, FILTER_VALIDATE_INT);
        if ($count === false || $count < 1 || $count > 128) { continue; }
        $bus = (int) $match[1];
        for ($port = 1; $port <= $count; $port++) {
            $name = $bus . '-' . $port;
            $panel[] = $byName[$name] ?? [
                'index' => -100000 - $bus * 256 - $port,
                'name' => 'USB: ' . $name,
                'panel_port' => $name,
                'usb_slot' => true,
                'placeholder' => true,
            ];
            unset($byName[$name]);
        }
    }
    return array_merge($panel, array_values($byName));
}

function icct_nms_config_ports_view($host)
{
    $view = icct_backend_ports_view($host);
    $hardware = icct_nms_hardware_view($host);
    $view['usb_hosts'] = array_values(array_filter($hardware['ports'],
        static fn ($row) => ($row['type'] ?? '') === 'USB host'));
    $view['ports'] = array_merge($view['ports'], icct_nms_hardware_ports($hardware, icct_nms_port_monitoring($host['id'])));
    return $view;
}
