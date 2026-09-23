const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const code=fs.readFileSync('plugins/nms/js/nms-hybrid.js','utf8');
const n={id:1,x:80,y:60};let choice='cancel',writes=0,exits=0;
const c=vm.createContext({data:{nodes:[n]},editBaseline:new Map([[1,{x:20,y:30}]]),
 editable:true,exitPending:false,savingPosition:false,autoArranging:false,drag:null,panning:null,
 deviceKind:()=> 'device',askLayoutSave:async()=>choice,post:async()=>{writes++;},
 message:()=>{},updateMoveButtons:()=>{},setEditMode:v=>{c.editable=v;},leaveFullscreen:async()=>{exits++;}});
vm.runInContext(code.slice(code.indexOf('function changedNodes()'),code.indexOf('\n\tfunction askLayoutSave()')),c);
(async()=>{
 await c.finishEditing();assert.equal(writes,0);assert.equal(exits,0);assert.equal(n.x,80);
 choice='discard';await c.finishEditing();assert.equal(writes,0);assert.equal(n.x,20);
 c.editable=true;n.x=90;choice='save';await c.finishEditing();assert.equal(writes,1);assert.equal(c.editBaseline.get(1).x,90);
 console.log('PASS: keep editing, discard without writes, explicit save');
})();
