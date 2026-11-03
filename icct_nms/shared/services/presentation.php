<?php

/** Shared presentation data is bundled locally, independent of Internet access. */
function icct_nms_severity_colors()
{
    static $colors;
    if ($colors === null) {
        $colors = json_decode(
            file_get_contents(__DIR__ . '/../assets/severity.json'),
            true,
            32,
            JSON_THROW_ON_ERROR,
        );
    }
    return $colors;
}
