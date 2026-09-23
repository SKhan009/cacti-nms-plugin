const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const code = fs.readFileSync('plugins/nms/js/nms-hybrid.js', 'utf8');
const handlers = {};
const classes = new Set();
const node = {id: 1, x: 40, y: 30};
const context = vm.createContext({
  n: node, editable: true, drag: null, panning: null, panX: 0, panY: 0,
  width: () => 1000, height: () => 800,
  point: e => ({x:e.clientX, y:e.clientY}),
  draw: () => {}, savePosition: async (n,old) => { context.saved={x:n.x,y:n.y,old}; },
  g: {addEventListener: (name, fn) => {handlers['node:'+name]=fn;}},
  svg: {addEventListener: (name,fn) => {handlers[name]=fn;},
    classList:{add: (...v)=>v.forEach(x=>classes.add(x)),remove:(...v)=>v.forEach(x=>classes.delete(x))},
    setPointerCapture:()=>{}, hasPointerCapture:()=>true,releasePointerCapture:()=>{},
    getScreenCTM:()=>({a:2,d:2})}
});
const nodeStart = code.indexOf('\n\t\t\tg.addEventListener("pointerdown"');
vm.runInContext(code.slice(nodeStart,code.indexOf('g.addEventListener("click"',nodeStart)),context);
vm.runInContext(code.slice(code.indexOf('svg.addEventListener("pointerdown"'),code.indexOf('\n\t/**\n\t * Updates save Position.')),context);
vm.runInContext(code.slice(code.indexOf('svg.addEventListener("pointerup"'),code.indexOf('\n\t/**\n\t * Handles post.')),context);
function event(x,y,target=context.svg) {return {clientX:x,clientY:y,target,button:0,pointerId:1,preventDefault(){},stopPropagation(){this.stopped=true;}};}
(async()=>{
 const start=event(410,250); handlers['node:pointerdown'](start);
 assert.equal(start.stopped,true);
 handlers.pointerdown(start); assert.equal(context.panning,null);
 handlers.pointermove(event(510,330));
 assert.equal(node.x,50); assert.equal(node.y,40);
 assert.equal(context.panX,0); assert.equal(context.panY,0);
 await handlers.pointerup(event(510,330)); assert.equal(context.saved.x,50); assert.equal(classes.size,0);
 handlers.pointerdown(event(10,10)); handlers.pointermove(event(110,70));
 assert.equal(context.panX,-50); assert.equal(context.panY,-30); assert.equal(node.x,50);
 await handlers.pointerup(event(110,70));
 handlers['node:pointerdown'](event(500,320));handlers.pointermove(event(600,400));handlers.pointercancel();
 assert.equal(node.x,50);assert.equal(node.y,40);assert.equal(classes.size,0);
 context.editable=false;handlers['node:pointerdown'](event(500,320));assert.equal(context.drag,null);
 console.log('PASS: device drag, pointer offset, pan isolation, save, cancellation and read-only access');
})();
