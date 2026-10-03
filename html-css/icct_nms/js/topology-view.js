(function(){
'use strict';
const source=document.querySelector('#topologyData');if(!source)return;
const data=JSON.parse(source.textContent),stage=document.querySelector('#topologyStage'),canvas=document.querySelector('#topologyCanvas'),cards=document.querySelector('#topologyDevices'),svg=document.querySelector('#topologyLinks'),panel=document.querySelector('#icct-panel-topology'),save=document.querySelector('#topologySave'),message=document.querySelector('#topologyMessage'),dialog=document.querySelector('#topologyDraftDialog');
let editing=false,busy=false,scale=1,dirty=false,pending=null,bypass=false,drag=null;
const devices=data.devices,positions={},core=devices.find(d=>Number(d.id)===Number(data.core_id));
const others=devices.filter(d=>d!==core),cols=Math.max(4,Math.ceil(Math.sqrt(others.length*1.15))),rows=Math.ceil(others.length/cols);
others.forEach((d,i)=>{const row=Math.floor(i/cols),col=i%cols;positions[d.id]=[.08+col*.84/Math.max(1,cols-1),row<Math.ceil(rows/2)? .08+row*.32/Math.max(1,Math.ceil(rows/2)-1):.60+(row-Math.ceil(rows/2))*.32/Math.max(1,rows-Math.ceil(rows/2)-1)];});
if(core)positions[core.id]=[.5,.5];
Object.entries(data.layout).forEach(([id,p])=>{if(positions[id]&&Number(id)!==Number(core?.id))positions[id]=p;});
let saved=structuredClone(positions);
const severityColors={Critical:'#ff4148',Major:'#ff7226',Minor:'#ff9b17',Warning:'#43aa91',Information:'#277f9b'};
function el(tag,cls,text){const e=document.createElement(tag);e.className=cls;if(text!==undefined)e.textContent=text;return e;}
function showDevice(d){
 const detail=document.querySelector('#topologyDeviceDialog');document.querySelector('#topologyDeviceTitle').textContent=d.name;document.querySelector('#topologyDeviceAddress').textContent=d.address;
 const actions=document.querySelector('#topologyDeviceDiagnostics');actions.replaceChildren();
 Object.entries(d.diagnostics||{}).forEach(([tool,label])=>{const a=el('a','button',label);a.href='http://127.0.0.1:8080/cacti/plugins/icct_nms/diagnostics.php?host_id='+encodeURIComponent(d.id)+'&tool='+encodeURIComponent(tool);actions.append(a);});
 if(!actions.children.length)actions.append(el('p','','No diagnostics selected.'));
 const discoveryLink=el('a','button','Device Connections');discoveryLink.href='topology-configuration.html#connection-device-'+encodeURIComponent(d.id);actions.append(discoveryLink);
 const rows=document.querySelector('#topologyDeviceDiscovery');rows.replaceChildren();
 (d.discovery||[]).forEach(o=>{const row=el('tr','');row.append(el('td','',o.label));const status=el('td','');status.append(el('span','device-status '+(o.status==='Current'?'online':o.status==='Failed'?'offline':o.status==='Disabled'?'disabled':'other'),o.status));row.append(status,el('td','',o.count===null?'—':String(o.count)),el('td','',o.evidence));rows.append(row);});
 if(!rows.children.length){const row=el('tr',''),cell=el('td','','No topology discovery methods selected.');cell.colSpan=4;row.append(cell);rows.append(row);}
 detail.showModal();
}
function render(){
 cards.replaceChildren();canvas.style.transform='scale('+scale+')';canvas.style.width='100%';canvas.style.height='100%';
 const w=canvas.clientWidth,h=canvas.clientHeight;
 // Fit dense inventories without changing the saved device coordinates.
 const sizes={square:[52,52],rectangle:[72,52],wide:[156,44],tall:[44,72]};
 const dimensions=devices.map(d=>{const shape=d.shape||(d===core?'wide':'rectangle');const size=sizes[shape]||sizes.rectangle;return d.network_asset?.includes('/uploads/')&&shape!=='wide'?[size[0],shape==='tall'?92:76]:size;});
 const maxWidth=Math.max(1,...dimensions.map(s=>s[0])),maxHeight=Math.max(1,...dimensions.map(s=>s[1]));
 const halfRows=Math.ceil(rows/2),rowGap=halfRows>1?h*.32/(halfRows-1):h*.20;
 const densityScale=devices.length>20?Math.min(1,(w*.84/Math.max(1,cols-1)-8)/maxWidth,(rowGap-8)/maxHeight):1;
 canvas.style.setProperty('--topology-node-scale',String(Math.max(.15,densityScale)));
 devices.forEach(d=>{const card=el('div','topology-device '+window.icctStatusClass(d.status)+' shape-'+(['square','rectangle','wide','tall'].includes(d.shape)?d.shape:(d===core?'wide':'rectangle')));card.dataset.deviceId=d.id;card.classList.toggle('fixed-core',d===core);card.tabIndex=0;card.setAttribute('role','button');card.setAttribute('aria-label',d.name+' · '+d.status);card.title=d.name+' · '+d.status;card.style.left=(positions[d.id][0]*100)+'%';card.style.top=(positions[d.id][1]*100)+'%';card.classList.toggle('has-device-image',!!d.network_asset?.includes('/uploads/'));const heading=el('div','topology-device-heading');heading.append(el('strong','',d.short_name||d.name));if(d.network_asset){const icon=el('img','topology-device-icon');icon.src=d.network_asset;icon.alt='';icon.draggable=false;icon.addEventListener('error',()=>{icon.hidden=true;card.classList.remove('has-device-image');});heading.append(icon);}card.append(heading);const badge=el('span','topology-alarm',String(d.fault_count||0));badge.style.background=d.alarm?severityColors[d.alarm.severity]||'#aaa':'white';badge.title=d.alarm?d.alarm.severity+': '+d.alarm.message:'No active fault';card.append(badge);
 card.addEventListener('click',()=>{if(!editing&&!busy)showDevice(d);});
 card.addEventListener('pointerdown',e=>{if(!editing||busy||d===core||e.button!==0)return;e.preventDefault();drag={id:d.id,card};card.setPointerCapture(e.pointerId);card.classList.add('dragging');});
 card.addEventListener('pointermove',e=>{if(!drag||drag.id!==d.id)return;const rect=canvas.getBoundingClientRect();positions[d.id]=[Math.max(.05,Math.min(.95,(e.clientX-rect.left)/rect.width)),Math.max(.07,Math.min(.93,(e.clientY-rect.top)/rect.height))];card.style.left=positions[d.id][0]*100+'%';card.style.top=positions[d.id][1]*100+'%';lines();});
 function finish(){if(!drag)return;drag=null;card.classList.remove('dragging');dirty=JSON.stringify(positions)!==JSON.stringify(saved);controls();}
 card.addEventListener('pointerup',finish);card.addEventListener('pointercancel',finish);
 card.addEventListener('keydown',e=>{if(!editing&&['Enter',' '].includes(e.key)){e.preventDefault();showDevice(d);return;}if(!editing||busy||d===core||!['ArrowLeft','ArrowRight','ArrowUp','ArrowDown'].includes(e.key))return;e.preventDefault();const p=positions[d.id];p[0]=Math.max(.05,Math.min(.95,p[0]+(e.key==='ArrowLeft'?-.01:e.key==='ArrowRight'?.01:0)));p[1]=Math.max(.07,Math.min(.93,p[1]+(e.key==='ArrowUp'?-.01:e.key==='ArrowDown'?.01:0)));dirty=true;render();cards.querySelector('[data-device-id="'+d.id+'"]').focus();});cards.append(card);});
 svg.setAttribute('viewBox','0 0 '+w+' '+h);lines();controls();
}
function lines(){svg.replaceChildren();const w=canvas.clientWidth,h=canvas.clientHeight;data.links.forEach(link=>{const a=positions[link.source],b=positions[link.target];if(!a||!b)return;const path=document.createElementNS('http://www.w3.org/2000/svg','path');const x=a[0]*w,y=a[1]*h,X=b[0]*w,Y=b[1]*h,mid=(y+Y)/2;path.setAttribute('d',`M${x} ${y} V${mid} H${X} V${Y}`);const title=document.createElementNS('http://www.w3.org/2000/svg','title');title.textContent=link.label||link.protocol.toUpperCase();if(link.color)path.style.stroke=link.color;path.append(title);svg.append(path);});}
function controls(){save.disabled=!dirty||busy;document.querySelector('#topologyDiscard').disabled=busy;document.querySelector('.topology-actions').hidden=!editing;document.querySelector('#topologyEdit').hidden=!data.management;document.querySelector('#topologyEdit').setAttribute('aria-pressed',String(editing));cards.classList.toggle('editing',editing);}
function discard(){if(busy)return;Object.assign(positions,structuredClone(saved));dirty=false;editing=false;drag=null;message.textContent='';render();}
async function persist(){if(busy)return false;if(!dirty)return true;busy=true;controls();const body=new FormData(document.querySelector('#topologyToken'));body.set('topology_positions',JSON.stringify(positions));body.set('revision',data.revision);try{const response=await fetch('topology.php',{method:'POST',body,credentials:'same-origin'}),result=await response.json();if(!response.ok||!result.ok)throw Error(result.error||'Unable to save layout.');data.revision=result.revision;saved=structuredClone(positions);dirty=false;message.textContent='';return true;}catch(e){message.textContent=e.message;return false;}finally{busy=false;controls();}}
function leave(action){if(dirty){pending=action;dialog.showModal();}else action();}
dialog.querySelectorAll('[data-topology-choice]').forEach(b=>b.addEventListener('click',async()=>{const choice=b.dataset.topologyChoice;if(choice==='save'&&!await persist())return;if(choice==='discard')discard();dialog.close();if(choice!=='cancel'&&pending){const action=pending;pending=null;bypass=true;action();bypass=false;}}));dialog.addEventListener('cancel',()=>{pending=null;});
save.addEventListener('click',async()=>{if(await persist()){editing=false;drag=null;render();document.querySelector('#topologyEdit').focus();}});document.querySelector('#topologyDiscard').addEventListener('click',discard);
document.querySelector('#topologyEdit').addEventListener('click',()=>{if(busy)return;const nextEditing=!editing;leave(()=>{editing=nextEditing;render();});});
[['topologyZoomIn',.15],['topologyZoomOut',-.15]].forEach(([id,delta])=>document.getElementById(id).addEventListener('click',()=>{scale=Math.max(.6,Math.min(2,scale+delta));render();}));document.querySelector('#topologyFit').addEventListener('click',()=>{scale=1;render();});document.querySelector('#topologyFullscreen').addEventListener('click',()=>{if(document.fullscreenElement)document.exitFullscreen();else document.querySelector('.icct-map-panel').requestFullscreen().catch(()=>{message.textContent='Fullscreen is unavailable.';});});
document.addEventListener('icct:before-view-change',e=>{if(!panel.hidden&&dirty&&!bypass){e.preventDefault();leave(()=>e.detail.tab.click());}});
document.addEventListener('click',e=>{const link=e.target.closest('a[href]');if(link&&!panel.hidden&&dirty&&!bypass){e.preventDefault();leave(()=>{window.location.href=link.href;});}},true);
window.addEventListener('beforeunload',e=>{if(dirty){e.preventDefault();e.returnValue='';}});new ResizeObserver(()=>{if(!panel.hidden)render();}).observe(stage);
const totals={Critical:0,Major:0,Minor:0,Warning:0,Information:0};devices.forEach(d=>{Object.entries(d.fault_counts||{}).forEach(([severity,count])=>{if(severity in totals)totals[severity]+=count;});});const alarms=document.querySelector('#topologyAlarms');alarms.append(el('span','','Total: '+Object.values(totals).reduce((a,b)=>a+b,0)));Object.entries(totals).forEach(([name,count])=>{const item=el('span','',name+': '+count);const dot=el('i','');dot.style.background=severityColors[name];item.prepend(dot);alarms.append(item);});render();
})();
