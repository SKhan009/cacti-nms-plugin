<?php
/** Load editable wizard panels without creating or updating a device. */
    $discovery = icct_nms_discovery_assignment($id);
    $diag = db_fetch_row_prepared(
        "SELECT p.* FROM plugin_icct_nms_diagnostic_devices d JOIN plugin_icct_nms_diagnostic_profiles p ON p.id=d.profile_id WHERE d.host_id=?",
        [$id],
    );
    $ssh = db_fetch_row_prepared(
        "SELECT p.*,d.monitoring FROM plugin_icct_nms_ssh_devices d JOIN plugin_icct_nms_ssh_presets p ON p.id=d.preset_id WHERE d.host_id=?",
        [$id],
    );
    $serial = $id ? icct_backend_serial_assignment($id) : [];
    $connections = icct_backend_serial_connections((int) $host["poller_id"]);
    $graphAssociations = $id ? icct_nms_graph_associations($id) : [];
    $availableGraphTemplates = icct_nms_available_graph_templates($id);
    $dataQueryAssociations = $id ? icct_nms_data_query_associations($id) : [];
    $availableDataQueries = $id ? icct_nms_available_data_queries($id, $host) : db_fetch_assoc('SELECT id,name FROM snmp_query ORDER BY name');
    $dataQueryMethods = icct_nms_data_query_methods($host);
    $defaultDataQueryMethod = (int) read_config_option("reindex_method");
    if (!isset($dataQueryMethods[$defaultDataQueryMethod])) $defaultDataQueryMethod = 0;
