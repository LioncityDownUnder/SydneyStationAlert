import test from 'node:test';
import assert from 'node:assert/strict';
import {activeTripExpiry,saveConfirmedTrip,loadConfirmedTrip,clearConfirmedTrip} from '../dist/assets/active-trip.js';

function memoryStorage(){
 const entries=new Map();
 return {entries,getItem:key=>entries.get(key)??null,setItem:(key,value)=>entries.set(key,value),removeItem:key=>entries.delete(key)};
}
const journey={legs:[{arrival:'2026-10-11T14:00:00+11:00'}],stops:[{id:'1'}]};
test('confirmed journey stores versioned data and restores without changing the journey',()=>{
 const storage=memoryStorage(),now=Date.parse('2026-10-11T12:00:00+11:00');
 saveConfirmedTrip(storage,'qa:active',journey,now);
 assert.deepEqual(loadConfirmedTrip(storage,'qa:active',now+1000),journey);
 const saved=JSON.parse(storage.getItem('qa:active'));
 assert.equal(saved.version,1);
 assert.equal(saved.savedAt,now);
 assert.equal(saved.expiresAt,Date.parse(journey.legs[0].arrival)+4*3600000);
 assert.equal(storage.getItem('prod:active'),null);
});
test('missing arrival expires eight hours after saving',()=>{
 const now=1000;
 assert.equal(activeTripExpiry({legs:[]},now),now+8*3600000);
});
test('expired or invalid trip is cleared without being restored',()=>{
 const storage=memoryStorage(),now=10000;
 storage.setItem('trip',JSON.stringify({version:1,expiresAt:now,journey}));
 assert.equal(loadConfirmedTrip(storage,'trip',now),null);
 assert.equal(storage.getItem('trip'),null);
 storage.setItem('trip',JSON.stringify({version:2,expiresAt:now+1000,journey}));
 assert.equal(loadConfirmedTrip(storage,'trip',now),null);
 storage.setItem('trip','{broken');
 assert.equal(loadConfirmedTrip(storage,'trip',now),null);
});
test('unavailable storage does not crash journey persistence',()=>{
 const storage={getItem(){throw Error('blocked');},setItem(){throw Error('blocked');},removeItem(){throw Error('blocked');}};
 assert.doesNotThrow(()=>saveConfirmedTrip(storage,'trip',journey));
 assert.equal(loadConfirmedTrip(storage,'trip'),null);
 assert.doesNotThrow(()=>clearConfirmedTrip(storage,'trip'));
});
test('clear only affects the given environment key',()=>{
 const storage=memoryStorage();
 saveConfirmedTrip(storage,'qa',journey,100);
 saveConfirmedTrip(storage,'prod',journey,100);
 clearConfirmedTrip(storage,'qa');
 assert.equal(storage.getItem('qa'),null);
 assert.notEqual(storage.getItem('prod'),null);
});
