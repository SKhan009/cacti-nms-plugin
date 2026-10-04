(function(){
'use strict';
const dataElement=document.querySelector('#dashboardData');if(!dataElement)return;
const data=JSON.parse(dataElement.textContent);let preferences=data.preferences,readings=data.readings,mode='severity',saving=false,saveAgain=false;
const names={topology:'Topology / Rack / Image / Map',birds:'Birds Eye View',alarms:'Alarm Overview',ack:'Ack Overview',escalation:'Escalation Overview',frequent:'Top 10 Frequent Alarms by Count',recent:'Recent Alarms (25)',ports:'Device Interface (Ports) Overview',problematic:'Problematic Devices by Active Alarms Count'},colors={Critical:'#ff4148',Major:'#ff7226',Minor:'#ff9b17',Warning:'#43aa91',Information:'#277f9b'},palette=['#737eff','#6bce95','#ffb14e','#03cfeb','#a585ff','#2499ff','#ff8585','#34bbcc'];
const select=document.querySelector('#dashboardSelect'),picker=document.querySelector('.dashboard-widget-picker'),status=document.querySelector('#dashboardSaveStatus'),side=document.querySelector('#dashboardWidgets'),extras=document.querySelector('#dashboardExtraWidgets'),grid=document.querySelector('.dashboard-grid');
function element(tag,text){const e=document.createElement(tag);if(text!==undefined)e.textContent=text;return e;}
function svg(tag,attributes,text){const e=document.createElementNS('http://www.w3.org/2000/svg',tag);Object.entries(attributes).forEach(([k,v])=>e.setAttribute(k,v));if(text!==undefined)e.textContent=text;return e;}
function widgets(){return preferences.dashboards[preferences.selected].widgets;}
async function save(){if(saving){saveAgain=true;return;}saving=true;status.textContent='Saving…';do{saveAgain=false;const body=new FormData(document.querySelector('#dashboardToken'));body.set('dashboard_preferences',JSON.stringify(preferences));try{const r=await fetch('topology.php',{method:'POST',body,credentials:'same-origin'});if(!r.ok)throw Error();status.textContent='Layout saved';}catch(e){status.textContent='Layout could not be saved. Click to retry.';}}while(saveAgain);saving=false;}
status.addEventListener('click',save);
// Pack cards into short grid rows so tall cards do not stretch their neighbours.
let packFrame=0;
function packCards(){
 cancelAnimationFrame(packFrame);packFrame=requestAnimationFrame(()=>{
  if(extras.hidden)return;
  const columns=extras.clientWidth>=1100?3:extras.clientWidth>=600?2:1;
  extras.style.gridTemplateColumns='repeat('+columns+', minmax(0, 1fr))';
  extras.querySelectorAll('.dashboard-card').forEach(card=>{
   card.style.gridColumn='span 1';
   if(!card.hidden&&!card.classList.contains('widget-expanded'))card.style.gridRowEnd='span '+Math.ceil(card.getBoundingClientRect().height+12);
  });
 });
}
const packingObserver=new ResizeObserver(packCards);
packingObserver.observe(extras);
document.querySelectorAll('.dashboard-card').forEach(card=>packingObserver.observe(card));
window.addEventListener('resize',packCards);
function layout(){
 select.replaceChildren();preferences.dashboards.forEach((d,i)=>{const option=element('option','Dashboard #'+(i+1));option.value=i;select.append(option);});select.value=preferences.selected;document.querySelector('#dashboardAdd').disabled=preferences.dashboards.length>=5;
 document.querySelectorAll('[data-widget]').forEach(card=>card.hidden=!widgets().includes(card.dataset.widget));
 const cards=widgets().filter(key=>key!=='topology');cards.forEach(key=>extras.append(document.querySelector('[data-widget="'+key+'"]')));
 side.hidden=true;extras.hidden=cards.length===0;grid.classList.toggle('dashboard-no-side',side.hidden);
 let empty=document.querySelector('#dashboardEmpty');if(!empty){empty=element('p','This dashboard is empty. Use Add Widget to add a card.');empty.id='dashboardEmpty';grid.append(empty);}empty.hidden=widgets().length!==0;
 window.dispatchEvent(new Event('resize'));
}
select.addEventListener('change',()=>{preferences.selected=Number(select.value);layout();save();});
document.querySelector('#dashboardAdd').addEventListener('click',()=>{if(preferences.dashboards.length>=5)return;preferences.dashboards.push({widgets:['topology']});preferences.selected=preferences.dashboards.length-1;layout();save();});
const addWidget=document.querySelector('#dashboardAddWidget'),choices=document.querySelector('#dashboardWidgetChoices');
function closePicker(){choices.hidden=true;addWidget.setAttribute('aria-expanded','false');}
addWidget.addEventListener('click',()=>{
 if(!choices.hidden){closePicker();return;}
 choices.replaceChildren();Object.entries(names).filter(([key])=>key!=='topology').forEach(([key,name])=>{
  const button=element('button',name+(widgets().includes(key)?' — Added':''));button.type='button';button.disabled=widgets().includes(key);
  button.addEventListener('click',()=>{widgets().push(key);layout();save();closePicker();addWidget.focus();});choices.append(button);
 });choices.hidden=false;addWidget.setAttribute('aria-expanded','true');
});
document.addEventListener('click',e=>{if(!picker.contains(e.target))closePicker();});
picker.addEventListener('keydown',e=>{if(e.key==='Escape'){closePicker();addWidget.focus();}});
document.querySelectorAll('[data-remove-widget]').forEach(button=>button.addEventListener('click',()=>{preferences.dashboards[preferences.selected].widgets=widgets().filter(w=>w!==button.dataset.removeWidget);if(button.dataset.removeWidget==='recent')collapseRecent();if(button.dataset.removeWidget==='ports')collapsePorts();if(button.dataset.removeWidget==='problematic')collapseProblematic();layout();save();}));
function move(key,target,after){
 const list=widgets(),old=list.indexOf(key),to=list.indexOf(target);
 if(key==='topology'||target==='topology'||old<0||to<0||old===to)return;
 list.splice(old,1);list.splice(after===undefined?to:list.indexOf(target)+(after?1:0),0,key);layout();save();
}
// Pointer dragging supports both card headers and the gaps between cards.
let cardDrag=null,dragFrame=0;
function dropPosition(x,y){
 const bounds=grid.getBoundingClientRect();if(x<bounds.left||x>bounds.right||y<bounds.top||y>bounds.bottom)return null;
 const sideBounds=side.getBoundingClientRect(),zone=!side.hidden&&x>=sideBounds.left&&x<=sideBounds.right&&y>=sideBounds.top&&y<=sideBounds.bottom?side:extras;
 const zoneBounds=zone.getBoundingClientRect();if(zone.hidden||x<zoneBounds.left||x>zoneBounds.right||y<zoneBounds.top||y>zoneBounds.bottom)return null;
 const cards=Array.from(zone.querySelectorAll('.dashboard-card:not([hidden])')).filter(card=>card.dataset.widget!==cardDrag.key);
 let closest=null,distance=Infinity;
 for(const card of cards){const r=card.getBoundingClientRect(),dx=Math.max(r.left-x,0,x-r.right),dy=Math.max(r.top-y,0,y-r.bottom),score=dx*dx+dy*dy;if(score<distance){distance=score;closest=card;}}
 if(!closest)return null;
 const r=closest.getBoundingClientRect(),after=zone===side?y>r.top+r.height/2:y>=r.bottom||(y>=r.top&&x>r.left+r.width/2);
 return {card:closest,after};
}
function paintDrop(){
 document.querySelectorAll('.drag-target,.drag-before,.drag-after').forEach(card=>card.classList.remove('drag-target','drag-before','drag-after'));
 cardDrag.destination=dropPosition(cardDrag.x,cardDrag.y);
 if(cardDrag.destination){const {card,after}=cardDrag.destination;card.classList.add('drag-target',after?'drag-after':'drag-before');}
 cardDrag.preview.style.left=(cardDrag.x+12)+'px';cardDrag.preview.style.top=(cardDrag.y+12)+'px';
}
function dragScroll(){
 if(!cardDrag?.active)return;
 const delta=cardDrag.y>window.innerHeight-55?18:cardDrag.y<70?-18:0;
 if(delta){window.scrollBy(0,delta);paintDrop();}dragFrame=requestAnimationFrame(dragScroll);
}
function endCardDrag(commit){
 if(!cardDrag)return;const state=cardDrag;cardDrag=null;cancelAnimationFrame(dragFrame);state.preview?.remove();state.card.classList.remove('card-dragging');document.body.classList.remove('dashboard-dragging');
 document.querySelectorAll('.drag-target,.drag-before,.drag-after').forEach(card=>card.classList.remove('drag-target','drag-before','drag-after'));
 if(commit&&state.destination)move(state.key,state.destination.card.dataset.widget,state.destination.after);
}
document.querySelectorAll('.dashboard-card header').forEach(header=>{
 const card=header.closest('[data-widget]'),grip=header.querySelector('.widget-grip'),key=card.dataset.widget;grip.draggable=false;
 header.addEventListener('pointerdown',e=>{
  if(e.button!==0||card.classList.contains('widget-expanded')||e.target.closest('button:not(.widget-grip),a,input,select'))return;
  e.preventDefault();grip.focus();cardDrag={key,card,id:e.pointerId,startX:e.clientX,startY:e.clientY,x:e.clientX,y:e.clientY,active:false};header.setPointerCapture(e.pointerId);
 });
 grip.addEventListener('keydown',e=>{if(!['ArrowUp','ArrowDown'].includes(e.key))return;e.preventDefault();const list=widgets().filter(w=>w!=='topology'),destination=list[list.indexOf(key)+(e.key==='ArrowUp'?-1:1)];if(destination){move(key,destination);grip.focus();}});
});
document.addEventListener('pointermove',e=>{
 if(!cardDrag||e.pointerId!==cardDrag.id)return;cardDrag.x=e.clientX;cardDrag.y=e.clientY;
 if(!cardDrag.active){if(Math.hypot(e.clientX-cardDrag.startX,e.clientY-cardDrag.startY)<5)return;cardDrag.active=true;cardDrag.card.classList.add('card-dragging');document.body.classList.add('dashboard-dragging');cardDrag.preview=element('div',names[cardDrag.key]);cardDrag.preview.className='widget-drag-preview';document.body.append(cardDrag.preview);dragFrame=requestAnimationFrame(dragScroll);}
 e.preventDefault();paintDrop();
},{passive:false});
document.addEventListener('pointerup',e=>{if(cardDrag&&e.pointerId===cardDrag.id)endCardDrag(true);});
document.addEventListener('pointercancel',()=>endCardDrag(false));
document.addEventListener('keydown',e=>{if(e.key==='Escape')endCardDrag(false);});
window.addEventListener('blur',()=>endCardDrag(false));
function radar(){
 const radar=document.querySelector('#birdsRadar'),origin=readings.center||{name:'Cacti server',status:'Unknown',coordinates:null},nodes=readings.nodes.filter(node=>Number(node.id)!==Number(origin.site_id));radar.replaceChildren();
 const cx=160,cy=160,r=115,geographic=Array.isArray(origin.coordinates);
 radar.append(svg('title',{},geographic?'Node bearings from the Cacti server':'Logical node arrangement — Cacti server location is not configured'));
 for(let i=1;i<=9;i++)radar.append(svg('circle',{cx,cy,r:r*i/9,fill:'none',stroke:i%3===0?'#447cf4':'#c8d6ff','stroke-width':i%3===0?1.5:.7}));
 for(let degrees=0;degrees<360;degrees+=10){const angle=degrees*Math.PI/180,x=cx+Math.sin(angle)*(r+15),y=cy-Math.cos(angle)*(r+15);radar.append(svg('line',{x1:cx,y1:cy,x2:cx+Math.sin(angle)*r,y2:cy-Math.cos(angle)*r,stroke:degrees%30===0?'#447cf4':'#c8d6ff','stroke-width':degrees%30===0?1.4:.6}));if(degrees%30===0)radar.append(svg('text',{x,y:y+3,fill:'#95b0ff','text-anchor':'middle',transform:`rotate(${degrees},${x},${y})`},degrees));}
 const points=nodes.map((node,index)=>{
  if(!geographic)return {node,distance:null,angle:(-60+index*360/Math.max(1,nodes.length))*Math.PI/180};
  const [a,b]=origin.coordinates.map(v=>v*Math.PI/180),[c,d]=node.coordinates.map(v=>v*Math.PI/180),delta=d-b,hav=Math.sin((c-a)/2)**2+Math.cos(a)*Math.cos(c)*Math.sin(delta/2)**2;
  return {node,distance:6371*2*Math.atan2(Math.sqrt(Math.min(1,hav)),Math.sqrt(Math.max(0,1-hav))),angle:Math.atan2(Math.sin(delta)*Math.cos(c),Math.cos(a)*Math.sin(c)-Math.sin(a)*Math.cos(c)*Math.cos(delta))};
 });
 const max=Math.max(1,...points.map(p=>p.distance||0));
 function dot(node,x,y,isCenter,distance){
  const group=svg('g',isCenter?{}:{tabindex:0,role:'button','aria-label':node.name+' · '+node.status});
  group.append(svg('title',{},node.name+' · '+node.status+(distance===null?' · Logical position':distance===undefined?'': ' · '+distance.toFixed(1)+' km from Cacti server')));
  group.append(svg('circle',{cx:x,cy:y,r:10,fill:'transparent',stroke:'none','pointer-events':'all'}));
  if(isCenter)group.append(svg('circle',{cx:x,cy:y,r:13,fill:'white',stroke:'#447cf4','stroke-width':1.5}));
  group.append(svg('circle',{cx:x,cy:y,r:5,fill:node.status==='Online'?'#2dc658':node.status==='Offline'?'#ff4148':node.status==='Disabled'?'#999':'#ffb14e',stroke:'white','stroke-width':.6}));
  const right=!isCenter&&x>=cx,label=node.name.length>20?node.name.slice(0,19)+'…':node.name;
  group.append(svg('text',{x:x+(right?8:-8),y:y+3,'text-anchor':right?'start':'end','font-weight':700,fill:'#252525'},label));
  if(!isCenter){function open(){document.dispatchEvent(new CustomEvent('icct:show-node',{detail:node.id}));}group.addEventListener('click',open);group.addEventListener('keydown',e=>{if(['Enter',' '].includes(e.key)){e.preventDefault();open();}});}
  radar.append(group);
 }
 points.forEach(({node,distance,angle})=>{const radius=geographic?distance/max*r*.72:r*.58;dot(node,cx+Math.sin(angle)*radius,cy-Math.cos(angle)*radius,false,distance);});
 dot(origin,cx,cy,true);
}
function alarms(){const chart=document.querySelector('#alarmDonut'),legend=document.querySelector('#alarmLegend');chart.replaceChildren();legend.replaceChildren();const values=Object.entries(readings[mode]),total=values.reduce((s,[k,v])=>s+v,0),radius=59,circumference=2*Math.PI*radius;chart.append(svg('circle',{cx:90,cy:90,r:radius,fill:'none',stroke:'#eceef2','stroke-width':29}));let offset=0;values.forEach(([name,count],i)=>{const color=mode==='severity'?colors[name]:palette[i%palette.length];if(count>0){const length=count/total*circumference;chart.append(svg('circle',{cx:90,cy:90,r:radius,fill:'none',stroke:color,'stroke-width':29,'stroke-dasharray':length+' '+(circumference-length),'stroke-dashoffset':-offset,transform:'rotate(-90 90 90)'}));offset+=length;}const item=element('li'),dot=element('i');dot.style.background=color;item.append(dot,element('span',name),element('b',String(count)));item.title=name+': '+count;legend.append(item);});chart.append(svg('text',{x:90,y:98,'text-anchor':'middle','font-size':26,fill:'#222'},total));chart.setAttribute('aria-label',total+' active alarms grouped by '+(mode==='severity'?'severity':'segment'));document.querySelector('#alarmNote').textContent=total?'Current device fault observations':'No active alarms.';}
function frequentAlarms(){
 const body=document.querySelector('#frequentAlarms');body.replaceChildren();
 for(const alarm of readings.frequent||[]){const row=element('tr'),name=element('td',alarm.name),severity=element('td'),badge=element('span',({Warning:'Warn',Information:'Info'})[alarm.severity]||alarm.severity),count=element('td',String(alarm.count));badge.className='frequent-severity';badge.style.background=colors[alarm.severity]||'#ddd';severity.append(badge);row.append(name,severity,count);body.append(row);}
 if(!body.children.length){const row=element('tr'),cell=element('td','No active alarms.');cell.colSpan=3;row.append(cell);body.append(row);}
}
function problematicDevices(){
 const selected=Array.from(document.querySelectorAll('[data-problematic-severity]:checked')).map(input=>input.dataset.problematicSeverity),source=readings.problematic||{devices:[],total_devices:0};
 const devices=source.devices.map(device=>({...device,count:selected.reduce((sum,key)=>sum+Number(device.counts[key]||0),0)})).filter(device=>device.count>0).sort((a,b)=>b.count-a.count||a.name.localeCompare(b.name));
 document.querySelector('#problematicCount').textContent=devices.length;document.querySelector('#problematicDeviceTotal').textContent='/'+source.total_devices;
 const body=document.querySelector('#problematicDevices');body.replaceChildren();
 for(const device of devices){const row=element('tr'),name=element('td'),link=element('a',device.name);link.href='device.php?id='+device.id+'&view=1#view-fcaps';name.append(link);row.append(name,element('td',String(device.count)));body.append(row);}
 if(!devices.length){const row=element('tr'),cell=element('td',selected.length?'No devices with active alarms at the selected severities.':'Select a severity to show affected devices.');cell.colSpan=2;row.append(cell);body.append(row);}
}
document.querySelectorAll('[data-problematic-severity]').forEach(input=>input.addEventListener('change',problematicDevices));
const problematicCard=document.querySelector('[data-widget=problematic]'),problematicExpand=document.querySelector('#problematicExpand');
function collapseProblematic(){problematicCard.classList.remove('widget-expanded');problematicExpand.setAttribute('aria-pressed','false');problematicExpand.setAttribute('aria-label','Expand Problematic Devices');}
problematicExpand.addEventListener('click',()=>{const expanded=problematicCard.classList.toggle('widget-expanded');problematicExpand.setAttribute('aria-pressed',String(expanded));problematicExpand.setAttribute('aria-label',expanded?'Collapse Problematic Devices':'Expand Problematic Devices');});
document.addEventListener('keydown',e=>{if(e.key==='Escape')collapseProblematic();});
function recentAlarms(){
 const root=document.querySelector('#recentAlarms');root.replaceChildren();
 for(const alarm of readings.recent||[]){
  const detail=element('details'),summary=element('summary'),dot=element('i'),heading=element('span'),name=element('strong',alarm.name),when=alarm.time?new Date(alarm.time*1000).toLocaleString('en-IN',{timeZone:'Asia/Kolkata'}):'Time unavailable',meta=element('small',when+' · '+alarm.device);dot.style.background=colors[alarm.severity]||'#999';dot.title=alarm.severity;heading.append(name,meta);summary.append(dot,heading);detail.append(summary);
  const body=element('div');body.className='recent-detail';body.append(element('p',alarm.graph+' · Reading: '+(alarm.value===null?'Unavailable':alarm.value)+' · '+alarm.severity));if(alarm.corrective_action)body.append(element('p','Fix: '+alarm.corrective_action));const links=element('nav'),device=element('a','Device Details →');device.href='device.php?id='+alarm.device_id+'&view=1#view-fcaps';links.append(device);if(alarm.graph_id){const graph=element('a','Graph →');graph.href='device.php?id='+alarm.device_id+'&view=1#view-graphs';links.append(graph);}body.append(links);detail.append(body);root.append(detail);
 }
 if(!root.children.length)root.append(element('p','No active alarms.'));
}
const recentCard=document.querySelector('[data-widget=recent]'),expandRecent=document.querySelector('#recentExpand');
function collapseRecent(){recentCard.classList.remove('widget-expanded');expandRecent.setAttribute('aria-pressed','false');expandRecent.setAttribute('aria-label','Expand Recent Alarms');}
expandRecent.addEventListener('click',()=>{const expanded=recentCard.classList.toggle('widget-expanded');expandRecent.setAttribute('aria-pressed',String(expanded));expandRecent.setAttribute('aria-label',expanded?'Collapse Recent Alarms':'Expand Recent Alarms');});
document.addEventListener('keydown',e=>{if(e.key==='Escape')collapseRecent();});
const portTip=document.querySelector('#dashboardPortTooltip');let tipTimer=null;
function hidePortTip(){portTip.hidden=true;clearTimeout(tipTimer);document.querySelectorAll('.port-dot[aria-describedby]').forEach(e=>e.removeAttribute('aria-describedby'));}
function portsOverview(){
 hidePortTip();const root=document.querySelector('#portsOverview');root.replaceChildren();
 const states={1:'Up',2:'Down',3:'Testing',4:'Unknown',5:'Dormant',6:'Not present',7:'Lower layer down'};
 for(const device of readings.ports||[]){const row=element('div'),name=element('a',device.name),dots=element('div');row.className='ports-device-row';name.href='device.php?id='+device.id+'&view=1#view-ports';dots.className='port-dots';
  for(const port of device.ports.items){const fresh=device.ports.fresh,dot=element('button');dot.type='button';dot.className='port-dot';dot.style.background=!fresh?'#aaa':port.admin===2?'#ff4148':port.oper===1?'#43ae69':port.oper===2?'#155cff':'#ff9b17';dot.setAttribute('aria-label',device.name+' · '+port.name+' · '+port.status);
   function show(){clearTimeout(tipTimer);portTip.replaceChildren();const speed=(port.high_speed_mbps||0)*1000000||(port.speed_bps||0),capacity=speed>=1e9?(speed/1e9).toLocaleString()+' Gbps':speed>=1e6?(speed/1e6).toLocaleString()+' Mbps':speed>0?speed+' bps':'Speed unavailable';const list=element('dl');for(const [label,value] of [['Interface Name',device.name+' · '+port.name+' ('+(fresh?capacity:'Speed unavailable')+')'],['Admin Status',fresh?(states[port.admin]||'Unknown'):'Unknown'],['Operational Status',fresh?(states[port.oper]||'Unknown'):'Unknown'],['Availability',port.status],['Updated',device.ports.collected?new Date(device.ports.collected*1000).toLocaleString('en-IN',{timeZone:'Asia/Kolkata'}):'Unavailable']]){const dt=element('dt',label),dd=element('dd',value);if(label.includes('Status'))dd.style.color=value==='Up'?'#26974d':value==='Down'?'#e6353e':'#777';if(label==='Availability'&&port.status==='Available (link down)')dd.style.color='#155cff';list.append(dt,dd);}portTip.append(list);portTip.hidden=false;dot.setAttribute('aria-describedby','dashboardPortTooltip');const box=dot.getBoundingClientRect(),tip=portTip.getBoundingClientRect();portTip.style.left=Math.max(8,Math.min(box.left,innerWidth-tip.width-8))+'px';portTip.style.top=(box.bottom+8+tip.height<innerHeight?box.bottom+8:Math.max(8,box.top-tip.height-8))+'px';}
   dot.addEventListener('pointerenter',show);dot.addEventListener('focus',show);dot.addEventListener('click',show);dot.addEventListener('pointerleave',()=>{tipTimer=setTimeout(hidePortTip,180);});dot.addEventListener('blur',hidePortTip);dots.append(dot);
  }row.append(name,dots);root.append(row);
 }
 if(!root.children.length)root.append(element('p','No interfaces reported for the current device configurations.'));
}
portTip.addEventListener('pointerenter',()=>clearTimeout(tipTimer));portTip.addEventListener('pointerleave',hidePortTip);document.addEventListener('keydown',e=>{if(e.key==='Escape')hidePortTip();});document.addEventListener('click',e=>{if(!e.target.closest('.port-dot')&&!portTip.contains(e.target))hidePortTip();});grid.addEventListener('scroll',hidePortTip);
const portsCard=document.querySelector('[data-widget=ports]'),portsExpand=document.querySelector('#portsExpand');portsExpand.addEventListener('click',()=>{hidePortTip();const expanded=portsCard.classList.toggle('widget-expanded');portsExpand.setAttribute('aria-pressed',String(expanded));portsExpand.setAttribute('aria-label',expanded?'Collapse Ports Overview':'Expand Ports Overview');});function collapsePorts(){hidePortTip();portsCard.classList.remove('widget-expanded');portsExpand.setAttribute('aria-pressed','false');portsExpand.setAttribute('aria-label','Expand Ports Overview');}document.addEventListener('keydown',e=>{if(e.key==='Escape')collapsePorts();});
function workflowCharts(){
 for(const key of ['ack','escalation']){const chart=document.querySelector('#'+key+'Donut'),legend=document.querySelector('#'+key+'Legend'),values=Object.entries(readings[key]||{}),total=readings.total;chart.replaceChildren();legend.replaceChildren();
  const radius=59,circumference=2*Math.PI*radius;chart.append(svg('circle',{cx:90,cy:90,r:radius,fill:'none',stroke:total>0?'#eceef2':'#b5cdff','stroke-width':29}));let offset=0;
  values.forEach(([name,count],i)=>{const color=i===0?'#155cff':'#b5cdff';if(count>0&&total>0){const length=count/total*circumference;chart.append(svg('circle',{cx:90,cy:90,r:radius,fill:'none',stroke:color,'stroke-width':29,'stroke-dasharray':length+' '+(circumference-length),'stroke-dashoffset':-offset,transform:'rotate(-90 90 90)'}));offset+=length;}
   const item=element('li'),dot=element('i');dot.style.background=color;item.append(dot,element('span',name==='Not_Ack'?'Not Ack':name),element('b',count===null?'Unknown':String(count)));legend.append(item);
  });chart.append(svg('text',{x:90,y:98,'text-anchor':'middle','font-size':26,fill:'#222'},total));chart.setAttribute('aria-label',total+' active alarms; '+values.map(([name,count])=>(name==='Not_Ack'?'Not Ack':name)+': '+(count===null?'Unknown':count)).join(', '));
  document.querySelector('#'+key+'Note').textContent=total>0?(key==='ack'?'Acknowledgement':'Escalation')+' status has not been recorded.':'No active alarms.';
 }
}
document.querySelectorAll('[data-alarm-mode]').forEach(button=>button.addEventListener('click',()=>{mode=button.dataset.alarmMode;document.querySelectorAll('[data-alarm-mode]').forEach(b=>b.setAttribute('aria-pressed',String(b===button)));alarms();}));
document.querySelector('#birdsRefresh').addEventListener('click',async function(){this.disabled=true;try{const response=await fetch('topology.php?dashboard_readings=1',{credentials:'same-origin',cache:'no-store'});if(!response.ok)throw Error();readings=await response.json();radar();alarms();workflowCharts();frequentAlarms();recentAlarms();portsOverview();problematicDevices();status.textContent='Widgets updated '+new Date().toLocaleTimeString();}catch(e){status.textContent='Widget refresh failed. Try again.';}finally{this.disabled=false;}});
layout();radar();alarms();workflowCharts();frequentAlarms();recentAlarms();portsOverview();problematicDevices();
})();
