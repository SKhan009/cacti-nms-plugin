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
const connectionEditor = document.querySelector('#connection-editor');
function updateConnectionPreview() {
  if (!connectionEditor) return;
  const fields=connectionEditor.elements;
  const svg=document.querySelector('#connection-editor-preview svg');
  svg.style.color=fields.color.value;
  const styles={'solid':'','dashed':'9 5','dotted':'2 5','dash-dot':'10 4 2 4','fine-dotted':'1 3','short-dashed':'4 4'};
  svg.replaceChildren();
  const node=(tag,attributes)=>{ const el=document.createElementNS('http://www.w3.org/2000/svg',tag); for(const [key,value] of Object.entries(attributes)) el.setAttribute(key,value); svg.append(el); };
  node('path',{d:'M8 8h104',fill:'none',stroke:'currentColor','stroke-width':'1.5','stroke-dasharray':styles[fields.line_style.value]});
  if(fields.symbol.value==='circle') for(const x of [8,112]) node('circle',{cx:x,cy:8,r:4,fill:'currentColor'});
  if(fields.symbol.value==='square') node('path',{d:'M4 4h8v8H4zM108 4h8v8h-8z',fill:'currentColor'});
  if(fields.symbol.value==='arrow') node('path',{d:'m12 5-4 3 4 3M108 5l4 3-4 3',fill:'none',stroke:'currentColor','stroke-width':'1.5'});
}
function openConnectionEditor(profile={}) {
  connectionEditor.reset();
  for(const [name,value] of Object.entries({connection_id:profile.connection_id ?? '',connection_name:profile.name ?? '',color:profile.color ?? '#00bfae',line_style:profile.line_style ?? 'dotted',symbol:profile.symbol ?? 'circle'})) connectionEditor.elements[name].value=value;
  document.querySelector('#connection-editor-title').textContent=profile.connection_id?'Edit Network Connection':'Add Network Connection';
  connectionEditor.hidden=false; updateConnectionPreview(); connectionEditor.elements.connection_name.focus();
}
document.querySelector('#add-network-connections')?.addEventListener('click',()=>openConnectionEditor());
document.querySelector('#cancel-connection')?.addEventListener('click',()=>{connectionEditor.hidden=true;});
connectionEditor?.addEventListener('input',updateConnectionPreview);
document.querySelectorAll('[data-edit-connection]').forEach(button=>button.addEventListener('click',()=>openConnectionEditor(JSON.parse(button.dataset.editConnection))));
document.querySelectorAll('[data-delete-connection]').forEach(form=>form.addEventListener('submit',async event=>{
  event.preventDefault();
  if(await icctShowMessage({title:'Delete network connection',text:`Delete “${form.dataset.connectionName}”?`,confirm:true,danger:true,accept:'Delete'})) {
    if(form.dataset.staticPreview) icctToast({title:'Preview',text:'Delete connections in the deployed plugin. Saved connections are unchanged.'});
    else form.submit();
  }
}));
