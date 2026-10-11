import test from 'node:test';
import assert from 'node:assert/strict';
import {loadStationIndex,saveStationIndex,findStationMatches,mergeStationEntries} from '../dist/assets/station-index.js';

function storageFixture(){
 const values=new Map();
 return {values,getItem:key=>values.get(key)??null,setItem:(key,value)=>values.set(key,value),removeItem:key=>values.delete(key)};
}
const sample=[
 {id:'2',name:'Town Hall',mode:'train'},
 {id:'3',name:'Central',mode:'train'},
 {id:'4',name:'North Sydney',mode:'train'},
 {id:'5',name:'North Ryde',mode:'metro'}
];

test('station index retains saved data within cache age and invalidates expired data',()=>{
 const storage=storageFixture();
 saveStationIndex(storage,'qa:index',sample,1000);
 assert.deepEqual(loadStationIndex(storage,'qa:index',500,1499),sample);
 assert.deepEqual(loadStationIndex(storage,'qa:index',500,1501),[]);
 assert.equal(storage.getItem('qa:index'),null);
});
test('station index malformed data is discarded while bad storage does not crash',()=>{
 const storage=storageFixture();
 storage.setItem('qa:index','{invalid');
 assert.deepEqual(loadStationIndex(storage,'qa:index',1000,100),[]);
 storage.setItem('qa:index',JSON.stringify({savedAt:0,stations:{}}));
 assert.deepEqual(loadStationIndex(storage,'qa:index',1000,100),[]);
 assert.equal(storage.getItem('qa:index'),null);
 const unavailable={getItem(){throw Error('blocked');},setItem(){throw Error('blocked');}};
 assert.deepEqual(loadStationIndex(unavailable,'qa:index',100,100),[]);
 assert.doesNotThrow(()=>saveStationIndex(unavailable,'qa:index',sample,100));
});
test('station matching retains prefix priority, case insensitive substring and twelve-result cap',()=>{
 assert.deepEqual(findStationMatches(sample,'no').map(s=>s.name),['North Ryde','North Sydney']);
 assert.deepEqual(findStationMatches(sample,'HALL').map(s=>s.name),['Town Hall']);
 assert.deepEqual(findStationMatches(sample,'n'),[]);
 const candidates=Array.from({length:15},(_,i)=>({id:String(i),name:'Park '+i}));
 assert.equal(findStationMatches(candidates,'park').length,12);
});
test('station merge preserves earlier entries and replaces matching IDs',()=>{
 const incoming=[{id:'3',name:'Central Updated',mode:'train'},{id:'9',name:'Gadigal',mode:'metro'}];
 const merged=mergeStationEntries(sample,incoming);
 assert.equal(merged.length,5);
 assert.equal(merged[1].name,'Central Updated');
 assert.equal(merged[4].name,'Gadigal');
 assert.equal(mergeStationEntries(sample,[]),sample);
});
