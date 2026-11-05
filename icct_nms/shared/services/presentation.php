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

/** Escape the saved label and retain its full name for colour-independent reading. */
function icct_nms_severity_badge($severity)
{
    $label = htmlspecialchars((string) $severity, ENT_QUOTES, 'UTF-8');
    return '<span class="severity-badge device-status" data-severity="' .
        $label .
        '" data-log-status="' .
        $label .
        '">' .
        $label .
        '</span>';
}
