const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const code=fs.readFileSync('plugins/nms/js/nms-hybrid.js','utf8'),button={};
const nodes=Array.from({length:100},(_,i)=>({id:i,x:50,y:50}));
let saved=0,fitted=false;
const c=vm.createContext({editable:true,savingPosition:false,autoArranging:false,drag:null,panning:null,
 data:{nodes},deviceKind:()=> 'device',width:()=>3000,height:()=>2000,
 document:{getElementById:()=>button},savePosition:async()=>{saved++;return true;},
 updateMoveButtons:()=>{},fitDevices:()=>{fitted=true;},message:()=>{}});
vm.runInContext(code.slice(code.indexOf('const arrangeButton ='),code.indexOf('\n\tconst editButton')),c);
(async()=>{await button.onclick();assert.equal(saved,100);assert.equal(new Set(nodes.map(n=>`${n.x},${n.y}`)).size,100);assert.ok(fitted);assert.equal(c.autoArranging,false);console.log('PASS: 100 distinct arranged positions saved and fitted');})();
