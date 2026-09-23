<?php
/** Operator-declared map connections, independent of discovery evidence. */
require_once __DIR__ . '/../relationships.php';
require_once __DIR__ . '/../device_metadata.php';
require_once __DIR__ . '/config.php';
function nms_connection_types() {
    return ['Ethernet'=>'Ethernet','Fiber'=>'Fiber','Wireless'=>'Wireless','Logical'=>'Logical',
        'VSAT / Leased-line'=>'VSAT, Leased-line link','Optical fiber'=>'Optical fiber link','Line-of-sight (LOS)'=>'Line-of-sight (LOS) link'];
}
function nms_connection_patterns() {
    return ['solid'=>'','dashed'=>'9 5','dotted'=>'2 5','dash-dot'=>'10 4 2 4','fine-dotted'=>'1 3','short-dashed'=>'4 4'];
}
function nms_connection_schema() {
    nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_link_classification (
        link_key CHAR(64) NOT NULL PRIMARY KEY, type VARCHAR(24) NOT NULL,
        updated_by INT UNSIGNED NOT NULL, updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB");
    nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_connection_types (
        name VARCHAR(24) NOT NULL PRIMARY KEY, color CHAR(7) NOT NULL,
        line_style VARCHAR(12) NOT NULL, symbol VARCHAR(12) NOT NULL
    ) ENGINE=InnoDB");
    if (!db_fetch_cell_prepared("SELECT meta_value FROM plugin_nms_meta WHERE meta_key=?", ["connection_types_seeded"])) {
    foreach (array_keys(nms_connection_types()) as $name) {
        nms_category_execute("INSERT IGNORE INTO plugin_nms_connection_types VALUES (?, ?, ?, ?)", [$name,
            in_array($name,['VSAT / Leased-line','Optical fiber','Line-of-sight (LOS)'],true)?'#334155':'#64748b',
            ['VSAT / Leased-line'=>'dash-dot','Optical fiber'=>'fine-dotted','Line-of-sight (LOS)'=>'short-dashed'][$name] ?? 'dashed',
            in_array($name,['VSAT / Leased-line','Optical fiber','Line-of-sight (LOS)'],true)?'none':'circle']);
    }
    nms_category_execute("INSERT INTO plugin_nms_meta (meta_key,meta_value,updated_at) VALUES ('connection_types_seeded','1',NOW())");
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
        !in_array($in['line_style'] ?? '', array_keys(nms_connection_patterns()), true) ||
        !in_array($in['symbol'] ?? '', ['none','circle','square','arrow'], true)) {
        throw new InvalidArgumentException('Select a colour, line style and endpoint symbol.');
    }
    return [$in['color'], $in['line_style'], $in['symbol']];
}
function nms_connection_save($in) {
    nms_require_management();
    $action = $in['nms_action'] ?? '';
    if ($action === 'connection_classify') {
        require_once __DIR__.'/canvas.php';
        $key=$in['link_key'] ?? ''; $type=$in['type'] ?? '';
        if (!is_string($key) || !preg_match('/^[a-f0-9]{64}$/D',$key)) throw new InvalidArgumentException('Invalid discovered link.');
        $found=false;
        foreach (nms_canvas_data(null)['links'] as $link) {
            if (empty($link['manual']) && nms_connection_link_key($link)===$key) { $found=true; break; }
        }
        if (!$found) throw new InvalidArgumentException('Link is no longer available or accessible. Refresh discovery and try again.');
        if ($type==='__unclassified__') {
            nms_category_execute('DELETE FROM plugin_nms_link_classification WHERE link_key=?',[$key]); return;
        }
        if (!is_string($type) || !db_fetch_cell_prepared('SELECT name FROM plugin_nms_connection_types WHERE name=?',[$type])) throw new InvalidArgumentException('Select a connection type.');
        nms_category_execute('INSERT INTO plugin_nms_link_classification (link_key,type,updated_by,updated_at) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE type=VALUES(type),updated_by=VALUES(updated_by),updated_at=NOW()',[$key,$type,nms_current_user_id()]);
        return;
    }
    if (in_array($action, ['connection_type_save','connection_type_delete','connection_style'], true)) {
        $old = nms_classification_text($in['original_type'] ?? $in['type'] ?? '', 24);
        if ($action === 'connection_type_delete') {
            if (db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_nms_manual_connections WHERE type=?', [$old]) || db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_nms_link_classification WHERE type=?', [$old])) throw new InvalidArgumentException('This type is in use. Change its connections before deleting it.');
            nms_category_execute('DELETE FROM plugin_nms_connection_types WHERE name=?', [$old]);
            return;
        }
        $name = nms_classification_text($in['type'] ?? '', 24);
        if ($name === '') throw new InvalidArgumentException('Enter a connection type name.');
        $style = nms_connection_style($in);
        if ($old !== '' && !db_fetch_cell_prepared('SELECT name FROM plugin_nms_connection_types WHERE name=?', [$old])) throw new InvalidArgumentException('Connection type no longer exists.');
        if ($old !== $name && db_fetch_cell_prepared('SELECT name FROM plugin_nms_connection_types WHERE name=?', [$name])) throw new InvalidArgumentException('A connection type with this name already exists.');
        if ($old !== '') {
            nms_category_execute('UPDATE plugin_nms_connection_types SET name=?,color=?,line_style=?,symbol=? WHERE name=?', [$name,...$style,$old]);
            nms_category_execute('UPDATE plugin_nms_manual_connections SET type=? WHERE type=?', [$name,$old]);
            nms_category_execute('UPDATE plugin_nms_link_classification SET type=? WHERE type=?', [$name,$old]);
        } else nms_category_execute('INSERT INTO plugin_nms_connection_types (name,color,line_style,symbol) VALUES (?,?,?,?)', [$name,...$style]);
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
    if (!is_string($type) || !db_fetch_cell_prepared('SELECT name FROM plugin_nms_connection_types WHERE name=?', [$type])) throw new InvalidArgumentException('Select a connection type.');
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
            'dash'=>nms_connection_patterns()[$r['line_style']] ?? '', 'color'=>$r['color'],'line_style'=>$r['line_style'],'symbol'=>$r['symbol'],'protocols'=>[]];
    }
    return $links;
}

/** Read-only rows retain discovery provenance; never assign a manual record ID. */
function nms_connection_discovered_rows($canvas) {
    $nodes=array_column($canvas['nodes'] ?? [], null, 'id'); $rows=[];
    foreach ($canvas['links'] ?? [] as $link) {
        if (!empty($link['manual']) || !isset($nodes[$link['a']],$nodes[$link['b']])) continue;
        $row=['id'=>null,'source'=>'Auto-detected','link_key'=>nms_connection_link_key($link),'type'=>$link['connection_type'] ?? 'Unclassified',
            'protocol'=>implode('/',array_keys($link['protocols'] ?? [])) ?: 'Discovery','status'=>(string)($link['state'] ?? 'Unknown'),
            'detected_type'=>$link['detected_type'] ?? 'Unknown', 'capacity_display'=>$link['capacity_display'] ?? 'Not reported by interfaces', 'label'=>'', 'speed_mbps'=>max(0,(float)($link['speed'] ?? 0))/1000000];
        if ($row['type']==='') $row['type']='Discovery';
        foreach (['a','b'] as $side) {
            $row[$side]=(string)$link[$side];
            $port=trim((string)($link[$side.'_port'] ?? ''));
            $row[$side.'_display']=$nodes[$link[$side]]['name'].($port!==''?' / '.$port:' (interface unknown)');
        }
        $rows[]=$row;
    }
    return $rows;
}

/** Direction-independent identity; port changes require a fresh classification. */
function nms_connection_link_key($link) {
    $ends=[];
    foreach (['a','b'] as $side) $ends[]=json_encode([(string)$link[$side],(string)($link[$side.'_ifindex'] ?? ''),(string)($link[$side.'_port'] ?? '')]);
    sort($ends,SORT_STRING);
    return hash('sha256',json_encode($ends));
}
function nms_connection_apply_classifications($links) {
    $saved=array_column(db_fetch_assoc('SELECT c.link_key,c.type,t.color,t.line_style,t.symbol FROM plugin_nms_link_classification c JOIN plugin_nms_connection_types t ON t.name=c.type'),null,'link_key');
    foreach ($links as &$link) {
        if (!empty($link['manual'])) continue;
        $style=$saved[nms_connection_link_key($link)] ?? null;
        if (!$style) continue;
        $link['connection_type']=$style['type'];
        foreach (['color','line_style','symbol'] as $field) $link[$field]=$style[$field];
        $link['dash']=nms_connection_patterns()[$style['line_style']] ?? '';
        // Preserve protocol evidence, state and current flag exactly as discovered.
        $link['label']=$style['type'].' · '.($link['label'] ?? '');
    }
    unset($link); return $links;
}

/** IF-MIB describes interfaces, not the underlying end-to-end carrier service. */
function nms_connection_iftype_label($type) {
    return [6=>'Ethernet',7=>'Ethernet',62=>'Ethernet',69=>'Ethernet (100BASE-FX)',117=>'Ethernet',
        71=>'Wi-Fi (802.11)',23=>'PPP',135=>'VLAN (802.1Q)',136=>'Layer 3 VLAN',
        131=>'Tunnel',150=>'MPLS tunnel',161=>'Link aggregation (LAG)',
        24=>'Loopback',32=>'Frame Relay',37=>'ATM',39=>'SONET',56=>'Fibre Channel',
        118=>'HDLC',157=>'Point-to-point wireless'][ (int)$type ] ?? null;
}
function nms_connection_detect_interfaces($links,$nodes) {
    $by=array_column($nodes,null,'id');
    foreach ($links as &$link) {
        if (!empty($link['manual'])) continue;
        $types=[]; $speeds=[];
        foreach (['a','b'] as $side) {
            $index=(int)($link[$side.'_ifindex'] ?? 0);
            // Never guess Ethernet from port names, LLDP, speed, or ARP alone.
            if ($index<=0) continue;
            $matches=array_values(array_filter($by[$link[$side]]['interfaces'] ?? [],static fn($p)=>(int)$p['index']===$index));
            if (count($matches)!==1) continue;
            $speed=max((float)($matches[0]['high_speed_mbps'] ?? 0)*1000000,(float)($matches[0]['speed_bps'] ?? 0));
            if ($speed>0) $speeds[$side]=$speed;
            $type=$matches[0]['if_type'] ?? null;
            $label=nms_connection_iftype_label($type);
            if ($label!==null) $types[$side]=$label;
        }
        $link['capacity_display']=count($speeds)===2 && $speeds['a']===$speeds['b']
            ? nms_connection_rate_label($speeds['a']).' · SNMP interface speed'
            : implode('; ',array_map(static fn($side)=>strtoupper($side).': '.nms_connection_rate_label($speeds[$side]),array_keys($speeds)));
        if (!$speeds) $link['capacity_display']='No current interface speed';
        elseif (count($speeds)===1) $link['capacity_display'].='; other endpoint not reported';
        $link['detected_type']=count($types)===2 && $types['a']===$types['b']
            ? $types['a'].' — detected'
            : implode('; ',array_map(static fn($side)=>strtoupper($side).': '.$types[$side].' — detected',array_keys($types)));
        if ($link['detected_type']==='') $link['detected_type']='Unknown — no current interface type';
        elseif (count($types)===1) $link['detected_type'].='; other endpoint unknown';
    }
    unset($link); return $links;
}

function nms_connection_rate_label($bps) {
    if (!is_numeric($bps) || !is_finite((float)$bps) || $bps<=0) return 'Not reported';
    $value=(float)$bps; $units=['bps','Kbps','Mbps','Gbps','Tbps']; $i=0;
    while ($value>=1000 && $i<count($units)-1) { $value/=1000; $i++; }
    return rtrim(rtrim(number_format($value,3,'.',','),'0'),'.').' '.$units[$i];
}
