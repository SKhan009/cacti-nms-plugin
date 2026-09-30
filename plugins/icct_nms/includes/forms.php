<?php
/**
 * Escaped form controls built from saved values and installed Cacti field metadata.
 */

/**
 * Render an escaped input; type, name and extra attributes are trusted template arguments.
 */
function icct_nms_input($label, $name, $value = '', $type = 'text', $extra = '')
{
    print '<label class="field"><span class="field-label">' .
        icct_nms_h($label) .
        '</span><input type="' .
        $type .
        '" name="' .
        $name .
        '" value="' .
        icct_nms_h($value) .
        '" ' .
        $extra .
        '></label>';
}

/**
 * Render choices and retain an unavailable saved selection for explicit user review.
 */
function icct_nms_select($label, $name, $choices, $value, $extra = '')
{
    print '<label class="field"><span class="field-label">' .
        icct_nms_h($label) .
        '</span><span class="select-wrap"><select name="' .
        $name .
        '" ' .
        $extra .
        '>';
    if ((string) $value !== '' && !array_key_exists((string) $value, $choices)) {
        $choices[$value] = 'Saved value unavailable: ' . $value;
    }
    foreach ($choices as $key => $text) {
        print '<option value="' .
            icct_nms_h($key) .
            '"' .
            ((string) $key === (string) $value ? ' selected' : '') .
            '>' .
            icct_nms_h($text) .
            '</option>';
    }
    print '</select></span></label>';
}

/**
 * Use the installed Cacti field definition as the authoritative list of choices.
 */
function icct_nms_core_select($label, $name, $value)
{
    global $fields_host_edit;
    if (!isset($fields_host_edit[$name])) {
        throw new RuntimeException('Cacti form field is unavailable: ' . $name);
    }
    icct_nms_select($label, $name, icct_backend_core_field_choices($fields_host_edit[$name]), $value);
}

/**
 * Read native defaults when creating a device instead of copying another host.
 */
function icct_nms_defaults()
{
    global $fields_host_edit;
    $values = ['location' => '']; // Cacti's optional free-text location has no form default.
    foreach ($fields_host_edit as $name => $field) {
        if (array_key_exists('default', $field)) {
            $values[$name] = $field['default'];
        }
    }
    return $values;
}
