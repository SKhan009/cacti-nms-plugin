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
  typeSaveButton.disabled = !typeEditor.elements.type_name.value.trim() || !option || !typeEditor.elements.physical_ports.validity.valid;
}
function closeIconPicker() { if(iconGrid) { iconGrid.hidden=true;iconToggle.setAttribute('aria-expanded','false'); } }
function openTypeEditor(type = {}) {
  typeEditor.reset();
  const fields = {type_id:type.type_id ?? '',type_name:type.name ?? '',category_id:type.category_id ?? 0,physical_ports:type.physical_ports ?? '',icon:type.icon ?? ''};
  for (const view of ['network','rack','map']) fields[`display_${view}`] = type.display_modes?.[view] ?? 'icon';
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
  const svg=document.querySelector('#connection-editor-preview svg');
  svg.parentElement.hidden=!(fields.color.value && fields.line_style.value && fields.symbol.value);
  svg.style.color=fields.color.value;
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
