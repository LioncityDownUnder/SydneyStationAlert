import test from 'node:test';
import assert from 'node:assert/strict';
import {sameService,firstDepartureTime} from '../dist/assets/journey-service.js';

const place=id=>({id});
const leg=(tripIds,line='T4',from='A',to='B')=>({
  tripIds,line,origin:place(from),destination:place(to),
  departure:'2026-10-11T10:00:00+11:00'
});
const journey=(...legs)=>({legs});

test('service comparison matches case-insensitive and numeric shared trip IDs',()=>{
 assert.equal(sameService(journey(leg(['TRAIN-12'])),journey(leg(['train-12']))),true);
 assert.equal(sameService(journey(leg([123])),journey(leg(['123']))),true);
 assert.equal(sameService(journey(leg(['TRAIN-12'])),journey(leg(['train-13']))),false);
 assert.equal(sameService(journey(leg(['train-12'])),journey(leg(['train-13'],'T2'))),false);
});

test('service comparison preserves legacy fallback only when a leg lacks trip IDs',()=>{
 assert.equal(sameService(journey(leg([])),journey(leg(['another-id']))),true);
 assert.equal(sameService(journey(leg([])),journey(leg([], 'T3'))),false);
 assert.equal(sameService(journey(leg([], 'T4','A','B')),journey(leg([], 'T4','C','B'))),false);
 assert.equal(sameService(journey(leg(['a'], 'T4','A','B')),journey(leg(['b'],'T4','A','B'))),false);
});

test('multi-leg comparisons require identical leg count and matching legs',()=>{
 const first=journey(leg(['one'],'T4','A','B'),leg(['two'],'T8','B','C'));
 const same=journey(leg(['ONE'],'T4','A','B'),leg(['TWO'],'T8','B','C'));
 const changed=journey(leg(['one'],'T4','A','B'),leg(['three'],'T8','B','C'));
 assert.equal(sameService(first,same),true);
 assert.equal(sameService(first,changed),false);
 assert.equal(sameService(first,journey(leg(['one']))),false);
 assert.equal(sameService(first,null),false);
 assert.equal(sameService(undefined,first),false);
 assert.equal(sameService({legs:null},first),false);
});

test('departure uses first leg time, then origin departure, otherwise unknown',()=>{
 const direct='2026-10-11T10:00:00+11:00';
 const fallback='2026-10-11T09:58:00+11:00';
 assert.equal(firstDepartureTime(journey({...leg(['id']),departure:direct,origin:{id:'A',departure:fallback}})),Date.parse(direct));
 assert.equal(firstDepartureTime(journey({...leg(['id']),departure:'',origin:{id:'A',departure:fallback}})),Date.parse(fallback));
 assert.ok(Number.isNaN(firstDepartureTime(journey({}))));
 assert.ok(Number.isNaN(firstDepartureTime(null)));
});

test('comparison does not mutate either journey',()=>{
 const left=journey(leg(['ID']));
 const right=journey(leg(['id']));
 const previous=JSON.stringify([left,right]);
 assert.equal(sameService(left,right),true);
 assert.equal(JSON.stringify([left,right]),previous);
});
