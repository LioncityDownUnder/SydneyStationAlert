import test from 'node:test';
import assert from 'node:assert/strict';
import {findConnectionAtRisk,calculateDisplayedProgress} from '../dist/assets/journey-monitoring.js';

const date=time=>Date.parse('2026-10-11T'+time+':00+11:00');
const transferJourney=(arrival='10:00',departure='10:02')=>({
 legs:[
  {arrival:new Date(date(arrival)).toISOString(),destination:{arrival:new Date(date(arrival)).toISOString()}},
  {departure:new Date(date(departure)).toISOString(),origin:{id:'B',name:'Town Hall',departure:new Date(date(departure)).toISOString()}}
 ]
});

test('interchange warning retains strict 3-minute threshold and 45-minute expiry',()=>{
 const short=transferJourney('10:00','10:02');
 assert.deepEqual(findConnectionAtRisk(short,true,false,date('09:58')),{index:1,name:'Town Hall',arrival:date('10:00'),departure:date('10:02')});
 assert.equal(findConnectionAtRisk(transferJourney('10:00','10:03'),true,false,date('10:01')),null);
 assert.equal(findConnectionAtRisk(short,true,false,date('10:47')),null);
 assert.ok(findConnectionAtRisk(short,true,false,date('10:46')));
});

test('interchange warning is suppressed when not onboard or paused',()=>{
 const route=transferJourney();
 assert.equal(findConnectionAtRisk(route,false,false,date('10:00')),null);
 assert.equal(findConnectionAtRisk(route,true,true,date('10:00')),null);
 assert.equal(findConnectionAtRisk(null,true,false,date('10:00')),null);
 assert.equal(findConnectionAtRisk({legs:[]},true,false,date('10:00')),null);
 assert.equal(findConnectionAtRisk({legs:[{arrival:'bad'}, {departure:'bad'}]},true,false,date('10:00')),null);
});

test('interchange warning uses fallback leg timestamps and name',()=>{
 const route={legs:[
  {arrival:new Date(date('10:00')).toISOString()},
  {departure:new Date(date('10:02')).toISOString(),origin:{id:'B'}}
 ]};
 assert.deepEqual(findConnectionAtRisk(route,true,false,date('10:01')),{index:1,name:'the interchange',arrival:date('10:00'),departure:date('10:02')});
});

const stop=(id,name,time,lat,lon)=>({
 id,name,lat,lon,...(time?{arrival:new Date(date(time)).toISOString()}: {})
});
const journey=()=>({
 stops:[
  stop('A','Central','10:00',-33.883,151.206),
  stop('X','Bypassed station',null,-33.881,151.207),
  stop('B','Town Hall','10:04',-33.873,151.206),
  stop('Y','Another bypass',null,-33.871,151.207),
  stop('C','Wynyard','10:06',-33.865,151.206),
  stop('D','North Sydney','10:12',-33.840,151.208)
 ],
 transfers:[{id:'B',name:'Town Hall'}],
 legs:[]
});

test('onboard schedule fallback counts only scheduled stopping stations',()=>{
 const route=journey(),farLocation={lat:0,lon:0};
 const beforeChange=calculateDisplayedProgress(route,farLocation,true,date('10:01'));
 assert.deepEqual(beforeChange,{index:0,remaining:3,toChange:1,nearest:null});
 const afterChange=calculateDisplayedProgress(route,farLocation,true,date('10:05'));
 assert.deepEqual(afterChange,{index:2,remaining:2,toChange:null,nearest:null});
 assert.deepEqual(calculateDisplayedProgress(route,farLocation,true,date('10:13')),{index:5,remaining:0,toChange:null,nearest:null});
});

test('GPS progress takes priority onboard; preview stays at origin',()=>{
 const route=journey(),atTownHall={lat:-33.873,lon:151.206};
 const gps=calculateDisplayedProgress(route,atTownHall,true,date('09:00'));
 assert.equal(gps.index,2);
 assert.equal(gps.remaining,2);
 assert.equal(gps.toChange,null);
 assert.equal(gps.nearest.id,'B');
 const notBoarded=calculateDisplayedProgress(route,atTownHall,false,date('10:13'));
 assert.equal(notBoarded.index,0);
 assert.equal(notBoarded.remaining,3);
 assert.equal(notBoarded.toChange,1);
 assert.equal(notBoarded.nearest.id,'A');
});

test('calculation has no side effects on the route',()=>{
 const route=journey(),before=JSON.stringify(route);
 calculateDisplayedProgress(route,{lat:0,lon:0},true,date('10:05'));
 findConnectionAtRisk({legs:transferJourney().legs},true,false,date('10:00'));
 assert.equal(JSON.stringify(route),before);
});
