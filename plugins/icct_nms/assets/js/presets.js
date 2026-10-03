const segmentEditor = document.querySelector('#segment-editor');
function openSegmentEditor(id = '', name = '') {
  segmentEditor.hidden = false;
  segmentEditor.elements.segment_id.value = id;
  segmentEditor.elements.segment_name.value = name;
  segmentEditor.elements.segment_name.focus();
}
document.querySelector('#add-segment')?.addEventListener('click', () => openSegmentEditor());
document.querySelector('#cancel-segment')?.addEventListener('click', () => { segmentEditor.hidden = true; });
document.querySelectorAll('[data-edit-segment]').forEach(button => button.addEventListener('click', () => openSegmentEditor(button.dataset.editSegment, button.dataset.segmentName)));
document.querySelectorAll('[data-delete-segment]').forEach(form => form.addEventListener('submit', async event => {
  event.preventDefault();
  if (await icctShowMessage({title:'Delete segment',text:`Delete “${form.dataset.segmentName}”? Segments assigned to devices or imported templates cannot be deleted.`,confirm:true,danger:true,accept:'Delete'})) {
    if (form.dataset.staticPreview) icctToast({title:'Preview',text:'Delete segments in the deployed plugin. Saved segments are unchanged.'});
    else form.submit();
  }
}));
const typeEditor = document.querySelector('#device-type-editor');
const iconPreview = document.querySelector('#device-type-icon-preview');
function updateTypeIcon() {
  if (iconPreview) iconPreview.src = new URL(`../images/device-types/${typeEditor.elements.icon.value}.svg`, document.querySelector('script[src*="presets.js"]').src).href;
}
function openTypeEditor(type = {}) {
  typeEditor.reset();
  const fields = {type_id:type.type_id ?? '',type_name:type.name ?? '',category_id:type.category_id ?? 0,physical_ports:type.physical_ports ?? 0,icon:type.icon ?? 'device'};
  for (const view of ['network','rack','map']) fields[`display_${view}`] = type.display_modes?.[view] ?? 'icon';
  for (const [name,value] of Object.entries(fields)) typeEditor.elements[name].value = value;
  document.querySelector('#type-editor-title').textContent = type.type_id ? 'Edit Device Type' : 'Add Device Type';
  typeEditor.hidden = false;
  updateTypeIcon();
  typeEditor.elements.type_name.focus();
  typeEditor.scrollIntoView({block:'start',behavior:'smooth'});
}
document.querySelector('#add-device-type')?.addEventListener('click', () => openTypeEditor());
document.querySelector('#cancel-device-type')?.addEventListener('click', () => { typeEditor.hidden = true; });
document.querySelector('#device-type-icon')?.addEventListener('change', updateTypeIcon);
document.querySelectorAll('[data-edit-type]').forEach(button => button.addEventListener('click', () => openTypeEditor(JSON.parse(button.dataset.editType))));
document.querySelectorAll('[data-delete-type]').forEach(form => form.addEventListener('submit', async event => {
  event.preventDefault();
  if (await icctShowMessage({title:'Delete device type',text:`Delete “${form.dataset.typeName}”? Reassign devices using this type before deleting it.`,confirm:true,danger:true,accept:'Delete'})) {
    if (form.dataset.staticPreview) icctToast({title:'Preview',text:'Delete device types in the deployed plugin. Saved device types are unchanged.'});
    else form.submit();
  }
}));
