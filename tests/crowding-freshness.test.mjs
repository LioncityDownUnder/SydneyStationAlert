import test from 'node:test';
import assert from 'node:assert/strict';
import {
  crowdingObservationAgeLabel, crowdingSummary, carriageCrowding
} from '../dist/assets/crowding-markup.js';

const now=Date.parse('2026-10-11T19:00:00+11:00');
const observed=secondsAgo=>new Date(now-secondsAgo*1000).toISOString();
const known=(legSpecs,overall='busy')=>({
  crowding:{
    available:true,level:overall,checkedAt:observed(0),
    // The response-level timestamp is deliberately wrong; the label must use
    // the observation for whichever leg is actually shown.
    updatedAt:observed(0),
    legs:legSpecs.map((l,i)=>({
      legId:'leg-'+i,mode:'train',vehicleMatched:true,
      level:'busy',updatedAt:observed(20),carriages:[],...l
    }))
  }
});

test('SSA-07 source age is minute-accurate, not a page fetch time',()=>{
  assert.equal(crowdingObservationAgeLabel(observed(0),now),'TfNSW crowding observed less than a minute ago');
  assert.equal(crowdingObservationAgeLabel(observed(59),now),'TfNSW crowding observed less than a minute ago');
  assert.equal(crowdingObservationAgeLabel(observed(60),now),'TfNSW crowding observed 1 minute ago');
  assert.equal(crowdingObservationAgeLabel(observed(119),now),'TfNSW crowding observed 1 minute ago');
  assert.equal(crowdingObservationAgeLabel(observed(120),now),'TfNSW crowding observed 2 minutes ago');
  assert.equal(crowdingObservationAgeLabel(observed(300),now),'TfNSW crowding observed 5 minutes ago');
});

test('SSA-07 hides freshness label for missing, older, invalid, future timestamps',()=>{
  for(const value of [null,undefined,'', '2025-10-01', 'invalid',observed(301),observed(-1)]) {
    assert.equal(crowdingObservationAgeLabel(value,now),'No freshness label for '+String(value));
  }
});

test('SSA-07 whole-train level shows observation age of the level actually displayed',()=>{
  const journey=known([
    {level:'moderate',updatedAt:observed(10)},
    {level:'busy',updatedAt:observed(180)}
  ],'busy');
  const html=crowdingSummary(journey,now);
  assert.match(html,/Crowding: Busy/);
  assert.match(html,/TfNSW crowding observed 3 minutes ago/);
  assert.doesNotMatch(html,/less than a minute ago/);
  assert.equal(carriageCrowding(journey,now),'');
});

test('SSA-07 carriage display uses source timestamp from shown leg, not the newest unseen leg',()=>{
 const journey=known([
   {mode:'train',level:'busy',updatedAt:observed(155),carriages:[
     {level:'quiet',position:1,name:'A'},
     {level:'busy',position:2,name:'B'}
   ]},
   {mode:'metro',level:'busy',updatedAt:observed(5),carriages:[
     {level:'very_busy',position:1,name:'Z'}
   ]}
 ]);
 assert.equal(crowdingSummary(journey,now),'','Carriage view replaces summary');
 const html=carriageCrowding(journey,now);
 assert.match(html,/2-car train/);
 assert.match(html,/TfNSW crowding observed 2 minutes ago/);
 assert.doesNotMatch(html,/5 minutes ago|less than a minute ago|code Z/);
 assert.equal((html.match(/crowding-freshness/g)||[]).length,1,'Only one time label');
});

test('SSA-07 never labels unavailable, unmatched, stale or uncertain train crowding',()=>{
 const noData=known([{level:'unknown',carriages:[],updatedAt:observed(10)}],'unknown');
 assert.equal(crowdingSummary(noData,now),'');
 assert.equal(carriageCrowding(noData,now),'');
 const unmatched=known([{level:'busy',vehicleMatched:false,updatedAt:observed(5)}]);
 assert.equal(crowdingSummary(unmatched,now),'');
 assert.equal(carriageCrowding(unmatched,now),'');
 const stale=known([{level:'busy',updatedAt:observed(310),carriages:[{level:'busy'}]}]);
 assert.equal(crowdingSummary(stale,now),'');
 assert.equal(carriageCrowding(stale,now),'');
 const skewed=known([{level:'busy',updatedAt:observed(-20),carriages:[{level:'busy'}]}]);
 assert.match(carriageCrowding(skewed,now),/Carriage crowding/,'SSA-03 clock-skew allowance remains unchanged');
 assert.doesNotMatch(carriageCrowding(skewed,now),/crowding observed/,'But do not claim a future observation is in the past');
});

test('SSA-07 distinguishes data received but no per-leg timestamp evidence',()=>{
 const data={crowding:{available:true,level:'quiet',checkedAt:observed(0),updatedAt:observed(0),legs:[
   {legId:'leg-0',mode:'metro',vehicleMatched:true,level:'quiet',updatedAt:null,carriages:[]}
 ]}};
 assert.equal(crowdingSummary(data,now),'');
 assert.equal(carriageCrowding(data,now),'');
});
