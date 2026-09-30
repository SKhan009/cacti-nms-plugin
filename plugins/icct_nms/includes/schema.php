<?php
/** Lifecycle-only schema creation. Ordinary requests never create or repair tables. */
function icct_nms_schema_install()
{
    $sql = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
    $sql = preg_replace('/^--.*$/m', '', $sql);
    foreach (explode(';', $sql) as $statement) {
        if (trim($statement) !== '' && !db_execute($statement)) {
            throw new RuntimeException(
                'ICCT NMS schema installation failed. Check Cacti database logs.'
            );
        }
    }
    if (
        !db_execute_prepared(
            'INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',
            ['icct_nms_schema_version', '1.1.0']
        )
    ) {
        throw new RuntimeException('ICCT NMS schema version could not be saved.');
    }
}
