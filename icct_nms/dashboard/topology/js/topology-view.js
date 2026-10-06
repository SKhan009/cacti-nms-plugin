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
function showDevice(d, refreshed=false){
 const detail=document.querySelector('#topologyDeviceDialog');document.querySelector('#topologyDeviceTitle').textContent=d.name;document.querySelector('#topologyDeviceAddress').textContent=d.address;
 const state=document.querySelector('#topologyDeviceStatus');state.className='device-status '+window.icctStatusClass(d.status);state.textContent=d.status==='Up'?'Online':d.status==='Down'?'Offline':d.status;
 const image=document.querySelector('#topologyDeviceImage');image.hidden=!d.network_asset;if(d.network_asset)image.src=d.network_asset;else image.removeAttribute('src');image.alt=d.name;
 const summary=document.querySelector('#topologyDeviceSummary');summary.replaceChildren();Object.entries(d.summary||{}).forEach(([label,value])=>{const row=el('div','');row.append(el('dt','',label),el('dd','',value===null||value===''?'—':String(value)));summary.append(row);});
 const capacity=document.querySelector('#topologyDeviceCapacity');capacity.replaceChildren();['Switching Capacity (Tbps)','Forwarding Rate (Bpps)','Hardware Redundancy (W)','Table Scale (TCAM / FIB)'].forEach(label=>{const row=el('div','');row.append(el('dt','',label),el('dd','',d.capacity?.[label]===null||d.capacity?.[label]===undefined?'—':Number(d.capacity[label]).toLocaleString(undefined,{maximumFractionDigits:3})));capacity.append(row);});
 const alarms=document.querySelector('#topologyDeviceAlarms');alarms.replaceChildren();Object.entries(severityColors).forEach(([severity,color])=>{const badge=el('span',''),dot=el('i','');dot.style.background=color;badge.append(dot,document.createTextNode((severity==='Warning'?'Warn':severity==='Information'?'Info':severity)+': '+(d.fault_counts?.[severity]||0)));alarms.append(badge);});
 const links=document.querySelector('#topologyDeviceLinks');links.replaceChildren();for(const [label,tab] of [['Device Details','details'],['Graphs','graphs'],['Active Alarms','fcaps']]){const a=el('a','',label+' →');a.href='inventory/controllers/device.php?id='+encodeURIComponent(d.id)+'&view=1#view-'+tab;links.append(a);}
 if(!detail.open) detail.showModal();
 if(!refreshed && !document.documentElement.dataset.staticPreview && source.dataset.summaryUrl){
  detail.dataset.deviceId=String(d.id);detail.dataset.summaryState='loading';
  fetch(source.dataset.summaryUrl+'?device_summary='+encodeURIComponent(d.id),{credentials:'same-origin',cache:'no-store'}).then(response=>{if(!response.ok)throw new Error('Summary unavailable');return response.json();}).then(fresh=>{if(detail.open && detail.dataset.deviceId===String(d.id)){showDevice({...d,...fresh},true);detail.dataset.summaryState='current';}}).catch(()=>{if(detail.dataset.deviceId===String(d.id))detail.dataset.summaryState='unavailable';});
 }
}
function render(){
 cards.replaceChildren();canvas.style.transform='scale('+scale+')';canvas.style.width='100%';canvas.style.height='100%';
 const w=canvas.clientWidth,h=canvas.clientHeight;
 // Fit dense inventories without changing the saved device coordinates.
 const sizes={square:[52,52],rectangle:[72,52],wide:[156,44],tall:[44,72]};
 const dimensions=devices.map(d=>{const shape=d.shape||(d===core?'wide':'rectangle');const size=sizes[shape]||sizes.rectangle;return d.network_asset?[size[0],shape==='tall'?92:76]:size;});
 const maxWidth=Math.max(1,...dimensions.map(s=>s[0])),maxHeight=Math.max(1,...dimensions.map(s=>s[1]));
 const halfRows=Math.ceil(rows/2),rowGap=halfRows>1?h*.32/(halfRows-1):h*.20;
 const densityScale=devices.length>20?Math.min(1,(w*.84/Math.max(1,cols-1)-8)/maxWidth,(rowGap-8)/maxHeight):1;
 canvas.style.setProperty('--topology-device-scale',String(Math.max(.15,densityScale)));
 devices.forEach(d=>{const card=el('div','topology-device '+window.icctStatusClass(d.status)+' shape-'+(['square','rectangle','wide','tall'].includes(d.shape)?d.shape:(d===core?'wide':'rectangle')));card.dataset.deviceId=d.id;card.classList.toggle('fixed-core',d===core);card.tabIndex=0;card.setAttribute('role','button');card.setAttribute('aria-label',d.name+' · '+d.status);card.title=d.name+' · '+d.status;card.style.left=(positions[d.id][0]*100)+'%';card.style.top=(positions[d.id][1]*100)+'%';card.classList.toggle('has-device-image',!!d.network_asset?.includes('/uploads/'));card.classList.toggle('has-device-asset',!!d.network_asset);const heading=el('div','topology-device-heading');heading.append(el('strong','',d.short_name||d.name));card.append(heading);if(d.network_asset){const icon=el('img','topology-device-icon');icon.src=d.network_asset;icon.alt='';icon.draggable=false;icon.addEventListener('error',()=>{icon.hidden=true;card.classList.remove('has-device-image','has-device-asset');});card.append(icon);}const badge=el('span','topology-alarm',String(d.fault_count||0));badge.style.background=d.alarm?severityColors[d.alarm.severity]||'#aaa':'white';badge.title=d.alarm?d.alarm.severity+': '+d.alarm.message:'No active fault';card.append(badge);
 card.addEventListener('click',()=>{if(!editing&&!busy)showDevice(d);});
 card.addEventListener('pointerdown',e=>{if(!editing||busy||d===core||e.button!==0)return;e.preventDefault();drag={id:d.id,card};card.setPointerCapture(e.pointerId);card.classList.add('dragging');});
 card.addEventListener('pointermove',e=>{if(!drag||drag.id!==d.id)return;const rect=canvas.getBoundingClientRect();positions[d.id]=[Math.max(.05,Math.min(.95,(e.clientX-rect.left)/rect.width)),Math.max(.07,Math.min(.93,(e.clientY-rect.top)/rect.height))];card.style.left=positions[d.id][0]*100+'%';card.style.top=positions[d.id][1]*100+'%';lines();});
 function finish(){if(!drag)return;drag=null;card.classList.remove('dragging');dirty=JSON.stringify(positions)!==JSON.stringify(saved);controls();}
 card.addEventListener('pointerup',finish);card.addEventListener('pointercancel',finish);
 card.addEventListener('keydown',e=>{if(!editing&&['Enter',' '].includes(e.key)){e.preventDefault();showDevice(d);return;}if(!editing||busy||d===core||!['ArrowLeft','ArrowRight','ArrowUp','ArrowDown'].includes(e.key))return;e.preventDefault();const p=positions[d.id];p[0]=Math.max(.05,Math.min(.95,p[0]+(e.key==='ArrowLeft'?-.01:e.key==='ArrowRight'?.01:0)));p[1]=Math.max(.07,Math.min(.93,p[1]+(e.key==='ArrowUp'?-.01:e.key==='ArrowDown'?.01:0)));dirty=true;render();cards.querySelector('[data-device-id="'+d.id+'"]').focus();});cards.append(card);});
 svg.setAttribute('viewBox','0 0 '+w+' '+h);lines();controls();
}
const linkTip=el('div','topology-link-tooltip');linkTip.id='topologyLinkTooltip';linkTip.setAttribute('role','tooltip');linkTip.hidden=true;panel.append(linkTip);
let activeLink=null,linkRequest=0,linkTimer=null;
function hideLink(){clearTimeout(linkTimer);linkRequest++;activeLink?.classList.remove('active-link');activeLink=null;linkTip.hidden=true;}
function bandwidth(value){if(value===null||value===undefined)return 'Unavailable';const units=['bps','Kbps','Mbps','Gbps','Tbps'];let n=Number(value),i=0;while(n>=1000&&i<4){n/=1000;i++;}return n.toLocaleString(undefined,{maximumFractionDigits:2})+' '+units[i];}
function mtrReadings(section,reports){
 if(!reports.length){section.append(el('small','mtr-empty','MTR: no reading'));return;}
 const report=[...reports].sort((a,b)=>(b.collected||'').localeCompare(a.collected||''))[0],final=report.hops.at(-1);
 section.append(el('b','mtr-heading',report.method),el('small','mtr-path',report.collector+' → '+report.target));
 const ms=v=>v===null||v===undefined?'Unavailable':Number(v).toLocaleString(undefined,{maximumFractionDigits:2})+' ms';
 const values={'Latency (avg)':ms(final?.avg_ms),'Packet loss':final?final.loss_percent+'%':'Unavailable','Measured':report.collected};
 if(final&&final.address!==report.target)values['Final hop']=final.address==='???'?'No reply':final.address;
 const rows=el('dl','');Object.entries(values).forEach(([k,v])=>rows.append(el('dt','',k),el('dd','',String(v))));section.append(rows);
 if(report.timed_out||report.status!=='complete')section.append(el('p','mtr-issue',report.timed_out?'MTR timed out':'MTR failed'));
 else if(final?.loss_percent>0)section.append(el('p','mtr-issue','Hop '+final.hop+' · '+final.address+' reports '+final.loss_percent+'% reply loss'));
}

function showLink(link,anchor,event){
 if(editing)return;clearTimeout(linkTimer);const request=++linkRequest;activeLink?.classList.remove('active-link');activeLink=anchor;anchor.classList.add('active-link');linkTip.replaceChildren();linkTip.append(el('strong','',link.label||'Network link'));
 const rect=anchor.getBoundingClientRect();const left=event?.clientX??(rect.left+rect.width/2),top=event?.clientY??rect.top;
 linkTip.hidden=false;linkTip.style.left=Math.max(8,Math.min(left+14,window.innerWidth-350))+'px';linkTip.style.top=Math.max(8,Math.min(top+14,window.innerHeight-300))+'px';
 const loading=el('p','','Loading link and MTR readings…');linkTip.append(loading);
 function refresh(){fetch('dashboard/controllers/topology.php?link_source='+encodeURIComponent(link.source)+'&link_target='+encodeURIComponent(link.target),{credentials:'same-origin',cache:'no-store'}).then(r=>{if(!r.ok)throw Error();return r.json();}).then(fresh=>{
  if(activeLink!==anchor||request!==linkRequest)return;const scrollTop=linkTip.scrollTop;linkTip.replaceChildren(el('strong','',link.label||'Network link'));
  (fresh.readings||[]).forEach((reading,i)=>{const device=devices.find(d=>Number(d.id)===Number(i?link.target:link.source));const section=el('section','');section.append(el('b','',(device?.name||'Device')+(reading.port==='Not specified'?'':' · '+reading.port)));
   if(!reading.available)section.append(el('p','','Bandwidth: unavailable'));
   else {const rows=el('dl','');const values={'Link status':reading.status,'Capacity':bandwidth(reading.capacity_bps),'Inbound':bandwidth(reading.in_bps),'Outbound':bandwidth(reading.out_bps),'Updated':reading.collected};
    if(reading.capacity_bps>0){for(const dir of ['in','out'])if(reading[dir+'_bps']!==null)values[dir==='in'?'Inbound utilization':'Outbound utilization']=(reading[dir+'_bps']/reading.capacity_bps*100).toFixed(1)+'%';}
    Object.entries(values).forEach(([name,value])=>{rows.append(el('dt','',name),el('dd','',value));});section.append(rows);}
   mtrReadings(section,fresh.mtr?.[i]||[]);linkTip.append(section);
  });
  linkTip.scrollTop=scrollTop;
  linkTip.style.top=Math.max(8,Math.min(top+14,window.innerHeight-linkTip.offsetHeight-8))+'px';
 }).catch(()=>{if(activeLink===anchor&&request===linkRequest){linkTip.replaceChildren(el('p','','Readings unavailable'));}}).finally(()=>{if(activeLink===anchor&&request===linkRequest)linkTimer=setTimeout(refresh,10000);});}refresh();
}
function lines(){hideLink();svg.replaceChildren();const w=canvas.clientWidth,h=canvas.clientHeight;data.links.forEach(link=>{const a=positions[link.source],b=positions[link.target];if(!a||!b)return;const path=document.createElementNS('http://www.w3.org/2000/svg','path');const x=a[0]*w,y=a[1]*h,X=b[0]*w,Y=b[1]*h,mid=(y+Y)/2;path.setAttribute('d',`M${x} ${y} V${mid} H${X} V${Y}`);if(link.color)path.style.stroke=link.color;svg.append(path);
 const hit=path.cloneNode();hit.classList.add('topology-link-hit');hit.style.stroke='transparent';hit.setAttribute('tabindex','0');hit.setAttribute('aria-label','Link bandwidth and MTR: '+link.label);hit.setAttribute('aria-describedby',linkTip.id);
 hit.addEventListener('pointerenter',e=>{if(!activeLink)showLink(link,hit,e);});hit.addEventListener('focus',()=>{if(!activeLink)showLink(link,hit);});hit.addEventListener('keydown',e=>{if(['Enter',' '].includes(e.key)){e.preventDefault();showLink(link,hit);}});hit.addEventListener('click',e=>showLink(link,hit,e));svg.append(hit);
});}
document.addEventListener('keydown',e=>{if(e.key==='Escape')hideLink();});document.addEventListener('click',e=>{if(!linkTip.contains(e.target)&&!e.target.closest('.topology-link-hit'))hideLink();});document.addEventListener('icct:before-view-change',hideLink);
function controls(){save.disabled=!dirty||busy;document.querySelector('#topologyDiscard').disabled=busy;document.querySelector('.topology-actions').hidden=!editing;document.querySelector('#topologyEdit').hidden=!data.management;document.querySelector('#topologyEdit').setAttribute('aria-pressed',String(editing));cards.classList.toggle('editing',editing);}
function discard(){if(busy)return;Object.assign(positions,structuredClone(saved));dirty=false;editing=false;drag=null;message.textContent='';render();}
async function persist(){if(busy)return false;if(!dirty)return true;busy=true;controls();const body=new FormData(document.querySelector('#topologyToken'));body.set('topology_positions',JSON.stringify(positions));body.set('revision',data.revision);try{const response=await fetch('dashboard/controllers/topology.php',{method:'POST',body,credentials:'same-origin'}),result=await response.json();if(!response.ok||!result.ok)throw Error(result.error||'Unable to save layout.');data.revision=result.revision;saved=structuredClone(positions);dirty=false;message.textContent='';return true;}catch(e){message.textContent=e.message;return false;}finally{busy=false;controls();}}
function leave(action){if(dirty){pending=action;dialog.showModal();}else action();}
dialog.querySelectorAll('[data-topology-choice]').forEach(b=>b.addEventListener('click',async()=>{const choice=b.dataset.topologyChoice;if(choice==='save'&&!await persist())return;if(choice==='discard')discard();dialog.close();if(choice!=='cancel'&&pending){const action=pending;pending=null;bypass=true;action();bypass=false;}}));dialog.addEventListener('cancel',()=>{pending=null;});
save.addEventListener('click',async()=>{if(await persist()){editing=false;drag=null;render();document.querySelector('#topologyEdit').focus();}});document.querySelector('#topologyDiscard').addEventListener('click',discard);
document.querySelector('#topologyEdit').addEventListener('click',()=>{if(busy)return;const nextEditing=!editing;leave(()=>{editing=nextEditing;render();});});
[['topologyZoomIn',.15],['topologyZoomOut',-.15]].forEach(([id,delta])=>document.getElementById(id).addEventListener('click',()=>{scale=Math.max(.6,Math.min(2,scale+delta));render();}));document.querySelector('#topologyFit').addEventListener('click',()=>{scale=1;render();});document.querySelector('#topologyFullscreen').addEventListener('click',()=>{if(document.fullscreenElement)document.exitFullscreen();else document.querySelector('.icct-map-panel').requestFullscreen().catch(()=>{message.textContent='Fullscreen is unavailable.';});});
document.addEventListener('icct:before-view-change',e=>{if(!panel.hidden&&dirty&&!bypass){e.preventDefault();leave(()=>e.detail.tab.click());}});
document.addEventListener('click',e=>{const link=e.target.closest('a[href]');if(link&&!panel.hidden&&dirty&&!bypass){e.preventDefault();leave(()=>{window.location.href=link.href;});}},true);
window.addEventListener('beforeunload',e=>{if(dirty){e.preventDefault();e.returnValue='';}});new ResizeObserver(()=>{if(!panel.hidden)render();}).observe(stage);
function alarmTotals(){const totals={Critical:0,Major:0,Minor:0,Warning:0,Information:0};devices.forEach(d=>{Object.entries(d.fault_counts||{}).forEach(([severity,count])=>{if(severity in totals)totals[severity]+=count;});});const alarms=document.querySelector('#topologyAlarms');alarms.replaceChildren();alarms.append(el('span','','Total: '+Object.values(totals).reduce((a,b)=>a+b,0)));Object.entries(totals).forEach(([name,count])=>{const item=el('span','',name+': '+count);const dot=el('i','');dot.style.background=severityColors[name];item.prepend(dot);alarms.append(item);});}
document.addEventListener('icct:alarm-readings',e=>{
 const updates=new Map(e.detail.map(d=>[Number(d.id),d]));
 devices.forEach(d=>{const update=updates.get(Number(d.id));if(!update)return;d.fault_counts=update.counts;d.fault_count=Object.values(update.counts).reduce((a,b)=>a+b,0);d.alarm=update.alarm;
 const badge=cards.querySelector('[data-device-id="'+d.id+'"] .topology-alarm');if(badge){badge.textContent=String(d.fault_count);badge.style.background=d.alarm?severityColors[d.alarm.severity]||'#aaa':'white';badge.title=d.alarm?d.alarm.severity+': '+d.alarm.message:'No active fault';}
 });alarmTotals();
});
alarmTotals();render();
})();
