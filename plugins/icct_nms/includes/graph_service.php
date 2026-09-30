<?php
/** Read graph ownership and visibility through Cacti's native authorization service. */
function icct_nms_device_graphs($id)
{
    global $config;
    require_once $config["base_path"] . "/lib/auth.php";
    icct_backend_require_device_access($id);
    $total = 0;
    return get_allowed_graphs(
        "gl.host_id=" . (int) $id,
        "gtg.title_cache",
        "",
        $total,
    );
}

/** Match the native device editor's eligible, unassociated graph templates. */
function icct_nms_available_graph_templates($id)
{
    return db_fetch_assoc_prepared(
        "SELECT DISTINCT gt.id,gt.name FROM graph_templates gt
        LEFT JOIN snmp_query_graph sqg ON sqg.graph_template_id=gt.id
        JOIN graph_templates_item gti ON gti.graph_template_id=gt.id
        JOIN data_template_rrd dtr ON gti.task_item_id=dtr.id
        JOIN data_template_data dtd ON dtd.data_template_id=dtr.data_template_id
        WHERE sqg.name IS NULL AND gti.local_graph_id=0 AND dtr.local_data_id=0
        AND gt.id NOT IN (SELECT graph_template_id FROM host_graph WHERE host_id=?) ORDER BY gt.name",
        [$id],
    );
}

function icct_nms_graph_associations($id)
{
    $allowed = icct_nms_device_graphs($id);
    $templates = db_fetch_assoc_prepared(
        "SELECT gt.id,gt.name FROM host_graph hg JOIN graph_templates gt ON gt.id=hg.graph_template_id WHERE hg.host_id=? ORDER BY gt.name",
        [$id],
    );
    $graphTemplateIds = array_column(db_fetch_assoc_prepared("SELECT id,graph_template_id FROM graph_local WHERE host_id=?", [$id]), "graph_template_id", "id");
    foreach ($templates as &$template) {
        $template["graphs"] = array_values(array_filter($allowed, function ($graph) use ($template, $graphTemplateIds) {
            return (int) ($graphTemplateIds[$graph["local_graph_id"]] ?? 0) === (int) $template["id"];
        }));
    }
    unset($template);
    foreach ($templates as &$template) {
        $template["parameters"] = icct_nms_graph_parameters((int)$template["id"], 0);
        $template["details"] = [];
        foreach ($template["graphs"] as $graph) {
            $graphId = (int)$graph["local_graph_id"];
            $template["details"][] = icct_nms_graph_parameters((int)$template["id"], $graphId);
        }
    }
    unset($template);
    return $templates;
}

/** Called only for an associated template or an ACL-filtered graph on this device. */
function icct_nms_graph_parameters($templateId, $graphId)
{
    $parameters = db_fetch_row_prepared("SELECT * FROM graph_templates_graph WHERE graph_template_id=? AND local_graph_id=?", [$templateId, $graphId]);
    $items = db_fetch_assoc_prepared("SELECT gti.*, dtr.data_source_name,dtr.rrd_minimum,dtr.rrd_maximum,dtr.rrd_heartbeat,dtr.data_source_type_id,c.hex AS color_hex, dt.name AS data_template_name, cd.name AS cdef_name, vd.name AS vdef_name, gp.name AS gprint_name FROM graph_templates_item gti LEFT JOIN data_template_rrd dtr ON dtr.id=gti.task_item_id LEFT JOIN colors c ON c.id=gti.color_id LEFT JOIN data_template dt ON dt.id=dtr.data_template_id LEFT JOIN cdef cd ON cd.id=gti.cdef_id LEFT JOIN vdef vd ON vd.id=gti.vdef_id LEFT JOIN graph_templates_gprint gp ON gp.id=gti.gprint_id WHERE gti.graph_template_id=? AND gti.local_graph_id=? ORDER BY gti.sequence", [$templateId, $graphId]);
    return ["graph_id"=>$graphId, "settings"=>$parameters, "items"=>$items];
}

/** Preserve native device-editor semantics: removing an association never deletes its graphs. */
function icct_nms_save_graph_association($id, $input)
{
    global $config;
    icct_backend_require_management(3);
    icct_backend_require_device_access($id);
    $templateId = icct_nms_id($input["graph_template_id"] ?? 0);
    require_once $config["base_path"] . "/lib/api_device.php";
    if (($input["action"] ?? "") === "remove_graph_template") {
        if (
            !db_fetch_cell_prepared(
                "SELECT COUNT(*) FROM host_graph WHERE host_id=? AND graph_template_id=?",
                [$id, $templateId],
            )
        ) {
            throw new InvalidArgumentException(
                "Graph template association no longer exists.",
            );
        }
        api_device_gt_remove($id, $templateId);
        return;
    }
    if (
        !in_array(
            $templateId,
            array_map(
                "intval",
                array_column(icct_nms_available_graph_templates($id), "id"),
            ),
            true,
        )
    ) {
        throw new InvalidArgumentException(
            "Choose an available graph template.",
        );
    }
    require_once $config["base_path"] . "/lib/api_automation.php";
    db_execute_prepared(
        "REPLACE INTO host_graph(host_id,graph_template_id) VALUES(?,?)",
        [$id, $templateId],
    );
    automation_hook_graph_template($id, $templateId);
    api_plugin_hook_function("add_graph_template_to_host", [
        "host_id" => $id,
        "graph_template_id" => $templateId,
    ]);
}
