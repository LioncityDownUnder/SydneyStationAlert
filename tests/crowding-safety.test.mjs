import test from 'node:test';
import assert from 'node:assert/strict';
import { sanitizedSelectedCrowding, crowdingCurrentlyVisible } from '../dist/assets/crowding-safety.js';

const now=Date.parse('2026-10-11T12:00:00+11:00');
const at=(offset=0)=>new Date(now+offset*1000).toISOString();
const trip={ legs:[{mode:'train',tripIds:['Selected-Train','alias']}] };
const observation=(override={})=>({
  available:true,level:'busy',updatedAt:at(-45),checkedAt:at(),
  legs:[{legId:'leg-0',mode:'train',vehicleMatched:true,
    tripId:' SELECTED-TRAIN ',level:'busy',updatedAt:at(-45),
    carriages:[{position:1,level:'busy',name:'Car 1'}],...override}]
});

test('SSA-03 accepts a recent exact selected trip ID regardless of case or surrounding whitespace',()=>{
  const result=sanitizedSelectedCrowding(trip,observation(),now);
  assert.equal(result.available,true);
  assert.equal(result.level,'busy');
  assert.equal(result.legs[0].tripId,'selected-train');
  assert.equal(result.legs[0].carriages.length,1);
  assert.equal(crowdingCurrentlyVisible(result,now),true);
});

test('SSA-03 stale, future and missing timestamps cannot appear after live refresh or reload',()=>{
 for(const stamp of [at(-360),at(90),null,'invalid']){
  const response=observation({updatedAt:stamp});
  const output=sanitizedSelectedCrowding(trip,response,now);
  assert.equal(output.available,false,'rejected '+stamp);
  assert.equal(output.legs[0].carriages.length,0);
 }
 const live=sanitizedSelectedCrowding(trip,observation(),now);
 assert.equal(crowdingCurrentlyVisible(live,now+360*1000),false,'same saved response stops displaying after time passes');
});

test('SSA-03 refuses payloads that claim vehicle data from another exact trip',()=>{
 const wrong=sanitizedSelectedCrowding(trip,observation({tripId:'other-train'}),now);
 assert.equal(wrong.available,false);
 assert.deepEqual(wrong.legs,[]);
 const missing=sanitizedSelectedCrowding(trip,observation({vehicleMatched:true,tripId:null}),now);
 assert.equal(missing.available,false);
 const falseMatch=sanitizedSelectedCrowding(trip,observation({vehicleMatched:false,tripId:'selected-train'}),now);
 assert.equal(falseMatch.available,false);
});

test('SSA-03 refuses swapped legs, misreported mode and structurally incomplete crowding',()=>{
 assert.equal(sanitizedSelectedCrowding(trip,{legs:[]},now).available,false);
 assert.equal(sanitizedSelectedCrowding(trip,observation({legId:'leg-1'}),now).available,false);
 assert.equal(sanitizedSelectedCrowding(trip,observation({mode:'metro'}),now).available,false);
 const two={legs:[...trip.legs,{mode:'metro',tripIds:['metro-trip']}]};
 assert.equal(sanitizedSelectedCrowding(two,observation(),now).available,false);
});

test('SSA-03 unknown occupancy stays unknown even with a known exact vehicle',()=>{
 const unknown=sanitizedSelectedCrowding(trip,observation({level:'unknown',carriages:[{level:'unknown'}]}),now);
 assert.equal(unknown.available,false);
 assert.equal(unknown.level,'unknown');
 assert.equal(crowdingCurrentlyVisible(unknown,now),false);
});

test('SSA-03 live crowding is denied on hard-reload if saved selected train identifiers change',()=>{
 const saved=sanitizedSelectedCrowding(trip,observation(),now);
 const different={legs:[{mode:'train',tripIds:['different-train']}]};
 assert.equal(sanitizedSelectedCrowding(different,saved,now).available,false);
});
