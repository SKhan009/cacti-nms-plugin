<?php
/** Identify stock templates from Cacti's own installation packages, never by name or ID. */
function icct_nms_stock_graph_templates()
{
    global $config;
    static $templates;
    if ($templates !== null) return $templates;
    $templates = [];
    foreach (glob($config['base_path'] . '/install/templates/*.xml*') ?: [] as $path) {
        $xml = @file_get_contents($path);
        if ($xml === false) continue;
        if (substr($path, -3) === '.gz') $xml = @gzdecode($xml);
        $package = icct_nms_graph_xml($xml ?: '');
        if (!$package) continue;
        $documents = [$package];
        foreach ($package->files->file ?? [] as $file) {
            $contents = base64_decode((string)$file->data, true);
            if ($contents && strpos($contents, '<cacti>') !== false) {
                $document = icct_nms_graph_xml($contents);
                if ($document) $documents[] = $document;
            }
        }
        foreach ($documents as $document) {
            foreach ($document->children() as $template) {
                if (preg_match('/^hash_00[0-9a-f]{36}$/i', $template->getName())) {
                    $hash = substr($template->getName(), -32);
                    $templates[$hash][] = icct_nms_graph_xml_fields($template);
                }
            }
        }
    }
    return $templates;
}

function icct_nms_graph_xml($xml)
{
    $previous = libxml_use_internal_errors(true);
    try {
        return simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
}

/** Ignore XML whitespace and export-version prefixes while preserving template identities. */
function icct_nms_graph_xml_fields($element, $path = '')
{
    $fields = [];
    foreach ($element->children() as $child) {
        $name = preg_replace('/^hash_[0-9a-f]{6}([0-9a-f]{32})$/i', 'hash_$1', $child->getName());
        $key = $path . '/' . $name;
        if ($child->count()) {
            $fields += icct_nms_graph_xml_fields($child, $key);
        } else {
            $fields[$key] = preg_replace('/hash_[0-9a-f]{6}([0-9a-f]{32})/i', 'hash_$1', (string)$child);
        }
    }
    ksort($fields);
    return $fields;
}

/** Compare original fields only: newer Cacti releases may add fields to their exporter. */
function icct_nms_graph_matches_stock($current, $original)
{
    foreach ($original as $field => $value) {
        if (!array_key_exists($field, $current) || $current[$field] !== $value) return false;
    }
    foreach (['/items/', '/inputs/'] as $branch) {
        $identities = function ($fields) use ($branch) {
            $ids = [];
            foreach (array_keys($fields) as $key) {
                if (strpos($key, $branch) === 0) $ids[] = explode('/', $key)[2];
            }
            return array_values(array_unique($ids));
        };
        if ($identities($current) !== $identities($original)) return false;
    }
    return true;
}

function icct_nms_graph_origin($templateId)
{
    global $config, $struct_graph_item;
    static $origins = [];
    if (isset($origins[$templateId])) return $origins[$templateId];
    $stock = icct_nms_stock_graph_templates();
    $hash = db_fetch_cell_prepared('SELECT hash FROM graph_templates WHERE id=?', [$templateId]);
    if (!$stock) return ['label'=>'Origin Unavailable', 'edited'=>false];
    if (!isset($stock[$hash])) return $origins[$templateId] = ['label'=>'User Defined', 'edited'=>false];
    require_once $config['base_path'] . '/include/global_form.php';
    require_once $config['base_path'] . '/lib/export.php';
    // Cacti's exporter removes this global field; preserve it for the rest of the page.
    $savedItems = $struct_graph_item;
    try {
        $xml = icct_nms_graph_xml(graph_template_to_xml($templateId) ?: '');
    } finally {
        $struct_graph_item = $savedItems;
    }
    $current = $xml ? icct_nms_graph_xml_fields($xml) : [];
    $edited = true;
    foreach ($stock[$hash] as $original) {
        if (icct_nms_graph_matches_stock($current, $original)) { $edited = false; break; }
    }
    return $origins[$templateId] = ['label'=>'System Defined', 'edited'=>$edited];
}
