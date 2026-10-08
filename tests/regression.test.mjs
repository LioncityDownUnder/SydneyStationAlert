import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {progress, alertKeys, fmtTime} from '../dist/assets/logic.js';

const stop=(id,name,lat,lon,arrival=null,departure=null)=>({id,name,lat,lon,mode:'train',arrival,departure});
const a=stop('a','Origin',-33.9,151.1,null,'2026-10-09T23:45:00+11:00');
const b=stop('b','Interchange',-33.91,151.12,'2026-10-09T23:55:00+11:00','2026-10-10T00:05:00+11:00');
const c=stop('c','Destination',-33.92,151.14,'2026-10-10T00:20:00+11:00');
const route=(stops,transfers=[])=>({origin:stops[0],destination:stops.at(-1),stops,transfers});

test('direct journey counts only actual stopping stations',()=>{
 const bypass=stop('x','Express bypass',-33.905,151.11);
 const j=route([a,bypass,c]);
 assert.equal(progress(j,a).remaining,1);
 assert.equal(progress(j,bypass).remaining,1);
 assert.equal(progress(j,c).remaining,0);
 assert.deepEqual(alertKeys(j,0),['destination-one']);
});

test('interchange journey shows stops to change and destination',()=>{
 const j=route([a,b,c],[b]);
 assert.deepEqual({remaining:progress(j,a).remaining,toChange:progress(j,a).toChange},{remaining:2,toChange:1});
 assert.equal(progress(j,b).toChange,null);
 assert.ok(alertKeys(j,0).includes('transfer-b'));
});

test('overnight journey preserves calendar transition in time calculations',()=>{
 assert.ok(Date.parse(b.departure)>Date.parse(a.departure));
 assert.ok(Date.parse(c.arrival)>Date.parse(b.departure));
 assert.match(fmtTime(b.departure),/12:05/);
});

test('confirmed boarding only adopts updates for the same service',()=>{
 const main=fs.readFileSync('src/main.ts','utf8');
 assert.match(main,/function adoptJourneyUpdate\(updated\)/);
 assert.match(main,/if\(!sameService\(state\.journey,updated\)\)return false/);
 assert.match(main,/state\.onboard=true;saveActiveTrip\(\)/);
 assert.match(main,/const restoredTrip=loadActiveTrip\(\)/);
 assert.match(main,/clearActiveTrip\(\);stopTracking\(\)/);
});

test('missed connection recovery remains explicit and guarded',()=>{
 const main=fs.readFileSync('src/main.ts','utf8');
 assert.match(main,/async function recoverMissedConnection\(legIndex\)/);
 assert.match(main,/state\.recoveringConnection=true/);
 assert.match(main,/state\.recoveringConnection=false/);
 assert.match(main,/I missed my connection/);
 assert.match(main,/if\(generation!==journeyGeneration\|\|state\.journey!==original\)return/);
});

test('core journey fast path includes sixth probe without repeating it serially',()=>{
 const api=fs.readFileSync('api/index.php','utf8');
 assert.match(api,/if\(\$coreOnly&&count\(\$searchTimes\)>=6\)/);
 assert.match(api,/array_slice\(\$searchTimes,0,6\)/);
 assert.match(api,/array_slice\(\$searchTimes,6\)/);
});

test('additional journey prefetch is bounded and preserves sequential fallback',()=>{
 const api=fs.readFileSync('api/index.php','utf8');
 assert.match(api,/if\(\$coreOnly&&!\$route&&count\(\$remainingSearchTimes\)>=2\)/);
 assert.match(api,/array_slice\(\$remainingSearchTimes,0,2\)/);
 assert.match(api,/\$prefetchedBodies=timed_parallel_trip\(\$prefetchParams,25\)/);
 assert.match(api,/array_key_exists\(\$probeIndex,\$prefetchedBodies\)/);
 assert.match(api,/:timed_upstream\('trip',/);
});

test('verified core-only route skips redundant additional timetable probes',()=>{
 const api=fs.readFileSync('api/index.php','utf8');
 assert.match(api,/if\(\$coreOnly&&\$route\)\$remainingSearchTimes=\[\];/);
 assert.match(api,/foreach\(\$remainingSearchTimes as \$probeIndex=>\$probe\)/);
 assert.match(api,/if\(\$coreOnly&&!\$route&&count\(\$remainingSearchTimes\)>=2\)/);
});

test('unboarded departed service advances only to an upcoming journey',()=>{
 const main=fs.readFileSync('src/main.ts','utf8');
 assert.match(main,/if\(!state\.onboard\)\{/);
 assert.match(main,/oldDeparted&&!replacementIsUpcoming\)return false/);
 assert.match(main,/oldDeparted&&replacementIsUpcoming&&!sameService\(previous,updated\)/);
 assert.match(main,/if\(!sameService\(state\.journey,updated\)\)return false/);
 assert.match(main,/state\.alert='Your previous train has departed/);
});

test('missed connection detection requires fresh nearby GPS and a departure grace period',()=>{
 const main=fs.readFileSync('src/main.ts','utf8');
 assert.match(main,/Date\.now\(\)-state\.locationCheckedAt>90000/);
 assert.match(main,/depart\+5\*60000/);
 assert.match(main,/Math\.hypot\(lat,lon\)<=250/);
 assert.match(main,/if\(index<1\)return;/);
 assert.match(main,/use “I missed my connection”/);
 assert.match(main,/if\(!state\.onboard\|\|state\.checking\|\|!state\.journey\|\|legIndex<1\)return/);
});

test('onboard delay-aware connection warning uses arrival and departure with interchange buffer',()=>{
 const main=fs.readFileSync('src/main.ts','utf8');
 assert.match(main,/function connectionAtRisk\(j\)/);
 assert.match(main,/if\(!state\.onboard\|\|state\.paused/);
 assert.match(main,/arrival\+3\*60000>departure/);
 assert.match(main,/updateDelayConnectionWarning\(\);return true/);
 assert.match(main,/Connection at risk at /);
});

test('proactive onward lookup is bounded and preserves confirmed journey',()=>{
 const main=fs.readFileSync('src/main.ts','utf8');
 assert.match(main,/async function suggestOnwardConnection\(\)/);
 assert.match(main,/Date\.now\(\)-state\.lastSuggestionCheck<120000/);
 assert.match(main,/departure<risk\.arrival\+3\*60000/);
 assert.match(main,/state\.journey!==original\|\|!state\.onboard/);
 assert.match(main,/void suggestOnwardConnection\(\)/);
 assert.match(main,/Use “I missed my connection” to update your route/);
});
