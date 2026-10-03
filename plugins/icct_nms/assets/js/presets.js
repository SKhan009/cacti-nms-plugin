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
const iconGrid = document.querySelector('#icon-picker-grid');
const iconToggle = document.querySelector('#icon-picker-toggle');
const typeSaveButton = document.querySelector('#save-device-type');
function updateTypeIcon() {
  if (!typeEditor) return;
  const option = [...iconGrid.querySelectorAll('[data-icon]')].find(button=>button.dataset.icon===typeEditor.elements.icon.value);
  iconPreview.hidden = !option;
  document.querySelector('#icon-picker-value').textContent = option?.dataset.iconLabel ?? 'Select Icon';
  if(option) iconPreview.src=option.dataset.iconAsset; else iconPreview.removeAttribute('src');
  iconGrid.querySelectorAll('[data-icon]').forEach(button=>button.setAttribute('aria-selected',String(button===option)));
  typeSaveButton.disabled = !typeEditor.elements.type_name.value.trim() || !option || !typeEditor.elements.physical_ports.validity.valid || ['network','rack','map'].some(view=>!typeEditor.elements[`display_${view}`].value);
}
function closeIconPicker() { if(iconGrid) { iconGrid.hidden=true;iconToggle.setAttribute('aria-expanded','false'); } }
function openTypeEditor(type = {}) {
  typeEditor.reset();
  const fields = {type_id:type.type_id ?? '',type_name:type.name ?? '',category_id:type.category_id ?? 0,physical_ports:type.physical_ports ?? '',icon:type.icon ?? '',shape:type.shape ?? (type.icon==='switch'?'wide':'rectangle')};
  for (const view of ['network','rack','map']) fields[`display_${view}`] = type.display_modes?.[view] ?? (type.type_id?'icon':'');
  for (const [name,value] of Object.entries(fields)) typeEditor.elements[name].value = value;
  document.querySelector('#type-editor-title').textContent = type.type_id ? 'Edit Device Type' : 'Add Device Type';
  typeSaveButton.textContent = type.type_id ? 'Save' : 'Add';
  typeEditor.hidden = false;
  const preview=document.querySelector('#type-upload-preview');
  const validImage=/^uploads\/[a-f0-9]{32}\.(png|jpg|webp)$/.test(type.image ?? '');
  preview.hidden=!validImage;
  document.querySelector('#type-remove-image').hidden=!validImage;
  if(validImage) preview.src=new URL('../images/device-types/'+type.image,document.querySelector('script[src*="presets.js"]').src).href; else preview.removeAttribute('src');
  document.querySelector('#type-upload-name').textContent='';
  closeIconPicker(); updateTypeIcon();
  typeEditor.elements.type_name.focus();
}
document.querySelector('#add-device-type')?.addEventListener('click', () => openTypeEditor());
document.querySelector('#cancel-device-type')?.addEventListener('click', () => { typeEditor.hidden = true; closeIconPicker(); });
iconToggle?.addEventListener('click',()=>{ iconGrid.hidden=!iconGrid.hidden;iconToggle.setAttribute('aria-expanded',String(!iconGrid.hidden)); if(!iconGrid.hidden) iconGrid.querySelector('[aria-selected="true"], [data-icon]')?.focus(); });
iconGrid?.querySelectorAll('[data-icon]').forEach((button,index,buttons)=>{
  button.addEventListener('click',()=>{typeEditor.elements.icon.value=button.dataset.icon;updateTypeIcon();closeIconPicker();iconToggle.focus();});
  button.addEventListener('keydown',event=>{const offsets={ArrowRight:1,ArrowLeft:-1,ArrowDown:6,ArrowUp:-6};if(event.key in offsets){event.preventDefault();buttons[(index+offsets[event.key]+buttons.length)%buttons.length].focus();}if(event.key==='Escape'){closeIconPicker();iconToggle.focus();}});
});
document.addEventListener('click',event=>{if(iconGrid&&!event.target.closest('.icon-picker'))closeIconPicker();});
typeEditor?.addEventListener('input',updateTypeIcon);
typeEditor?.elements.device_image.addEventListener('change',()=>{
  const file=typeEditor.elements.device_image.files[0];
  if(!file) return;
  typeEditor.elements.device_image.setCustomValidity(file.size>512000?'Use an image up to 500 KB.':'');
  document.querySelector('#type-upload-name').textContent=file.name;
  const preview=document.querySelector('#type-upload-preview');
  if(file.size<=512000){ preview.src=URL.createObjectURL(file);preview.hidden=false; }
});
if(typeEditor&&!typeEditor.hidden) updateTypeIcon();
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
  document.querySelector('#connection-color-label').textContent=fields.color.value || 'Select color';
  document.querySelector('.connection-color-control').classList.toggle('unselected',!fields.color.value);
  if(fields.color.value) document.querySelector('#connection-color-picker').value=fields.color.value;
  document.querySelectorAll('[data-connection-choice]').forEach(picker=>{
    const selected=picker.querySelector(`[data-connection-value="${fields[picker.dataset.connectionChoice].value}"]`);
    const label=picker.querySelector('[data-choice-label]');
    label.replaceChildren();
    if(selected) { for(const child of selected.children) label.append(child.cloneNode(true)); }
    else label.textContent=picker.dataset.connectionChoice==='line_style'?'Select line style':'Select endpoint symbol';
    picker.querySelectorAll('[data-connection-value]').forEach(option=>option.setAttribute('aria-pressed',String(option===selected)));
  });

}
function openConnectionEditor(profile={}) {
  connectionEditor.reset();
  for(const [name,value] of Object.entries({connection_id:profile.connection_id ?? '',connection_name:profile.name ?? '',color:profile.color ?? '',line_style:profile.line_style ?? '',symbol:profile.symbol ?? ''})) connectionEditor.elements[name].value=value;
  document.querySelector('#connection-editor-title').textContent=profile.connection_id?'Edit Network Connection':'Add Network Connection';
  connectionEditor.hidden=false; updateConnectionPreview(); connectionEditor.elements.connection_name.focus();
}
document.querySelector('#add-network-connections')?.addEventListener('click',()=>openConnectionEditor());
document.querySelector('#cancel-connection')?.addEventListener('click',()=>{connectionEditor.hidden=true;});
connectionEditor?.addEventListener('input',updateConnectionPreview);
document.querySelector('#connection-color-picker')?.addEventListener('input',event=>{connectionEditor.elements.color.value=event.target.value; updateConnectionPreview();});
document.querySelectorAll('[data-connection-value]').forEach(option=>option.addEventListener('click',()=>{
  const picker=option.closest('[data-connection-choice]');
  connectionEditor.elements[picker.dataset.connectionChoice].value=option.dataset.connectionValue;
  picker.open=false; picker.querySelector('summary').focus(); updateConnectionPreview();
}));
document.querySelectorAll('[data-connection-choice]').forEach(picker=>picker.addEventListener('toggle',()=>{if(picker.open) document.querySelectorAll('[data-connection-choice]').forEach(other=>{if(other!==picker) other.open=false;});}));
document.addEventListener('click',event=>document.querySelectorAll('[data-connection-choice]').forEach(picker=>{if(!picker.contains(event.target)) picker.open=false;}));
connectionEditor?.addEventListener('keydown',event=>{if(event.key==='Escape') document.querySelectorAll('[data-connection-choice][open]').forEach(picker=>{picker.open=false; picker.querySelector('summary').focus();});});
if(connectionEditor) updateConnectionPreview();
document.querySelectorAll('[data-edit-connection]').forEach(button=>button.addEventListener('click',()=>openConnectionEditor(JSON.parse(button.dataset.editConnection))));
document.querySelectorAll('[data-delete-connection]').forEach(form=>form.addEventListener('submit',async event=>{
  event.preventDefault();
  if(await icctShowMessage({title:'Delete network connection',text:`Delete “${form.dataset.connectionName}”?`,confirm:true,danger:true,accept:'Delete'})) {
    if(form.dataset.staticPreview) icctToast({title:'Preview',text:'Delete connections in the deployed plugin. Saved connections are unchanged.'});
    else form.submit();
  }
}));

const siteEditor=document.querySelector('#site-editor');
function openSiteEditor(site={}) {
  siteEditor.reset();
  siteEditor.elements.site_id.value=site.id ?? '';
  for(const field of siteEditor.querySelectorAll('input:not([type=hidden]),select,textarea')) field.value=site[field.name] ?? (field.name==='zoom'?'12':'');
  document.querySelector('#site-editor-title').textContent=site.id?'Edit Site':'Add Site';
  siteEditor.hidden=false; document.querySelector('#site-list').hidden=true;
  siteEditor.elements.name.focus();
}
document.querySelector('#add-site')?.addEventListener('click',()=>openSiteEditor());
document.querySelectorAll('[data-edit-site]').forEach(button=>button.addEventListener('click',()=>openSiteEditor(JSON.parse(button.dataset.editSite))));
document.querySelector('#cancel-site')?.addEventListener('click',()=>{siteEditor.hidden=true; document.querySelector('#site-list').hidden=false;});
if(siteEditor && !siteEditor.hidden) document.querySelector('#site-list').hidden=true;
document.querySelector('#site-search')?.addEventListener('input',event=>{
  let count=0; const search=event.target.value.toLocaleLowerCase().trim();
  document.querySelectorAll('[data-site-row]').forEach(row=>{row.hidden=!row.dataset.siteSearch.includes(search); if(!row.hidden) count++;});
  document.querySelector('#site-empty').hidden=count>0;
});

const nodeEditor=document.querySelector('#node-editor');
function openNodeEditor(node={}) {
  nodeEditor.reset();
  nodeEditor.elements.node_id.value=node.id ?? '0';
  nodeEditor.elements.node_name.value=node.name ?? '';
  nodeEditor.elements.site_id.value=node.site_id ?? '';
  nodeEditor.elements.rack_profile_id.value=node.rack_profile_id ?? '';updateNodeRackCount();
  document.querySelector('#node-editor-title').textContent=node.id?'Edit Node':'Add Node';
  nodeEditor.hidden=false;
  nodeEditor.elements.node_name.focus();
}
document.querySelector('#add-node')?.addEventListener('click',()=>openNodeEditor());
document.querySelector('#cancel-node')?.addEventListener('click',()=>{nodeEditor.hidden=true;});
document.querySelectorAll('[data-edit-node]').forEach(button=>button.addEventListener('click',()=>openNodeEditor(JSON.parse(button.dataset.editNode))));
document.querySelectorAll('[data-delete-node]').forEach(form=>form.addEventListener('submit',async event=>{
  event.preventDefault();
  if(await icctShowMessage({title:'Delete node',text:`Delete “${form.dataset.nodeName}”? Nodes containing racks cannot be deleted.`,confirm:true,danger:true,accept:'Delete'})) {
    if(form.dataset.staticPreview) icctToast({title:'Preview',text:'Delete nodes in the deployed plugin. Saved nodes are unchanged.'});
    else form.submit();
  }
}));
document.querySelector('#node-search')?.addEventListener('input',event=>{
  const query=event.target.value.trim().toLocaleLowerCase();
  let count=0;
  document.querySelectorAll('[data-node-row]').forEach(row=>{row.hidden=!row.dataset.nodeSearch.includes(query);if(!row.hidden)count++;});
  document.querySelector('#node-empty').hidden=count>0;
});

function updateNodeRackCount(){if(!nodeEditor)return;const profiles=JSON.parse(document.querySelector('#node-rack-profiles').textContent);nodeEditor.elements.rack_count_display.value=profiles[nodeEditor.elements.rack_profile_id.value]?.rack_count ?? '';}
nodeEditor?.elements.rack_profile_id.addEventListener('change',updateNodeRackCount);if(nodeEditor)updateNodeRackCount();
const rackEditor=document.querySelector('#rack-editor');
function openRackEditor(profile={}){rackEditor.reset();for(const name of ['rack_profile_id','rack_name','rack_count','unit_count'])rackEditor.elements[name].value=profile[name] ?? '';document.querySelector('#rack-editor-title').textContent=profile.rack_profile_id?'Edit Rack Configuration':'Add Rack Configuration';rackEditor.hidden=false;rackEditor.elements.rack_name.focus();}
document.querySelector('#add-rack-config')?.addEventListener('click',()=>openRackEditor());
document.querySelector('#cancel-rack-config')?.addEventListener('click',()=>{rackEditor.hidden=true;});
document.querySelectorAll('[data-edit-rack-profile]').forEach(button=>button.addEventListener('click',()=>openRackEditor(JSON.parse(button.dataset.editRackProfile))));
document.querySelector('#rack-profile-search')?.addEventListener('input',event=>{const query=event.target.value.toLowerCase().trim();document.querySelectorAll('[data-rack-profile-search]').forEach(row=>{row.hidden=!row.dataset.rackProfileSearch.includes(query);});});

document.querySelectorAll('[data-delete-rack-profile]').forEach(form=>form.addEventListener('submit',async event=>{
  event.preventDefault();
  if(await icctShowMessage({title:'Delete rack configuration',text:`Delete “${form.dataset.rackName}”? Configurations assigned to nodes cannot be deleted.`,confirm:true,danger:true,accept:'Delete'})) {
    if(form.dataset.staticPreview)icctToast({title:'Preview',text:'Delete rack configurations in the live application.'});else form.submit();
  }
}));
