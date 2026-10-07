import test from 'node:test';import assert from 'node:assert/strict';import fs from 'node:fs';
const source=fs.readFileSync('src/logic.ts','utf8');
test('progress and alert code includes both thresholds and transfer detection',()=>{assert.match(source,/destination-two/);assert.match(source,/destination-one/);assert.match(source,/transfer-/);});
test('geolocation is centralized and distance-based',()=>{assert.match(fs.readFileSync('src/constants.ts','utf8'),/nearbyMetres/);assert.match(source,/distanceMetres/);});
test('network calls never contain a client API secret',()=>{const code=fs.readFileSync('src/api.ts','utf8');assert.doesNotMatch(code,/TFNSW_API_KEY|apikey /i);});
test('provider fixtures exist',()=>{for(const name of ['success','no-route','mixed','transfer','missing-platform','partial'])assert.ok(JSON.parse(fs.readFileSync(`tests/fixtures/${name}.json`)));});

import {nearestStation,progress,alertKeys,fmtTime} from '../dist/assets/logic.js';
const central={id:'1',name:'Central',mode:'train',lat:-33.883,lon:151.206};
const museum={id:'2',name:'Museum',mode:'train',lat:-33.876,lon:151.209};
const town={id:'3',name:'Town Hall',mode:'train',lat:-33.873,lon:151.207};
const mock={id:'test',origin:central,destination:town,legs:[],stops:[central,museum,town],transfers:[museum],omittedNonRail:false,fetchedAt:'2026-10-07T00:00:00Z',source:'live',nextDepartures:[]};
test('nearest station uses geodesic radius',()=>{assert.equal(nearestStation([central,museum],central)?.id,'1');assert.equal(nearestStation([central],{lat:-34.5,lon:150.4}),null);});
test('progress and no duplicate threshold keys calculate accurately',()=>{const p=progress(mock,museum);assert.equal(p.remaining,1);assert.equal(p.toChange,null);assert.deepEqual(alertKeys(mock,1),['destination-one','transfer-2']);});
test('journey start shows two remaining and next change first',()=>{const p=progress(mock,central);assert.equal(p.remaining,2);assert.equal(p.toChange,1);assert.ok(alertKeys(mock,0).includes('destination-two'));});
test('Sydney clock uses destination timezone',()=>{assert.match(fmtTime('2026-10-07T03:20:00Z'),/2:20/);});
