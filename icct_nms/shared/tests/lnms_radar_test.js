const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(path.join(__dirname,'../../dashboard/widgets/js/dashboard.js'),'utf8');
const start=source.indexOf('function radar(){'),end=source.indexOf('\nfunction alarms()',start);
class Node{constructor(tag,attrs={},text=''){this.tag=tag;this.attrs=attrs;this.textContent=text;this.children=[];this.events={};}append(...nodes){this.children.push(...nodes);}replaceChildren(){this.children=[];}addEventListener(name,callback){this.events[name]=callback;}}
const radar=new Node('svg'),note=new Node('p'),events=[];
const center={id:1,name:'Main LNMS',address:'10.0.0.1',status:'Online',coordinates:[13,77]};
const remote={id:2,name:'Remote LNMS',address:'10.0.0.2',status:'Offline',coordinates:[28,77]};
const context={readings:{sites:[{id:99,name:'Ordinary device site'}],lnms:{nodes:[center,remote],center}},document:{querySelector:s=>s==='#birdsRadar'?radar:note,dispatchEvent:e=>events.push(e)},svg:(...args)=>new Node(...args),CustomEvent:class{constructor(name,args){this.name=name;this.detail=args.detail;}}};
vm.createContext(context);vm.runInContext(source.slice(start,end)+';radar();',context);
let groups=radar.children.filter(n=>n.tag==='g');assert.equal(groups.length,2,'Only the configured LNMS pollers belong on the radar');
assert(groups[0].children.find(n=>n.tag==='title').textContent.includes('km from Cacti server'));
groups[0].events.click();assert.equal(events[0].name,'icct:show-lnms');assert.equal(events[0].detail,2);
assert.equal(groups[1].children.find(n=>n.tag==='circle'&&n.attrs.r===5).attrs.fill,'#2dc658','Healthy local heartbeat shows green');
context.readings.lnms.center.coordinates=null;context.readings.lnms.nodes=[center];vm.runInContext('radar()',context);
groups=radar.children.filter(n=>n.tag==='g');assert.equal(groups.length,1);assert.equal(note.textContent,'');assert.equal(note.hidden,true);
assert(!JSON.stringify(radar).includes('Ordinary device site'));
console.log('PASS: LNMS-only radar, heartbeat colors, real bearings, node summary actions and no invented remote sites.');
