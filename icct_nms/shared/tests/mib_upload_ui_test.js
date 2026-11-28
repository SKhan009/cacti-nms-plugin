'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../../protocols/snmp/mibs/js/mib-upload.js'), 'utf8');
class Element {
    constructor() { this.events = {}; this.children = []; this.dataset = {}; this.hidden = true; this.classList = {add(){},remove(){}}; }
    addEventListener(event, callback) { this.events[event] = callback; }
    emit(event) { return this.events[event]?.({preventDefault(){}}); }
    setAttribute() {}
    removeAttribute() {}
    append(child) { this.children.push(child); }
    replaceChildren() { this.children = []; }
    querySelector(selector) { return this.select[selector]; }
    querySelectorAll() { return []; }
    showModal() { this.open = true; }
    close() { this.open = false; this.emit('close'); }
    reset() {}
    focus() {}
}
function fixture() {
    const nodes = new Map();
    const node = (selector) => { if (!nodes.has(selector)) nodes.set(selector, new Element()); return nodes.get(selector); };
    const files = new Element(), requests = [], fetches = [];
    node('#mibUploadChooser').select = {'[type=file]':files};
    node('#mibUploadChooser').getAttribute = () => '/mib_repository.php';
    const automatic = new Element(), repository = new Element(); automatic.value = 'automatic'; repository.value = 'repository';
    node('#mibUploadNextForm').select = {'[name=upload_next]:checked':automatic, '[value=automatic]':automatic, '[value=repository]':repository};
    class FormData {
        constructor(form) { this.values = new Map([['mibs[]', form === node('#mibUploadChooser') ? files.files.slice() : []]]); }
        set(k,v) { this.values.set(k,v); } delete(k) { this.values.delete(k); }
    }
    class Request extends Element {
        constructor() { super(); this.upload = new Element(); requests.push(this); }
        open() {} setRequestHeader() {} abort() { this.aborted = true; }
        send(body) { this.body = body; }
    }
    vm.runInNewContext(source, {
        document:{querySelector:node,querySelectorAll:()=>[],createElement:()=>new Element(),baseURI:'http://localhost/'},
        URL, FormData, XMLHttpRequest:Request, icctRandomToken:()=> 'a'.repeat(32),
        location:{origin:'http://localhost',assign(){}}, fetch:(url,args)=>{ fetches.push(args); return Promise.resolve({}); },
    });
    function choose(selected) { files.files = selected; files.emit('change'); }
    function complete(validation) {
        const summary = {name:'UPS-MIB',can_configure:true,object_count:71,supported_count:60,existing_count:0,
            validation, dependencies:['SNMPv2-SMI'],repository_check:{blocked:false,warnings:[],exact:[],fingerprint:'b'.repeat(64)}};
        const request = requests.at(-1);
        request.status = 200;
        request.responseText = JSON.stringify({ok:true,upload_token:'a'.repeat(32),object_count:71,summary});
        request.emit('load');
    }
    return {node,choose,requests,complete,fetches};
}
const files = ['UPS-MIB','SNMPv2-SMI','SNMPv2-TC','SNMPv2-CONF'].map(name=>({name:name+'.txt',size:2048}));
for (const bad of [
    [...files,{name:'bad.html',size:10}],
    [...files,{name:'empty.txt',size:0}],
    [...files,{name:'large.txt',size:1048577}],
    Array.from({length:21},()=>files[0]),
    Array.from({length:9},()=>({name:'big.txt',size:1048576})),
]) {
    const f = fixture(); f.choose(bad);
    assert.equal(f.requests.length,0); assert.equal(f.node('#mibUploadError').hidden,false);
}
const validRows = files.map(file=>({name:file.name,module:file.name.slice(0,-4),status:'valid',message:'Validated'}));
const f = fixture(); f.choose(files);
assert.equal(f.requests.length,1);
assert.equal(f.requests[0].body.values.get('upload_file_count'),'4');
assert.deepEqual(f.requests[0].body.values.get('mibs[]'), files);
f.complete(validRows);
assert.equal(f.node('#mibUploadValidationRows').children.length,4);
assert.equal(f.node('#mibUploadReadNext').hidden,false);
assert.match(f.node('#mibUploadDependencies').textContent,/SNMPv2-SMI/);
// Selecting an invalid replacement invalidates the previous ready-to-save draft.
f.choose([{name:'bad.exe',size:1}]);
assert.equal(f.fetches.length,1); assert.equal(f.fetches[0].body.values.get('action'),'discard_upload');
assert.equal(f.node('#mibUploadReadNext').hidden,true);
f.node('#mibUploadNextForm').emit('submit');
assert.equal(f.fetches.length,1);
for (const incomplete of [validRows.slice(0,3),validRows.map((r,i)=>i===3?{...r,status:'unresolved'}:r)]) {
    const f = fixture(); f.choose(files); f.complete(incomplete);
    assert.equal(f.node('#mibUploadReadNext').hidden,true);
    assert.equal(f.node('#mibUploadError').hidden,false);
}
const escaped = fixture(); escaped.choose(files);
escaped.complete(validRows.map((r,i)=>i===0?{...r,name:'<img src=x onerror=alert(1)>.txt'}:r));
const cell = escaped.node('#mibUploadValidationRows').children[0].children[0];
assert.equal(cell.textContent,'<img src=x onerror=alert(1)>.txt');
assert.equal(cell.children.length,0);
f.node('#mibUploadDialog').close();
assert.equal(f.node('#mibUploadValidation').hidden,true);
console.log('MIB upload UI: multi-file posting, limits, response completeness, safe rendering and draft cancellation passed.');
