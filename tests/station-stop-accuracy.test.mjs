import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {progress, alertKeys} from '../dist/assets/logic.js';

// Stage 1: actual stopping stations, not all physical stations along the line.
const time='2026-10-16T20:00:00+11:00';
const make=(id,name,stops,lat)=>({id,name,mode:'train',lat,lon:151.2,arrival:stops?time:null,departure:stops?time:null});
const origin=make('1','Origin',true,-33.90);
const skippedA=make('2','Bypassed A',false,-33.89);
const skippedB=make('3','Bypassed B',false,-33.88);
const interchange=make('4','Interchange',true,-33.87);
const skippedC=make('5','Bypassed C',false,-33.86);
const destination=make('6','Destination',true,-33.85);
const journey={stops:[origin,skippedA,skippedB,interchange,skippedC,destination],transfers:[interchange]};

test('express train: physical stations do not inflate stop count',()=>{
 assert.equal(journey.stops.length,6);
 assert.equal(progress(journey,null).remaining,2);
 assert.equal(progress(journey,origin).remaining,2);
 assert.equal(progress(journey,interchange).remaining,1);
 assert.equal(progress(journey,destination).remaining,0);
});

test('interchange and destination alerts ignore bypassed stations',()=>{
 assert.equal(progress(journey,origin).toChange,1);
 assert.equal(progress(journey,interchange).toChange,null);
 assert.deepEqual(alertKeys(journey,0),['destination-two','transfer-4']);
 assert.deepEqual(alertKeys(journey,3),['destination-one']);
});

test('GPS position at bypassed station never marks that station as current stop',()=>{
 const p=progress(journey,skippedB);
 assert.notEqual(p.nearest?.id,skippedB.id);
 assert.notEqual(p.nearest?.id,skippedA.id);
});

test('journey UI labels bypassed stations and applies greyed-out class',()=>{
 const source=fs.readFileSync('src/main.ts','utf8');
 assert.match(source,/const bypassed=i>0&&i<j\.stops\.length-1&&!s\.arrival&&!s\.departure/);
 assert.match(source,/bypassed\?'bypassed '/);
 assert.match(source,/Does not stop/);
 const css=fs.readFileSync('public/styles.css','utf8');
 assert.match(css,/\.stop\.bypassed/);
});
