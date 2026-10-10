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
 assert.match(api,/array_slice\(\$searchTimes,0,\$qaRailPilot\?7:6\)/);
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

test('GPS missed-connection inference is removed without removing manual recovery',()=>{
 const main=fs.readFileSync('src/main.ts','utf8');
 assert.doesNotMatch(main,/suspectedMissedConnection|updateConnectionWarning|locationCheckedAt/);
 assert.match(main,/async function recoverMissedConnection\(legIndex\)/);
 assert.match(main,/function connectionAtRisk\(j\)/);
 assert.match(main,/function checkAlerts\(\)\{updateDelayConnectionWarning\(\);/);
});

test('onboard delay-aware connection warning uses arrival and departure with interchange buffer',()=>{
 const main=fs.readFileSync('src/main.ts','utf8');
 assert.match(main,/function connectionAtRisk\(j\)/);
 assert.match(main,/if\(!state\.onboard\|\|state\.paused/);
 assert.match(main,/arrival\+3\*60000>departure/);
 assert.match(main,/updateDelayConnectionWarning\(\);void suggestOnwardConnection\(\);return true/);
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

test('background alert limitations are disclosed and foreground checks resume',()=>{
 const main=fs.readFileSync('src/main.ts','utf8');
 assert.match(main,/function notificationReliabilityNotice\(\)/);
 assert.match(main,/Location and timetable checks may stop when your phone is locked/);
 assert.match(main,/\$\{notificationReliabilityNotice\(\)\}/);
 assert.match(main,/visibilitychange.*startTracking\(\);checkAlerts\(\);renderJourneyStable\(\);void refresh\(\)/);
 assert.match(main,/addEventListener\('pageshow'/);
});

test('missed connection recovery stays on interchange stops without duplicate top panel',()=>{
 const main=fs.readFileSync('src/main.ts','utf8');
 assert.doesNotMatch(main,/missedConnectionActions|connection-recovery/);
 assert.match(main,/transfer&&state.onboard/);
 assert.match(main,/data-recover-leg/);
 assert.match(main,/window\.confirm\('Confirm missed connection at '/);
});

test('late missed connection recovery requires departure after request',()=>{
 const main=fs.readFileSync('src/main.ts','utf8');
 assert.match(main,/const recoveryRequestedAt=Date\.now\(\)/);
 assert.match(main,/departure<recoveryRequestedAt/);
 assert.match(main,/window\.confirm\('Confirm missed connection at '/);
 assert.match(main,/transfer&&state\.onboard/);
});

test('core routing compares all initial probe candidates',()=>{
 const api=fs.readFileSync('api/index.php','utf8');
 assert.match(api,/\$route=better_route\(\$route,\$candidate\)/);
 assert.doesNotMatch(api,/if\(\$candidate\)\{\$route=\$candidate;break;\}/);
});

test('QA route diagnostics includes read-only candidate trace and build output',()=>{
 const html=fs.readFileSync('public/diagnostics.html','utf8');
 const php=fs.readFileSync('api/diagnostics.php','utf8');
 const pkg=fs.readFileSync('package.json','utf8');
 assert.match(html,/Find journey/);
 assert.match(html,/Full raw TfNSW response/);
 assert.match(html,/Download complete diagnostic JSON/);
 assert.match(php,/\/qatest\/api\//);
 assert.match(php,/diagnostic_candidate/);
 assert.match(php,/rawResponse/);
 assert.match(pkg,/cp public\/diagnostics\.html dist\/diagnostics\.html/);
});

test('Step 5X no-route notices are QA-only and retry is actionable',()=>{
 const main=fs.readFileSync('src/main.ts','utf8');
 assert.match(main,/function qaNoRouteAlertDetails\(\)/);
 assert.match(main,/if\(!isQaEnvironment\|\|state\.origin\?\.id!=='222010'\)return ''/);
 assert.match(main,/periodStatus==='WITHIN_ACTIVE_PERIOD'/);
 assert.match(main,/esc\(a\.title\|\|'TfNSW service notice'\)/);
 assert.match(main,/Contextual notice; impact on your selected journey is unverified/);
 assert.match(main,/state\.noRoute=e instanceof ApiRequestError&&\(e\.code==='NO_ROUTE'\|\|e\.code==='SEARCH_TIME_LIMIT'\)/);
 assert.match(main,/if\(state\.noRoute\)void loadQaNoRouteAlerts\(generation\)/);
 assert.match(main,/id="retry-no-route"/);
 assert.match(main,/getElementById\('retry-no-route'\)\?\.addEventListener\('click',\(\)=>void setJourney\(\)\)/);
 assert.match(main,/if\(generation===journeyGeneration&&state\.noRoute\)/);
 assert.match(main,/Disruption information is unavailable/);
});
test('Step 5X refuses to construct replacement-bus journeys from advisory text',()=>{
 const main=fs.readFileSync('src/main.ts','utf8');
 assert.match(main,/Replacement buses are informational only/);
 assert.match(main,/state\.noRouteAlertsLoading=true;setup\(\)/);
 assert.match(main,/state\.noRouteAlertsLoading=false;setup\(\)/);
});

test('Step 5AB QA-only search budget is explicit and does not affect production journey search',()=>{
 const api=fs.readFileSync('api/index.php','utf8');
 const core=fs.readFileSync('api/lib/core.php','utf8');
 assert.match(api,/\$qaBoundedSearch=\$coreOnly&&str_contains/);
 assert.match(api,/\$qaSearchDeadline=\$qaBoundedSearch\?microtime\(true\)\+22\.0:INF/);
 assert.match(api,/if\(\$qaBoundedSearch&&microtime\(true\)>=\$qaSearchDeadline\)/);
 assert.match(api,/No train-only journey was verified within the QA search time limit/);
 assert.match(api,/journey_search_qa_diagnostics_header\(\$qaSearchDiagnostics\)/);
 assert.match(core,/time_budget_exceeded/);
});

test('Step 5AC makes incomplete QA searches distinct from confirmed no-route responses',()=>{
 const main=fs.readFileSync('src/main.ts','utf8');
 const api=fs.readFileSync('src/api.ts','utf8');
 const backend=fs.readFileSync('api/index.php','utf8');
 assert.match(backend,/fail\(\$qaSearchBudgetExceeded\?'SEARCH_TIME_LIMIT':'NO_ROUTE'/);
 assert.match(main,/SEARCH TIME LIMIT REACHED/);
 assert.match(main,/We could not finish checking all possible trains/);
 assert.match(main,/state\.noRouteLimited=e instanceof ApiRequestError&&e\.code==='SEARCH_TIME_LIMIT'/);
 assert.match(api,/32000 : 20000/);
});

test('Step 5AH opt-in filtered initial journey search is QA-only',()=>{
 const backend=fs.readFileSync('api/index.php','utf8');
 const api=fs.readFileSync('src/api.ts','utf8');
 assert.match(backend,/\$qaRailPilot=\$qaBoundedSearch&&\(string\)\(\$_GET\['qaRailPilot'\]/);
 assert.match(backend,/if\(\$qaRailPilot\)\$fastParams=array_map\('qa_rail_filter_params',\$fastParams\)/);
 assert.match(backend,/timed_normalized_journey\(\$body,\$originSeed,\$destinationSeed\)/);
 assert.match(api,/new URLSearchParams\(window\.location\.search\)\.get\('qaRailPilot'\)/);
 assert.match(api,/qaRailPilot:'1'/);
});

test('Step 5AI QA pilot rejects departed first-leg services and labels overnight departures',()=>{
 const backend=fs.readFileSync('api/index.php','utf8');
 const main=fs.readFileSync('src/main.ts','utf8');
 assert.match(backend,/\$qaRailPilot\?qa_rail_route_in_window\(\$body,\$originSeed,\$destinationSeed,\$now->getTimestamp\(\),\$qaRailLatestDeparture\)/);
 assert.match(backend,/if\(\$qaRailPilot\)\$route=\$qaRouteWithinWindow\(\$route\)/);
 assert.match(main,/function sydneyDayLabel\(value,now=new Date\(\)\)/);
 assert.match(main,/timeZone:'Australia\/Sydney'/);
 assert.match(main,/return 'Tomorrow, '/);
 assert.match(main,/I boarded the \$\{esc\(datedTrainTime\(/);
});

test('Step 5AJ QA pilot applies four-hour window, strict leg times and official travel links',()=>{
 const api=fs.readFileSync('api/index.php','utf8');
 const qa=fs.readFileSync('api/lib/qa_route_rejections.php','utf8');
 const ui=fs.readFileSync('src/main.ts','utf8');
 assert.match(api,/\$qaRailLatestDeparture=\$qaRailPilot\?\$now->getTimestamp\(\)\+4\*3600/);
 assert.match(api,/\$fastProbes=array_slice\(\$searchTimes,0,\$qaRailPilot\?7:6\)/);
 assert.match(api,/if\(!\$route&&!\$qaRailPilot\)/);
 assert.match(api,/No train-only journey departing .*in the next 4 hours/);
 assert.match(qa,/function qa_rail_chronology_valid\(array \$route\):bool/);
 assert.match(qa,/\$previousArrival\+120/);
 assert.match(ui,/function qaRailPilotTravelLinks\(\)/);
 assert.match(ui,/transportnsw\.info\/alerts/);
 assert.match(ui,/transportnsw\.info\/trip/);
});

test('Step 5AL diagnostic is fixed QA-only and leaves commuter search unchanged',()=>{
 const diag=fs.readFileSync('api/diagnostics.php','utf8');
 const workflow=fs.readFileSync('.github/workflows/deploy-qa.yml','utf8');
 assert.match(diag,/str_starts_with\(\(string\)\(\$_SERVER\['SCRIPT_NAME'\]/);
 assert.match(diag,/probe_four_hour_search_comparison/);
 assert.match(diag,/\$offsets=\[0,60,180\]/);
 assert.match(diag,/\$pairs=\[\['Hurstville','Padstow'\]/);
 assert.match(diag,/QA_FOUR_HOUR_PROBE_COMPARISON/);
 assert.match(workflow,/step5al-four-hour-comparison\.json/);
 assert.match(workflow,/requestCount==12/);
});

test('Step 5AM restricts origin departure-board diagnosis to fixed QA station and four hours',()=>{
 const diag=fs.readFileSync('api/diagnostics.php','utf8');
 const workflow=fs.readFileSync('.github/workflows/deploy-qa.yml','utf8');
 assert.match(diag,/probe_hurstville_four_hour_departures/);
 assert.match(diag,/\$start\+14400/);
 assert.match(diag,/foreach\(\[0,120\] as \$offset\)/);
 assert.match(diag,/name_dm'=>'222010'/);
 assert.match(diag,/QA_HURSTVILLE_FOUR_HOUR_DEPARTURE_BOARD/);
 assert.match(workflow,/step5am-hurstville-departures\.json/);
});

test('Step 5AP rail-filter comparison is fixed, QA-only and informational',()=>{
 const diag=fs.readFileSync('api/diagnostics.php','utf8');
 const workflow=fs.readFileSync('.github/workflows/deploy-qa.yml','utf8');
 assert.match(diag,/probe_hurstville_rail_departure_filters/);
 assert.match(diag,/name_dm'=>'222010'/);
 assert.match(diag,/QA_HURSTVILLE_RAIL_FILTER_COMPARISON/);
 assert.match(diag,/railWithinFourHours/);
 assert.match(diag,/exclMOT_5/);
 assert.match(workflow,/step5ap-rail-filters\.json/);
 assert.match(workflow,/requestCount==3/);
});

test('Step 5AQ station identity and alert diagnostic is QA-only and read-only',()=>{
 const diag=fs.readFileSync('api/diagnostics.php','utf8');
 const wf=fs.readFileSync('.github/workflows/deploy-qa.yml','utf8');
 assert.match(diag,/probe_hurstville_identity_alerts/);
 assert.match(diag,/QA_HURSTVILLE_IDENTITY_ALERT_CHECK/);
 assert.match(diag,/count\(\$stations\)>=6/);
 assert.match(diag,/configuredStationId'=>'222010'/);
 assert.match(wf,/step5aq-identity-alerts\.json/);
});

test('Step 5AS QA rail pilot highlights only confirmed active contextual alerts on no-route',()=>{
 const ui=fs.readFileSync('src/main.ts','utf8');
 assert.match(ui,/periodStatus==='WITHIN_ACTIVE_PERIOD'/);
 assert.match(ui,/qaRailPilot&&state\.noRoute&&!state\.noRouteLimited&&active\.length/);
 assert.match(ui,/Trackwork may affect travel; this does not confirm cancellation/);
 assert.match(ui,/Contextual notice; impact on your selected journey is unverified/);
 assert.match(ui,/https:\/\/transportnsw\.info\/alerts/);
 assert.match(ui,/https:\/\/transportnsw\.info\/trip/);
});
