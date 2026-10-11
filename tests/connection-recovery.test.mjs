import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {
  earliestCatchableDeparture,validOnwardConnection,
  verifiedLateConnectionRisk,buildRecoveredJourney
} from '../dist/assets/connection-recovery.js';

const base=Date.parse('2026-10-11T10:00:00+11:00');
const iso=mins=>new Date(base+mins*60000).toISOString();
const station=(id,arrival=null,departure=null,extra={})=>({
 id,name:id,mode:'train',lat:-33.8,lon:151.1,arrival,departure,platform:'2',...extra
});
const clone=x=>structuredClone(x);
function setup(){
 const a=station('A',null,iso(0));
 const x=station('X',iso(12),iso(10),{plannedArrival:iso(3)});
 const b=station('B',iso(28),null);
 const prior={id:'leg-0',mode:'train',line:'T4',tripIds:['boarded-trip'],origin:a,destination:x,
   departure:iso(0),arrival:iso(12),stops:[a,x],path:[{lat:-33.8,lon:151.1}]};
 const connecting={id:'leg-1',mode:'train',line:'T8',tripIds:['old-onward'],origin:station('X',iso(12),iso(10)),
   destination:b,departure:iso(10),arrival:iso(28),stops:[station('X',iso(12),iso(10)),b]};
 const selected={id:'confirmed-trip',origin:a,destination:b,legs:[prior,connecting],
   stops:[a,x,b],transfers:[{id:'X',name:'X'}],
   timingVerifiedAt:iso(7),boardedDepartureAnchor:iso(0),crowding:{available:true}};
 const newTransfer=station('X',iso(12),iso(19)),newEnd=station('B',iso(44),null);
 const onward={id:'new-trip',origin:newTransfer,destination:newEnd,
   legs:[{id:'new-leg',mode:'train',line:'T8',tripIds:['new-onward'],origin:newTransfer,
     destination:newEnd,departure:iso(19),arrival:iso(44),stops:[newTransfer,newEnd]}],
   stops:[newTransfer,newEnd],transfers:[]};
 const risk={index:1,name:'X',arrival:base+12*60000,departure:base+10*60000};
 return {a,x,b,prior,selected,onward,risk};
}

test('SSA-02 searches from later of request and expected arrival plus 3 minutes',()=>{
 const {prior}=setup();
 assert.equal(earliestCatchableDeparture([prior],base+7*60000),base+15*60000);
 assert.equal(earliestCatchableDeparture([prior],base+18*60000),base+18*60000);
 assert.equal(earliestCatchableDeparture([],base),null);
 assert.equal(earliestCatchableDeparture([{destination:station('X')}],base),null);
});

test('SSA-02 accepts only a catchable train with valid onward chronology',()=>{
 const {selected,onward}=setup();
 const prior=selected.legs.slice(0,1),earliest=base+15*60000;
 assert.equal(validOnwardConnection(onward,selected.legs[1].origin,selected.destination,prior,earliest),true);
 const tooEarly=clone(onward);tooEarly.legs[0].origin.departure=iso(13);tooEarly.legs[0].departure=iso(13);
 assert.equal(validOnwardConnection(tooEarly,selected.legs[1].origin,selected.destination,prior,earliest),false);
 const bus=clone(onward);bus.legs[0].mode='bus';
 assert.equal(validOnwardConnection(bus,selected.legs[1].origin,selected.destination,prior,earliest),false);
 const wrong=clone(onward);wrong.legs[0].origin.id='Wrong';
 assert.equal(validOnwardConnection(wrong,selected.legs[1].origin,selected.destination,prior,earliest),false);
 const back=clone(onward);back.legs[0].destination.arrival=iso(18);
 assert.equal(validOnwardConnection(back,selected.legs[1].origin,selected.destination,prior,earliest),false);
});

test('SSA-02 automatic suggestion requires recent verified late first train, not schedule guesses',()=>{
 const {selected,risk}=setup();
 assert.equal(verifiedLateConnectionRisk(selected,risk,base+8*60000),true);
 assert.equal(verifiedLateConnectionRisk(selected,risk,base+14*60000),false);
 const noProof=clone(selected);delete noProof.timingVerifiedAt;
 assert.equal(verifiedLateConnectionRisk(noProof,risk,base+8*60000),false);
 const originallyTight=clone(selected);originallyTight.legs[0].destination.plannedArrival=iso(9);
 assert.equal(verifiedLateConnectionRisk(originallyTight,risk,base+8*60000),false);
 const noDelay=clone(selected);noDelay.legs[0].destination.arrival=iso(3);
 assert.equal(verifiedLateConnectionRisk(noDelay,risk,base+8*60000),false);
 assert.equal(verifiedLateConnectionRisk(selected,risk,base+6*60000-60000),false);
});

test('SSA-02 recovery retains exactly boarded prefix and updates onward train and stops',()=>{
 const {selected,onward}=setup();
 const existing=clone(selected);
 const recovered=buildRecoveredJourney(selected,1,onward,base+7*60000);
 assert.ok(recovered);
 assert.equal(recovered.legs.length,2);
 assert.deepEqual(recovered.legs[0],existing.legs[0]);
 assert.equal(recovered.legs[1].tripIds[0],'new-onward');
 assert.equal(recovered.boardedDepartureAnchor,iso(0));
 assert.equal(recovered.stops.filter(s=>s.id==='X').length,1);
 assert.equal(recovered.stops.find(s=>s.id==='X').arrival,iso(12));
 assert.equal(recovered.stops.find(s=>s.id==='X').departure,iso(19));
 assert.equal(recovered.transfers[0].arrivalPlatform,'2');
 assert.equal(recovered.crowding.available,false);
 assert.equal(recovered.timingVerifiedAt,undefined);
 assert.deepEqual(selected,existing);
});

test('SSA-02 refuses missed-transfer replacement if candidate is already departed',()=>{
 const {selected,onward}=setup();
 assert.equal(buildRecoveredJourney(selected,1,onward,base+21*60000),null);
 const outOfBounds=buildRecoveredJourney(selected,0,onward,base+7*60000);
 assert.equal(outOfBounds,null);
});

test('SSA-02 main coordinator keeps suggestion advisory, manual confirmation mandatory',()=>{
 const main=fs.readFileSync('src/main.ts','utf8');
 assert.match(main,/verifiedLateConnectionRisk\(state\.journey,risk\)/);
 assert.match(main,/getOnwardOptions\(transfer,original\.destination,notBefore\)/);
 assert.match(main,/state\.journey!==original/);
 assert.match(main,/data-accept-suggested/);
 assert.match(main,/if\(!window\.confirm\('Use this onward train\?/);
 assert.match(main,/if\(applyRecoveredConnection/);
 assert.match(main,/saveActiveTrip\(\)/);
 assert.match(main,/if\(state\.onboard\)void suggestOnwardConnection\(\)/);
});
