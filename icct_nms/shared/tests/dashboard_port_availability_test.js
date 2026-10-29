const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const source=fs.readFileSync(path.join(__dirname,'../../dashboard/widgets/js/dashboard.js'),'utf8');
const context={};vm.createContext(context);vm.runInContext(source.slice(source.indexOf('function portAvailability('),source.indexOf('function portsOverview('))+';globalThis.availability=portAvailability;',context);
const cases=[
 [{admin:1,oper:1},true,'Utilized/Occupied'],
 [{admin:2,oper:2},true,'Utilized/Occupied'],
 [{admin:1,oper:2},true,'Unoccupied'],
 [{admin:1,oper:4},true,'—'],
 [{admin:1,oper:1},false,'—'],
 [{admin:1,oper:7},true,'—']
];
for(const [port,fresh,expected] of cases)assert.equal(context.availability(port,fresh),expected);
console.log('PASS: reference availability labels preserve missing, stale and indeterminate readings.');
