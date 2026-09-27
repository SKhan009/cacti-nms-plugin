<?php
/** Plugin storage and revision-controlled serial presets; no equipment I/O here. */
require_once __DIR__ . '/validation.php';

function nms_configuration_schema()
{
    nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_serial_profiles (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT, name VARCHAR(150) NOT NULL,
        description VARCHAR(512) NOT NULL, manufacturer VARCHAR(120) NOT NULL,
        model VARCHAR(120) NOT NULL, settings_json TEXT NOT NULL,
        revision INT UNSIGNED NOT NULL DEFAULT 1, updated_by INT UNSIGNED NOT NULL,
        updated_at DATETIME NOT NULL, PRIMARY KEY(id), UNIQUE KEY name(name)
    ) ENGINE=InnoDB ROW_FORMAT=Dynamic");
    nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_serial_connections (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT, name VARCHAR(150) NOT NULL,
        poller_id INT UNSIGNED NOT NULL, transport VARCHAR(32) NOT NULL,
        endpoint VARCHAR(512) NOT NULL, endpoint_key CHAR(64) NOT NULL,
        profile_id INT UNSIGNED NOT NULL, profile_revision INT UNSIGNED NOT NULL,
        settings_json TEXT NOT NULL, revision INT UNSIGNED NOT NULL DEFAULT 1,
        enabled TINYINT UNSIGNED NOT NULL DEFAULT 1, updated_by INT UNSIGNED NOT NULL,
        updated_at DATETIME NOT NULL, PRIMARY KEY(id), UNIQUE KEY endpoint_key(endpoint_key),
        KEY profile_id(profile_id), KEY poller_id(poller_id)
    ) ENGINE=InnoDB ROW_FORMAT=Dynamic");
    nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_serial_devices (
        host_id MEDIUMINT UNSIGNED NOT NULL, connection_id INT UNSIGNED NOT NULL,
        device_address SMALLINT UNSIGNED NOT NULL, revision INT UNSIGNED NOT NULL DEFAULT 1,
        updated_by INT UNSIGNED NOT NULL, updated_at DATETIME NOT NULL,
        PRIMARY KEY(host_id), UNIQUE KEY bus_address(connection_id,device_address)
    ) ENGINE=InnoDB ROW_FORMAT=Dynamic");

    nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_config_profiles (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT, name VARCHAR(150) NOT NULL,
        manufacturer VARCHAR(120) NOT NULL, model VARCHAR(120) NOT NULL,
        manual_reference VARCHAR(512) NOT NULL, protocol VARCHAR(32) NOT NULL,
        fields_json MEDIUMTEXT NOT NULL, revision INT UNSIGNED NOT NULL DEFAULT 1,
        updated_by INT UNSIGNED NOT NULL, updated_at DATETIME NOT NULL,
        PRIMARY KEY(id), UNIQUE KEY name(name)
    ) ENGINE=InnoDB ROW_FORMAT=Dynamic");
    nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_config_devices (
        host_id MEDIUMINT UNSIGNED NOT NULL, profile_id INT UNSIGNED NOT NULL,
        credential_ref VARCHAR(100) NOT NULL DEFAULT '', interval_seconds INT UNSIGNED NOT NULL DEFAULT 300,
        revision INT UNSIGNED NOT NULL DEFAULT 1, updated_by INT UNSIGNED NOT NULL,
        updated_at DATETIME NOT NULL, PRIMARY KEY(host_id), KEY profile_id(profile_id)
    ) ENGINE=InnoDB ROW_FORMAT=Dynamic");
    nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_config_jobs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, host_id MEDIUMINT UNSIGNED NOT NULL,
        poller_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL,
        operation VARCHAR(16) NOT NULL, field_key VARCHAR(32) NOT NULL,
        signature CHAR(64) NOT NULL, before_json TEXT NOT NULL, requested_json TEXT NOT NULL,
        result_json MEDIUMTEXT NOT NULL, status VARCHAR(16) NOT NULL,
        requested_at DATETIME NOT NULL, started_at DATETIME NULL, finished_at DATETIME NULL,
        PRIMARY KEY(id), KEY collector_queue(poller_id,status,id), KEY device_history(host_id,id)
    ) ENGINE=InnoDB ROW_FORMAT=Dynamic");
    nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_serial_readings (
        host_id MEDIUMINT UNSIGNED NOT NULL, field_key VARCHAR(32) NOT NULL,
        signature CHAR(64) NOT NULL, value_json TEXT NOT NULL, status VARCHAR(16) NOT NULL,
        error_text VARCHAR(512) NOT NULL DEFAULT '', observed_at DATETIME NOT NULL,
        PRIMARY KEY(host_id,field_key)
    ) ENGINE=InnoDB ROW_FORMAT=Dynamic");

}

function nms_serial_profiles()
{
    nms_require_management();
    return db_fetch_assoc('SELECT * FROM plugin_nms_serial_profiles ORDER BY name');
}

function nms_serial_profile_get($id)
{
    nms_require_management();
    $id = nms_config_integer($id, 1, 2147483647, 'Profile ID');
    $row = db_fetch_row_prepared('SELECT * FROM plugin_nms_serial_profiles WHERE id=?', [$id]);
    if (!$row) throw new InvalidArgumentException('Serial profile not found.');
    $row['settings'] = json_decode($row['settings_json'], true, 32, JSON_THROW_ON_ERROR);
    return $row;
}

/** Old revisions fail instead of overwriting another operator's edit. */
function nms_serial_profile_save(array $input)
{
    nms_require_management();
    $id = nms_config_integer($input['id'] ?? 0, 0, 2147483647, 'Profile ID');
    $revision = nms_config_integer($input['revision'] ?? 0, 0, 2147483647, 'Revision');
    $profile = nms_serial_profile_validate($input);
    $user = nms_current_user_id();
    if ($user < 1) throw new RuntimeException('Sign in before saving a profile.');
    nms_category_execute('START TRANSACTION');
    try {
        if ($id) {
            $old = db_fetch_row_prepared('SELECT revision FROM plugin_nms_serial_profiles WHERE id=? FOR UPDATE', [$id]);
            if (!$old || (int)$old['revision'] !== $revision) throw new RuntimeException('Profile changed or was removed. Reload before saving.');
        }
        if (db_fetch_cell_prepared('SELECT id FROM plugin_nms_serial_profiles WHERE name=? AND id<>?', [$profile['name'],$id])) {
            throw new InvalidArgumentException('A serial profile already uses this name.');
        }
        $args = [$profile['name'],$profile['description'],$profile['manufacturer'],$profile['model'],
            json_encode($profile['settings'], JSON_THROW_ON_ERROR),$user];
        if ($id) {
            $args[] = $id;
            nms_category_execute('UPDATE plugin_nms_serial_profiles SET name=?,description=?,manufacturer=?,model=?,settings_json=?,updated_by=?,revision=revision+1,updated_at=NOW() WHERE id=?', $args);
        } else {
            nms_category_execute('INSERT INTO plugin_nms_serial_profiles (name,description,manufacturer,model,settings_json,updated_by,updated_at) VALUES (?,?,?,?,?,?,NOW())', $args);
            $id = (int)db_fetch_cell('SELECT LAST_INSERT_ID()');
        }
        // Connections keep their copied settings and profile_revision intentionally.
        nms_category_execute('COMMIT');
        return $id;
    } catch (Throwable $e) { db_execute('ROLLBACK'); throw $e; }
}

/** One management transaction lock covers endpoint ownership, membership and profile refresh. */
function nms_serial_mutation(callable $operation)
{
    nms_require_management();
    $key='nms_serial_'.substr(hash('sha256',(string)db_fetch_cell('SELECT DATABASE()')),0,32);
    if ((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,10)',[$key])!==1) throw new RuntimeException('Serial settings are busy. Retry shortly.');
    try {
        nms_category_execute('START TRANSACTION');
        try { $result=$operation(); nms_category_execute('COMMIT'); return $result; }
        catch(Throwable $e) { db_execute('ROLLBACK'); throw $e; }
    } finally { db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$key]); }
}

/** A shared bus cannot be edited through access to just one of its devices. */
function nms_serial_connection_get($id)
{
    nms_require_management();
    $id=nms_config_integer($id,1,2147483647,'Connection ID');
    $row=db_fetch_row_prepared('SELECT * FROM plugin_nms_serial_connections WHERE id=?',[$id]);
    if (!$row) throw new InvalidArgumentException('Serial connection not found.');
    foreach (db_fetch_assoc_prepared("SELECT d.host_id FROM plugin_nms_serial_devices d JOIN host h ON h.id=d.host_id WHERE d.connection_id=? AND h.deleted=''",[$id]) as $member) nms_require_device_access((int)$member['host_id']);
    $row['settings']=json_decode($row['settings_json'],true,32,JSON_THROW_ON_ERROR);
    return $row;
}

function nms_serial_connections($collector=0)
{
    nms_require_management();
    $rows=(int)$collector>0 ? db_fetch_assoc_prepared('SELECT id FROM plugin_nms_serial_connections WHERE poller_id=? ORDER BY name',[(int)$collector]) : db_fetch_assoc('SELECT id FROM plugin_nms_serial_connections ORDER BY name');
    $result=[];
    foreach($rows as $row) {
        try { $result[]=nms_serial_connection_get($row['id']); }
        catch(RuntimeException $e) { continue; } // Hide connections with inaccessible members.
    }
    return $result;
}

function nms_serial_connection_create(array $input)
{
    return nms_serial_mutation(function() use($input) {
        return nms_serial_connection_insert($input);
    });
}

/** Caller must hold the serial mutation lock and transaction. */
function nms_serial_connection_insert(array $input)
{
    $endpoint=nms_serial_endpoint($input);
    $name=nms_config_text($input['name'] ?? '',150,'Connection name');
        if (!db_fetch_cell_prepared("SELECT id FROM poller WHERE id=? AND disabled=''",[$endpoint['poller_id']])) throw new InvalidArgumentException('Select an enabled collector.');
        $profile=nms_serial_profile_get($input['profile_id'] ?? 0);
        if ((int)$profile['revision']!==nms_config_integer($input['profile_revision'] ?? '',1,2147483647,'Profile revision')) throw new RuntimeException('Profile changed. Preview it again.');
        if (db_fetch_cell_prepared('SELECT id FROM plugin_nms_serial_connections WHERE endpoint_key=?',[$endpoint['endpoint_key']])) throw new InvalidArgumentException('This endpoint already has a connection. Select the existing connection.');
        $settings_json=nms_serial_connection_settings($profile,$input);
        nms_category_execute('INSERT INTO plugin_nms_serial_connections (name,poller_id,transport,endpoint,endpoint_key,profile_id,profile_revision,settings_json,updated_by,updated_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())',
            [$name,$endpoint['poller_id'],$endpoint['transport'],$endpoint['endpoint'],$endpoint['endpoint_key'],$profile['id'],$profile['revision'],$settings_json,nms_current_user_id()]);
        return (int)db_fetch_cell('SELECT LAST_INSERT_ID()');
}

function nms_serial_assignment($host_id)
{
    nms_require_device_access($host_id);
    return db_fetch_row_prepared('SELECT d.*,c.name AS connection_name,c.poller_id,c.transport,c.endpoint,c.enabled,c.settings_json FROM plugin_nms_serial_devices d JOIN plugin_nms_serial_connections c ON c.id=d.connection_id WHERE d.host_id=?',[(int)$host_id]);
}

/** Acknowledged revision prevents a stale editor replacing a newer assignment. */
function nms_serial_assign($host_id, array $input)
{
    return nms_serial_mutation(function() use($host_id,$input) {
        return nms_serial_assign_locked($host_id,$input);
    });
}

/** Caller holds the serial transaction. */
function nms_serial_assign_locked($host_id, array $input)
{
    $host_id=nms_config_integer($host_id,1,16777215,'Device ID');
    $connection_id=nms_config_integer($input['connection_id'] ?? '',0,2147483647,'Connection ID');
    $revision=nms_config_integer($input['assignment_revision'] ?? '',0,2147483647,'Assignment revision');
        nms_require_device_access($host_id);
        $host=db_fetch_row_prepared("SELECT id,poller_id,disabled FROM host WHERE id=? AND deleted='' FOR UPDATE",[$host_id]);
        if (!$host) throw new InvalidArgumentException('Device no longer exists.');
        $old=db_fetch_row_prepared('SELECT * FROM plugin_nms_serial_devices WHERE host_id=? FOR UPDATE',[$host_id]);
        if ((int)($old['revision'] ?? 0)!==$revision) throw new RuntimeException('Device connection changed. Reload before saving.');
        if (!$connection_id) {
            nms_category_execute('DELETE FROM plugin_nms_serial_devices WHERE host_id=?',[$host_id]); return;
        }
        $connection=nms_serial_connection_get($connection_id);
        if ((int)$connection['poller_id']!==(int)$host['poller_id']) throw new InvalidArgumentException('Connection must use the device’s assigned Cacti collector.');
        if (!$connection['enabled']) throw new InvalidArgumentException('Connection is disabled.');
        $address=nms_serial_device_address($input['device_address'] ?? '');
        if (db_fetch_cell_prepared('SELECT host_id FROM plugin_nms_serial_devices WHERE connection_id=? AND device_address=? AND host_id<>?',[$connection_id,$address,$host_id])) throw new InvalidArgumentException('This address is already used on the connection.');
        nms_category_execute('INSERT INTO plugin_nms_serial_devices (host_id,connection_id,device_address,revision,updated_by,updated_at) VALUES (?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE connection_id=VALUES(connection_id),device_address=VALUES(device_address),revision=VALUES(revision),updated_by=VALUES(updated_by),updated_at=NOW()',[$host_id,$connection_id,$address,$revision+1,nms_current_user_id()]);
}

/** Preview exactly the shared settings and members that an explicit refresh affects. */
function nms_serial_refresh_preview($id)
{
    $connection=nms_serial_connection_get($id);
    $profile=nms_serial_profile_get($connection['profile_id']);
    $members=db_fetch_assoc_prepared("SELECT h.id,h.description,h.poller_id,h.site_id,d.device_address,d.revision FROM plugin_nms_serial_devices d JOIN host h ON h.id=d.host_id WHERE d.connection_id=? AND h.deleted='' ORDER BY h.id",[$id]);
    foreach($members as $member) {
        nms_require_device_access((int)$member['id']);
        if((int)$member['poller_id']!==(int)$connection['poller_id']) throw new RuntimeException('A member moved to another collector. Correct its connection before refreshing shared settings.');
    }
    $fingerprint=hash('sha256',json_encode([$connection,$profile,$members],JSON_THROW_ON_ERROR));
    return ['connection'=>$connection,'profile'=>$profile,'members'=>$members,'fingerprint'=>$fingerprint];
}

function nms_serial_refresh_apply($id, $fingerprint)
{
    if(!is_string($fingerprint) || !preg_match('/^[a-f0-9]{64}$/D',$fingerprint)) throw new InvalidArgumentException('Preview the connection before applying changes.');
    return nms_serial_mutation(function() use($id,$fingerprint) {
        $preview=nms_serial_refresh_preview($id);
        if(!hash_equals($preview['fingerprint'],$fingerprint)) throw new RuntimeException('Connection, profile or members changed. Preview again.');
        nms_category_execute('UPDATE plugin_nms_serial_connections SET settings_json=?,profile_revision=?,revision=revision+1,updated_by=?,updated_at=NOW() WHERE id=?',[$preview['profile']['settings_json'],$preview['profile']['revision'],nms_current_user_id(),$id]);
    });
}
