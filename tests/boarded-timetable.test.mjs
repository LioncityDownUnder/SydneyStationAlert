import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {
  canCheckBoardedTimetable, selectedTimetableRequest,
  mergeVerifiedBoardedTimetable, estimatedStopDelayMinutes
} from '../dist/assets/boarded-timetable.js';

const t = '2026-10-11T10:00:00+11:00';
const stop = (id, arrival=null, departure=null) => ({
  id, name: id, arrival, departure, platform:'1'
});
function route(ids=['selected-123']) {
  const a=stop('A',null,t), b=stop('B','2026-10-11T10:10:00+11:00',null);
  return {
    id:'original-journey', origin:a, destination:b,
    boardedDepartureAnchor:t,
    legs:[{id:'leg-0',mode:'train',line:'T4',origin:a,destination:b,
      stops:[a,b],tripIds:ids,departure:t,arrival:b.arrival,platform:'1',
      path:[{lat:-33.8,lon:151.1}]}],
    stops:[a,b],crowding:{available:true},other:'preserved'
  };
}
const clone=x=>JSON.parse(JSON.stringify(x));

test('SSA-01 keeps immutable saved boarding anchor and explicit stop IDs',()=>{
 const j=route();
 const req=selectedTimetableRequest(j);
 assert.equal(req.anchor,t);
 assert.deepEqual(req.legs[0].tripIds,['selected-123']);
 assert.deepEqual(req.legs[0].stopIds,['A','B']);
 assert.equal(canCheckBoardedTimetable(j),true);
 assert.equal(canCheckBoardedTimetable(route([])),false);
});

test('SSA-01 merges verified same-trip estimated times without changing service or map',()=>{
 const old=route();const next=clone(old);
 next.id='provider-recalculated-id';
 next.legs[0].tripIds=['other-alias','selected-123'];
 next.legs[0].departure='2026-10-11T10:06:00+11:00';
 next.legs[0].arrival='2026-10-11T10:16:00+11:00';
 next.legs[0].origin.departure=next.legs[0].departure;
 next.legs[0].origin.plannedDeparture=t;
 next.legs[0].stops[0].departure=next.legs[0].departure;
 next.legs[0].stops[0].plannedDeparture=t;
 next.legs[0].stops[1].arrival=next.legs[0].arrival;
 next.legs[0].stops[1].plannedArrival=old.legs[0].arrival;
 next.stops[0].departure=next.legs[0].departure;
 next.stops[0].plannedDeparture=t;
 next.stops[1].arrival=next.legs[0].arrival;
 next.stops[1].plannedArrival=old.legs[0].arrival;
 const verified=mergeVerifiedBoardedTimetable(old,next,'2026-10-11T10:04:00Z');
 assert.ok(verified);
 assert.equal(verified.id,'original-journey');
 assert.deepEqual(verified.legs[0].tripIds,['selected-123']);
 assert.deepEqual(verified.legs[0].path,old.legs[0].path);
 assert.deepEqual(verified.crowding,old.crowding);
 assert.equal(verified.boardedDepartureAnchor,t);
 assert.equal(estimatedStopDelayMinutes(verified.stops[0],true),6);
 assert.equal(estimatedStopDelayMinutes(verified.stops[1],false),6);
 assert.equal(verified.stops[1].arrival,'2026-10-11T10:16:00+11:00');
 assert.equal(old.stops[1].arrival,'2026-10-11T10:10:00+11:00');
});

test('SSA-01 does not accept a different train despite matching line, time and stations',()=>{
 const old=route(),next=route(['replacement-456']);
 assert.equal(mergeVerifiedBoardedTimetable(old,next,'now'),null);
 assert.equal(mergeVerifiedBoardedTimetable(route([]),route([]),'now'),null);
});

test('SSA-01 rejects different stopping patterns, endpoints, and implausible departure jumps',()=>{
 const old=route();
 const extra=clone(old);extra.legs[0].stops.splice(1,0,stop('X'));
 assert.equal(mergeVerifiedBoardedTimetable(old,extra,'now'),null);
 const otherEnd=clone(old);otherEnd.legs[0].destination.id='C';
 assert.equal(mergeVerifiedBoardedTimetable(old,otherEnd,'now'),null);
 const late=clone(old);late.legs[0].departure='2026-10-11T14:00:00+11:00';
 assert.equal(mergeVerifiedBoardedTimetable(old,late,'now'),null);
});

test('SSA-01 only presents positive provider-estimated delays',()=>{
 assert.equal(estimatedStopDelayMinutes({
  id:'A',plannedDeparture:t,departure:'2026-10-11T09:58:00+11:00'
 },true),null);
 assert.equal(estimatedStopDelayMinutes({id:'A',departure:t},true),null);
 assert.equal(estimatedStopDelayMinutes({
  id:'B',plannedArrival:'2026-10-11T10:00:00+11:00',
  arrival:'2026-10-11T10:05:00+11:00'
 },false),5);
});

test('SSA-01 refresh never falls back to a next-train search when already boarded',()=>{
 const main = fs.readFileSync('src/main.ts','utf8');
 const section=main.slice(main.indexOf('async function refresh(){'),
   main.indexOf('// A stale or distant GPS',main.indexOf('async function refresh(){')));
 assert.match(section,/if\(state\.onboard\)\{/);
 assert.match(section,/await getBoardedTimetable\(selected\)/);
 assert.match(section,/mergeVerifiedBoardedTimetable\(selected,checked\.journey,checked\.checkedAt\)/);
 assert.match(section,/generation!==journeyGeneration\|\|state\.journey!==selected/);
 assert.match(section,/no alternative train was selected/);
 const elseIndex=section.indexOf('}else{\n   const updated=await getJourney');
 assert.ok(elseIndex>0);
 assert.doesNotMatch(section.slice(0,elseIndex),/getJourney\(/);
});
