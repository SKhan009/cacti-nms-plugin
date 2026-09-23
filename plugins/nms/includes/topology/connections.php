<?php
/** Operator-declared map connections, independent of discovery evidence. */
require_once __DIR__ . '/../relationships.php';
require_once __DIR__ . '/../device_metadata.php';
require_once __DIR__ . '/config.php';
function nms_connection_schema() {
    nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_connection_types (
        name VARCHAR(24) NOT NULL PRIMARY KEY, color CHAR(7) NOT NULL,
        line_style VARCHAR(12) NOT NULL, symbol VARCHAR(12) NOT NULL
    ) ENGINE=InnoDB");
    foreach (['Ethernet','Fiber','Wireless','Logical'] as $name) {
        nms_category_execute("INSERT IGNORE INTO plugin_nms_connection_types VALUES (?, '#64748b', 'dashed', 'circle')", [$name]);
    }
    nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_manual_connections (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        fingerprint CHAR(64) NOT NULL UNIQUE,
        a VARCHAR(240) NOT NULL, b VARCHAR(240) NOT NULL,
        a_identity VARCHAR(512) NOT NULL, b_identity VARCHAR(512) NOT NULL,
        type VARCHAR(24) NOT NULL, label VARCHAR(150) NOT NULL DEFAULT '',
        speed_mbps DECIMAL(14,3) NOT NULL DEFAULT 0,
        updated_by INT UNSIGNED NOT NULL, updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB");
}
function nms_connection_endpoint($value) {
    $e = nms_relationship_endpoint($value);
    $site = nms_single_topology_site();
    if ($site && (int) db_fetch_cell_prepared('SELECT site_id FROM host WHERE id=?', [$e['host_id']]) !== $site) {
        throw new InvalidArgumentException('Device is outside the configured topology site.');
    }
    return $e;
}
function nms_connection_fingerprint($a, $b) {
    $ends = [$a, $b]; sort($ends, SORT_STRING);
    return hash('sha256', json_encode($ends));
}
function nms_connection_style($in) {
    if (!is_string($in['color'] ?? null) || !preg_match('/^#[0-9a-f]{6}$/iD', $in['color']) ||
        !in_array($in['line_style'] ?? '', ['solid','dashed','dotted'], true) ||
        !in_array($in['symbol'] ?? '', ['none','circle','square','arrow'], true)) {
        throw new InvalidArgumentException('Select a colour, line style and endpoint symbol.');
    }
    return [$in['color'], $in['line_style'], $in['symbol']];
}
function nms_connection_save($in) {
    nms_require_management();
    $action = $in['nms_action'] ?? '';
    if ($action === 'connection_style') {
        $style = nms_connection_style($in);
        if (!in_array($in['type'] ?? '', ['Ethernet','Fiber','Wireless','Logical'], true)) throw new InvalidArgumentException('Unknown connection type.');
        nms_category_execute('UPDATE plugin_nms_connection_types SET color=?,line_style=?,symbol=? WHERE name=?', [...$style, $in['type']]);
        return;
    }
    $id = nms_topology_integer($in['id'] ?? 0, 0, 2147483647, 'Connection');
    if ($id) {
        $old = db_fetch_row_prepared('SELECT * FROM plugin_nms_manual_connections WHERE id=?', [$id]);
        if (!$old) throw new InvalidArgumentException('Connection no longer exists.');
        // Authorise both original endpoints even when replacing them.
        foreach (['a','b'] as $side) nms_require_device_access((int) explode(':', $old[$side])[0]);
    }
    if ($action === 'connection_delete' && $id) {
        nms_category_execute('DELETE FROM plugin_nms_manual_connections WHERE id=?', [$id]); return;
    }
    if ($action !== 'connection_save') throw new InvalidArgumentException('Unknown connection action.');
    $a = nms_connection_endpoint($in['a'] ?? ''); $b = nms_connection_endpoint($in['b'] ?? '');
    if ($a['host_id'] === $b['host_id']) throw new InvalidArgumentException('Select two different devices.');
    $type = $in['type'] ?? '';
    if (!in_array($type, ['Ethernet','Fiber','Wireless','Logical'], true)) throw new InvalidArgumentException('Select a connection type.');
    $speed = $in['speed_mbps'] ?? '0';
    if (!is_scalar($speed) || !is_numeric($speed) || !is_finite((float)$speed) || $speed < 0 || $speed > 100000000) throw new InvalidArgumentException('Capacity must be between 0 and 100,000,000 Mbps.');
    $label = nms_classification_text($in['label'] ?? '', 150);
    $fp = nms_connection_fingerprint($in['a'], $in['b']);
    if (db_fetch_cell_prepared('SELECT id FROM plugin_nms_manual_connections WHERE fingerprint=? AND id<>?', [$fp,$id])) throw new InvalidArgumentException('This connection already exists, including in the reverse direction.');
    $values = [$fp,$in['a'],$in['b'],$a['identity'],$b['identity'],$type,$label,$speed,nms_current_user_id()];
    if ($id) nms_category_execute('UPDATE plugin_nms_manual_connections SET fingerprint=?,a=?,b=?,a_identity=?,b_identity=?,type=?,label=?,speed_mbps=?,updated_by=?,updated_at=NOW() WHERE id=?', [...$values,$id]);
    else nms_category_execute('INSERT INTO plugin_nms_manual_connections (fingerprint,a,b,a_identity,b_identity,type,label,speed_mbps,updated_by,updated_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())', $values);
}
function nms_connection_rows() {
    $va = nms_visible_host_sql('ha.id'); $vb = nms_visible_host_sql('hb.id');
    return db_fetch_assoc("SELECT m.*, t.color,t.line_style,t.symbol FROM plugin_nms_manual_connections m
        JOIN host ha ON ha.id=CAST(SUBSTRING_INDEX(m.a, ':', 1) AS UNSIGNED) AND ha.deleted='' AND ha.disabled=''
        JOIN host hb ON hb.id=CAST(SUBSTRING_INDEX(m.b, ':', 1) AS UNSIGNED) AND hb.deleted='' AND hb.disabled=''
        JOIN plugin_nms_connection_types t ON t.name=m.type WHERE $va AND $vb ORDER BY m.id DESC");
}
function nms_connection_links($nodes) {
    $by = array_column($nodes, null, 'id'); $links=[];
    foreach (nms_connection_rows() as $r) {
        $ends=[]; $valid=true;
        foreach (['a','b'] as $s) {
            [$host,$query,$index] = explode(':',$r[$s],3); $host=(int)$host;
            if (!isset($by[$host])) { $valid=false; break; }
            $identity = (int)$query ? nms_relationship_interface_identity($host,(int)$query,$index) : '';
            $matches = $identity === $r[$s.'_identity'];
            $monitorIndex = 0;
            // Only map a native IF-MIB index when the recorded name also matches.
            // Other Cacti query indexes must never be mistaken for network ports.
            if ($matches && (int)$query && ctype_digit($index)) {
                $recordedName = preg_replace('/^[^:]+:/','',$identity);
                foreach ($by[$host]['interfaces'] ?? [] as $iface) {
                    if ((int)$iface['index'] === (int)$index && in_array($recordedName, [$iface['name'], $iface['description'] ?? ''], true)) {
                        $monitorIndex = (int)$index; break;
                    }
                }
            }
            $ends[$s] = [$host, $monitorIndex,
                $r[$s.'_identity'] ? preg_replace('/^[^:]+:/','',$r[$s.'_identity']) : 'Device (no interface)', $matches];
        }
        if (!$valid) continue;
        $links[]=['id'=>'m'.$r['id'],'manual_id'=>(int)$r['id'], 'manual'=>true,
            'a'=>$ends['a'][0], 'b'=>$ends['b'][0], 'a_ifindex'=>$ends['a'][1], 'b_ifindex'=>$ends['b'][1],
            'a_port'=>$ends['a'][2], 'b_port'=>$ends['b'][2], 'current'=>false,
            'state'=>($ends['a'][3] && $ends['b'][3]) ? 'Manually configured' : 'Manually configured — interface needs revalidation',
            'label'=>$r['label'] ?: $r['type'], 'speed'=>(float)$r['speed_mbps']*1000000,
            'color'=>$r['color'],'line_style'=>$r['line_style'],'symbol'=>$r['symbol'],'protocols'=>[]];
    }
    return $links;
}
