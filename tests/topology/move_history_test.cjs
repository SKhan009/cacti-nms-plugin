const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const code=fs.readFileSync('plugins/nms/js/nms-hybrid.js','utf8');
const n={id:7,x:60,y:40}, buttons={};
let fail=false;
const c=vm.createContext({editable:true,savingPosition:false,autoArranging:false,coordinate:v=>v,drag:null,panning:null,
 undoMoves:[],redoMoves:[],data:{nodes:[n]},deviceKind:()=> 'device',
 document:{getElementById:id=>buttons[id] ||= {}},draw:()=>{},message:()=>{},
 post:async()=>{if(fail) throw Error('save failed');}});
vm.runInContext(code.slice(code.indexOf('async function savePosition('),code.indexOf('\n\tfunction changedNodes()')),c);
vm.runInContext(code.slice(code.indexOf('function updateMoveButtons()'),code.indexOf('\n\tif (canEdit) document.getElementById("nms-undo")')),c);
(async()=>{
 await c.savePosition(n,{x:30,y:20});assert.equal(c.undoMoves.length,1);
 await c.replayMove(c.undoMoves,c.redoMoves);assert.equal(n.x,30);assert.equal(c.redoMoves.length,1);
 await c.replayMove(c.redoMoves,c.undoMoves);assert.equal(n.x,60);
 fail=true;await c.replayMove(c.undoMoves,c.redoMoves);assert.equal(n.x,30);assert.equal(c.undoMoves.length,0);
 fail=false;c.editable=false;await c.replayMove(c.undoMoves,c.redoMoves);assert.equal(n.x,30);
 console.log('PASS: undo, redo, draft-only moves and view-mode lock');
})();
