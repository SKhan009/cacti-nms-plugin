(function(){
'use strict';
const dataElement=document.querySelector('#dashboardData');if(!dataElement)return;
const data=JSON.parse(dataElement.textContent);let preferences=data.preferences,readings=data.readings,mode='severity',saving=false,saveAgain=false,dragWidget=null;
const names={topology:'Topology / Rack / Image / Map',birds:'Birds Eye View',alarms:'Alarm Overview',ack:'Ack Overview',escalation:'Escalation Overview',frequent:'Top 10 Frequent Alarms by Count'},colors={Critical:'#ff4148',Major:'#ff7226',Minor:'#ff9b17',Warning:'#43aa91',Information:'#277f9b'},palette=['#737eff','#6bce95','#ffb14e','#03cfeb','#a585ff','#2499ff','#ff8585','#34bbcc'];
const select=document.querySelector('#dashboardSelect'),picker=document.querySelector('.dashboard-widget-picker'),status=document.querySelector('#dashboardSaveStatus'),side=document.querySelector('#dashboardWidgets'),extras=document.querySelector('#dashboardExtraWidgets'),grid=document.querySelector('.dashboard-grid');
function element(tag,text){const e=document.createElement(tag);if(text!==undefined)e.textContent=text;return e;}
function svg(tag,attributes,text){const e=document.createElementNS('http://www.w3.org/2000/svg',tag);Object.entries(attributes).forEach(([k,v])=>e.setAttribute(k,v));if(text!==undefined)e.textContent=text;return e;}
function widgets(){return preferences.dashboards[preferences.selected].widgets;}
async function save(){if(saving){saveAgain=true;return;}saving=true;status.textContent='Saving…';do{saveAgain=false;const body=new FormData(document.querySelector('#dashboardToken'));body.set('dashboard_preferences',JSON.stringify(preferences));try{const r=await fetch('topology.php',{method:'POST',body,credentials:'same-origin'});if(!r.ok)throw Error();status.textContent='Layout saved';}catch(e){status.textContent='Layout could not be saved. Click to retry.';}}while(saveAgain);saving=false;}
status.addEventListener('click',save);
function layout(){
 select.replaceChildren();preferences.dashboards.forEach((d,i)=>{const option=element('option','Dashboard #'+(i+1));option.value=i;select.append(option);});select.value=preferences.selected;document.querySelector('#dashboardAdd').disabled=preferences.dashboards.length>=5;
 document.querySelectorAll('[data-widget]').forEach(card=>card.hidden=!widgets().includes(card.dataset.widget));
 widgets().filter(key=>key!=='topology').forEach(key=>(['ack','escalation','frequent'].includes(key)?extras:side).append(document.querySelector('[data-widget="'+key+'"]')));
 side.hidden=!widgets().some(key=>['birds','alarms'].includes(key));extras.hidden=!widgets().some(key=>['ack','escalation','frequent'].includes(key));grid.classList.toggle('dashboard-no-side',side.hidden);grid.classList.toggle('dashboard-no-topology',!widgets().includes('topology'));
 let empty=document.querySelector('#dashboardEmpty');if(!empty){empty=element('p','This dashboard is empty. Use Add Widget to add a card.');empty.id='dashboardEmpty';grid.append(empty);}empty.hidden=widgets().length!==0;
 window.dispatchEvent(new Event('resize'));
}
select.addEventListener('change',()=>{preferences.selected=Number(select.value);layout();save();});
document.querySelector('#dashboardAdd').addEventListener('click',()=>{if(preferences.dashboards.length>=5)return;preferences.dashboards.push({widgets:[]});preferences.selected=preferences.dashboards.length-1;layout();save();});
const addWidget=document.querySelector('#dashboardAddWidget'),choices=document.querySelector('#dashboardWidgetChoices');
function closePicker(){choices.hidden=true;addWidget.setAttribute('aria-expanded','false');}
addWidget.addEventListener('click',()=>{
 if(!choices.hidden){closePicker();return;}
 choices.replaceChildren();Object.entries(names).forEach(([key,name])=>{
  const button=element('button',name+(widgets().includes(key)?' — Added':''));button.type='button';button.disabled=widgets().includes(key);
  button.addEventListener('click',()=>{widgets().push(key);layout();save();closePicker();addWidget.focus();});choices.append(button);
 });choices.hidden=false;addWidget.setAttribute('aria-expanded','true');
});
document.addEventListener('click',e=>{if(!picker.contains(e.target))closePicker();});
picker.addEventListener('keydown',e=>{if(e.key==='Escape'){closePicker();addWidget.focus();}});
document.querySelectorAll('[data-remove-widget]').forEach(button=>button.addEventListener('click',()=>{preferences.dashboards[preferences.selected].widgets=widgets().filter(w=>w!==button.dataset.removeWidget);layout();save();}));
function move(key,target){const list=widgets(),old=list.indexOf(key),to=list.indexOf(target);if(old<0||to<0||old===to)return;list.splice(old,1);list.splice(to,0,key);layout();save();}
document.querySelectorAll('.widget-grip').forEach(grip=>{const key=grip.closest('[data-widget]').dataset.widget;grip.addEventListener('dragstart',e=>{dragWidget=key;e.dataTransfer.setData('text/plain',key);});grip.addEventListener('dragend',()=>{dragWidget=null;});grip.addEventListener('keydown',e=>{if(!['ArrowUp','ArrowDown'].includes(e.key))return;e.preventDefault();const list=widgets().filter(w=>w!=='topology'&&(['ack','escalation','frequent'].includes(w)===['ack','escalation','frequent'].includes(key))),target=list[list.indexOf(key)+(e.key==='ArrowUp'?-1:1)];if(target){move(key,target);grip.focus();}});});
document.querySelectorAll('.dashboard-card').forEach(card=>{card.addEventListener('dragover',e=>{if(dragWidget)e.preventDefault();});card.addEventListener('drop',e=>{e.preventDefault();if(dragWidget&&(['ack','escalation','frequent'].includes(dragWidget)===['ack','escalation','frequent'].includes(card.dataset.widget)))move(dragWidget,card.dataset.widget);});});
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
document.querySelector('#birdsRefresh').addEventListener('click',async function(){this.disabled=true;try{const response=await fetch('topology.php?dashboard_readings=1',{credentials:'same-origin',cache:'no-store'});if(!response.ok)throw Error();readings=await response.json();radar();alarms();workflowCharts();frequentAlarms();status.textContent='Widgets updated '+new Date().toLocaleTimeString();}catch(e){status.textContent='Widget refresh failed. Try again.';}finally{this.disabled=false;}});
layout();radar();alarms();workflowCharts();frequentAlarms();
})();
