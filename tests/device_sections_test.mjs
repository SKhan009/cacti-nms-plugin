// Source contract for the save-first association workflow; no database writes.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const read = file => readFileSync(new URL('../' + file, import.meta.url), 'utf8');
const add = read('templates/devices/add.php');
const edit = read('templates/devices/edit.php');
const controller = read('devices.php');
const guard = add.indexOf('<?php if (!$device_form_is_edit) { ?>');
assert(guard > 0, 'Only unsaved devices should get the save-first panels');
for (const id of ['graph-templates', 'data-queries']) {
 assert(add.slice(guard).includes(`id="${id}"`), `Add device must expose ${id}`);
 assert(edit.includes(`href="#${id}"`), `Edit device needs a shortcut for ${id}`);
 assert(edit.includes(`id="${id}"`), `Keep the working ${id} section`);
}
assert(add.includes('id="nms-device-form"'), 'Save-first links must target the form');
assert(controller.includes("header('Location: devices.php?tab=edit&id=' . $device_id . '&device_created=' . $device_id . '#graph-templates');"), 'New devices must open their own association controls');
assert(edit.includes('value="add_graph_template"') && edit.includes('value="add_data_query"'), 'Keep association actions');
assert(edit.includes("$edit_device['serial_number'] !== ''") && !edit.includes("$edit_device['serial_number'] ?:"), 'The valid serial string 0 must not become a pending message');
assert(edit.includes("$edit_device['serial_status'] === 'unconfigured'") && edit.includes('no current value'), 'Unavailable inventory states must be visible, not a permanent pending label');
assert(!controller.includes('nms_template_upgrade_readable_names();'), 'A device view must not rename native templates');
assert(!read('nms.php').includes('nms_sync_all_faults('), 'A dashboard view must not perform global incident writes');
assert(!read('fault_config.php').includes('nms_sync_device_faults();'), 'Policy save must use the poller-owned evaluation cycle');
console.log('PASS: add/edit section visibility, shortcuts and post-create redirect contract.');
