// @ts-nocheck
import { searchStations, preloadStations, nearbyStations, getJourney, ApiRequestError } from './api.js';
import { GEO, REFRESH_MS, SEARCH_DEBOUNCE_MS, STATION_CACHE_MS } from './constants.js';
import { nearestStation, progress, alertKeys, fmtTime, escapeHTML as esc } from './logic.js';
const root = document.getElementById('app');
const state = { origin: null, destination: null, detected: false, manual: false, locating: false, location: null, journey: null, onboard: false, paused: false, busy: false, checking: false, recoveringConnection: false, suggestedConnection: null, suggestingConnection: false, lastSuggestionCheck: 0, enriching: false, message: '', alert: '', searchResults: [], activeField: null, searchLoading: false, searchQuery: '', searchError: '', highlightedIndex: -1, lastChecked: null, offline: !navigator.onLine, noRoute: false, noRouteAlerts: null, noRouteAlertsLoading: false };
let queryController = null;
let debounce;
let watchId = null;
let pollId;
const fired = new Set();
let journeyGeneration = 0;
let searchGeneration = 0;
const isQaEnvironment = /^\/qatest(?:\/|$)/.test(window.location.pathname);
const qaRailPilot = isQaEnvironment && new URLSearchParams(window.location.search).get('qaRailPilot') === '1';
const STATION_CACHE_KEY = isQaEnvironment ? 'sydstnalert:qa:stations:v1' : 'sydney-station-alert:stations:v1';
const ACTIVE_TRIP_KEY = isQaEnvironment ? 'sydstnalert:qa:active-trip:v1' : 'sydney-station-alert:active-trip:v1';
function activeTripExpiry(j){const arrival=j?.legs?.[j.legs.length-1]?.arrival;const ts=arrival?+new Date(arrival):NaN;return Number.isFinite(ts)?ts+4*60*60*1000:Date.now()+8*60*60*1000;}
function saveActiveTrip(){if(!state.onboard||!state.journey)return;try{localStorage.setItem(ACTIVE_TRIP_KEY,JSON.stringify({version:1,savedAt:Date.now(),expiresAt:activeTripExpiry(state.journey),journey:state.journey}));}catch{}}
function clearActiveTrip(){try{localStorage.removeItem(ACTIVE_TRIP_KEY);}catch{}}
function loadActiveTrip(){try{const raw=localStorage.getItem(ACTIVE_TRIP_KEY);if(!raw)return null;const p=JSON.parse(raw);if(!p||p.version!==1||!p.journey||!Array.isArray(p.journey.legs)||!Array.isArray(p.journey.stops)||Number(p.expiresAt)<=Date.now()){clearActiveTrip();return null;}return p.journey;}catch{clearActiveTrip();return null;}}
function sameService(a,b){if(!a||!b||!Array.isArray(a.legs)||!Array.isArray(b.legs)||a.legs.length!==b.legs.length)return false;return a.legs.every((leg,i)=>{const other=b.legs[i];const ids=(leg.tripIds||[]).map(x=>String(x).toLowerCase());const otherIds=(other?.tripIds||[]).map(x=>String(x).toLowerCase());if(ids.length&&otherIds.length)return ids.some(id=>otherIds.includes(id));return leg.line===other?.line&&leg.origin?.id===other?.origin?.id&&leg.destination?.id===other?.destination?.id;});}
function firstDepartureTime(j){return Date.parse(j?.legs?.[0]?.departure||j?.legs?.[0]?.origin?.departure||'');}
function adoptJourneyUpdate(updated){
 if(!state.onboard){
  const previous=state.journey;
  const oldDeparture=firstDepartureTime(previous),newDeparture=firstDepartureTime(updated);
  const oldDeparted=Number.isFinite(oldDeparture)&&oldDeparture<Date.now()-60000;
  const replacementIsUpcoming=Number.isFinite(newDeparture)&&newDeparture>=Date.now()-60000;
  // Never replace a displayed service with another service that has already left.
  if(oldDeparted&&!replacementIsUpcoming)return false;
  state.journey=updated;
  if(oldDeparted&&replacementIsUpcoming&&!sameService(previous,updated)){
   state.alert='Your previous train has departed. Showing the next available journey; confirm boarding only when on the displayed train.';
   fired.clear();
  }
  return true;
 }
 if(!sameService(state.journey,updated))return false;
 state.journey=updated;saveActiveTrip();updateDelayConnectionWarning();void suggestOnwardConnection();return true;
}

function loadStationCache() { try {
    const raw = localStorage.getItem(STATION_CACHE_KEY);
    if (!raw) return [];
    const parsed = JSON.parse(raw);
    if (!parsed || !Array.isArray(parsed.stations) || Date.now() - Number(parsed.savedAt) > STATION_CACHE_MS) {
        localStorage.removeItem(STATION_CACHE_KEY); return [];
    }
    return parsed.stations;
} catch { return []; } }
function saveStationCache(stations) { try { localStorage.setItem(STATION_CACHE_KEY, JSON.stringify({ savedAt: Date.now(), stations })); } catch {} }
let stationIndex = loadStationCache();
function localStationMatches(q) { const n = q.trim().toLocaleLowerCase(); if (n.length < 2) return []; return stationIndex.filter(s => s.name.toLocaleLowerCase().includes(n)).sort((a,b)=>{ const an=a.name.toLocaleLowerCase(),bn=b.name.toLocaleLowerCase(); const ap=an.startsWith(n)?0:1,bp=bn.startsWith(n)?0:1; return ap-bp||a.name.localeCompare(b.name); }).slice(0,12); }
async function warmStationIndex() { if (stationIndex.length) return; try { const stations=await preloadStations(); if(stations.length){stationIndex=stations;saveStationCache(stations);} } catch {} }
function mergeStationIndex(stations){ if(!stations.length)return; const map=new Map(stationIndex.map(s=>[s.id,s])); for(const s of stations)map.set(s.id,s); stationIndex=[...map.values()];saveStationCache(stationIndex); }
const brand=`<header class="top"><div class="brand"><div class="mark" aria-hidden="true">↗</div>Sydney Station Alert</div><div class="topmeta">SYDNEY TRAINS <span style="color:#d46d2c">/</span> METRO</div></header>`;
const footer=`<footer class="footer">Designed for Sydney commuters by Y.T. Ng</footer>`;
function message(text,warn=false){return text?`<p class="notice${warn?' warn':''}" role="status">${esc(text)}</p>`:'';}
function notificationReliabilityNotice(){
 const permission=('Notification' in window)?Notification.permission:'unsupported';
 const status=permission==='granted'?'Notifications enabled while the app is active.':permission==='denied'?'Notifications blocked in browser settings.':permission==='default'?'Notifications not yet enabled.':'Notifications unavailable in this browser.';
 return '<div class="notice" role="status"><strong>Alert reliability</strong><p>'+esc(status)+' Location and timetable checks may stop when your phone is locked or the browser is suspended. Keep the app open for best results; do not rely on it as your only stop reminder.</p></div>';
}
function serviceUpdate(j){const s=j?.serviceStatus;if(!s||s.level==='ok'||s.level==='unavailable'||!Array.isArray(s.alerts)||!s.alerts.length)return '';if(s.revalidationAttempted&&s.replacementFound){const via=Array.isArray(j.transfers)&&j.transfers.length?` via ${j.transfers.map(t=>t.name).filter(Boolean).join(' and ')}`:'';return '<div class="alarm" role="alert">⚠ '+esc(`Original service affected · showing the next valid route${via}.`)+'</div>';}const first=s.alerts[0];const title=first?.title||'TfNSW has issued a service update for this journey.';const text=`${s.level==='major'?'Service disruption':'Service update'}: ${title}`;return s.level==='major'||s.level==='warning'?'<div class="alarm" role="alert">⚠ '+esc(text)+'</div>':message(text);}
function serviceDetails(j){const s=j?.serviceStatus;if(!s||!Array.isArray(s.alerts)||!s.alerts.length)return '';const a=s.alerts[0];const note=a.materialChange?'Selected service may be affected.':'Broader line or station notice.';const body=(a.description||'').trim();return `<details class="notice"><summary>View disruption details</summary><div>${esc(note)}${body?`<p>${esc(body)}</p>`:''}</div></details>`;}
function crowdingSummary(j){const c=j?.crowding;if(!c?.available||!c.level||c.level==='unknown')return '';const hasCarriages=Array.isArray(c.legs)&&c.legs.some(l=>Array.isArray(l.carriages)&&l.carriages.some(car=>car.level&&car.level!=='unknown'));if(hasCarriages)return '';const labels={quiet:'Quiet',moderate:'Moderate',busy:'Busy',very_busy:'Very busy'};const label=labels[c.level];return label?`<div class="stateline">Crowding: ${esc(label)}</div>`:'';}
function carriageCrowding(j){const legs=j?.crowding?.legs;if(!Array.isArray(legs))return '';const labels={quiet:'Quiet',moderate:'Moderate',busy:'Busy',very_busy:'Very busy'};const leg=legs.find(l=>Array.isArray(l.carriages)&&l.carriages.some(c=>c.level&&c.level!=='unknown'));if(!leg)return '';const cars=leg.carriages.filter(c=>c.level&&c.level!=='unknown').sort((a,b)=>(Number(a.position)>0?Number(a.position):999)-(Number(b.position)>0?Number(b.position):999));if(!cars.length)return '';const trainType=leg.mode==='metro'?'Metro train':'train';return `<div class="stateline"><strong>Carriage crowding · ${esc(String(cars.length))}-car ${esc(trainType)}</strong></div><div class="crowding-bars" style="--car-count:${esc(String(cars.length))}" aria-label="Carriage crowding">${cars.map((car,i)=>{const label=labels[car.level]||'Unknown';const carNo=String(i+1);const code=car.name?` · TfNSW code ${car.name}`:'';return `<span class="crowding-bar crowding-bar-${esc(car.level)}" title="Car ${esc(carNo)}: ${esc(label)}${esc(code)}" aria-label="Car ${esc(carNo)}, ${esc(label)}${esc(code)}"></span>`;}).join('')}</div><div class="crowding-legend" aria-label="Crowding colour legend"><span><i class="crowding-dot crowding-dot-quiet"></i>Quiet</span><span><i class="crowding-dot crowding-dot-moderate"></i>Moderate</span><span><i class="crowding-dot crowding-dot-busy"></i>Busy</span></div>`;}
function qaNoRouteAlertDetails(){
 if(!isQaEnvironment||state.origin?.id!=='222010')return '';
 if(state.noRouteAlertsLoading)return '<p class="notice" role="status">Checking TfNSW disruption information…</p>';
 const assessment=state.noRouteAlerts?.sydneytrains?.assessment;
 if(!assessment)return '<p class="notice">Disruption information is unavailable. This does not mean trains are cancelled.</p>';
 const active=(assessment.matchingAlerts||[]).filter(a=>a.periodStatus==='WITHIN_ACTIVE_PERIOD');
 const details=active.slice(0,3).map(a=>'<details class="notice"><summary>'+esc(a.title||'TfNSW service notice')+'</summary><p>'+esc(a.description||'')+'</p><p>Contextual notice; impact on your selected journey is unverified.</p></details>').join('');
 return '<section aria-label="Service disruption information"><h3>Relevant TfNSW service notices</h3>'+(details||'<p class="notice">No matching active alert found. Train availability remains unconfirmed.</p>')+'<p class="muted">Replacement buses are informational only; their departures are not verified here.</p></section>';
}
async function loadQaNoRouteAlerts(generation){
 if(!isQaEnvironment||state.origin?.id!=='222010')return;
 state.noRouteAlertsLoading=true;setup();
 try{
  const response=await fetch('./api/diagnostics.php?action=probe_active_alerts',{cache:'no-store',signal:AbortSignal.timeout(18000)});
  if(!response.ok)throw new Error('Unavailable');
  const body=await response.json();
  if(generation===journeyGeneration&&state.noRoute)state.noRouteAlerts=body.data||null;
 }catch{if(generation===journeyGeneration)state.noRouteAlerts=null;}
 finally{if(generation===journeyGeneration){state.noRouteAlertsLoading=false;setup();}}
}
function qaRailPilotTravelLinks(){return qaRailPilot?'<p class="muted">Check <a href="https://transportnsw.info/alerts" target="_blank" rel="noopener noreferrer">TfNSW service alerts</a> or <a href="https://transportnsw.info/trip" target="_blank" rel="noopener noreferrer">TfNSW Trip Planner</a> for other transport arrangements.</p>':'';}
function routeUnavailableMessage(text){const limited=isQaEnvironment&&state.noRouteLimited;return text?`<section class="no-service" role="alert" aria-live="assertive"><div class="eyebrow">${limited?'SEARCH TIME LIMIT REACHED':'JOURNEY NOT FOUND'}</div><strong>${esc(text)}</strong><p>${limited?'We could not finish checking all possible trains. This does not confirm service cancellation. Check TfNSW Trip Planner for current options, or retry.':'We could not verify a train-only journey. This does not mean all trains are cancelled. Try searching again or choose a different destination.'}</p>${isQaEnvironment?qaNoRouteAlertDetails():''}${qaRailPilotTravelLinks()}${isQaEnvironment?'<button type="button" id="retry-no-route" class="secondary">Retry journey search</button>':''}</section>`:'';}
function highlightName(name,query){const i=name.toLocaleLowerCase().indexOf(query.toLocaleLowerCase());if(!query||i<0)return esc(name);return `${esc(name.slice(0,i))}<mark>${esc(name.slice(i,i+query.length))}</mark>${esc(name.slice(i+query.length))}`;}
function optionMarkup(s,i){return `<button id="station-option-${i}" role="option" aria-selected="${state.highlightedIndex===i}" type="button" data-pick="${i}"><span>${highlightName(s.name,state.searchQuery)}</span><small>${s.mode==='metro'?'Sydney Metro':'Sydney Trains'}</small></button>`;}
function stationField(field,label,placeholder){const chosen=state[field];if(chosen)return `<div class="field selected-field"><span class="fieldlabel">${esc(label)}</span><div class="selected-station"><div class="selected-copy"><strong>${esc(chosen.name)}</strong><span>${chosen.mode==='metro'?'Sydney Metro':'Sydney Trains'}</span></div><button type="button" class="change-station" id="edit-${field}" aria-label="Change ${esc(label.toLowerCase())}">Change</button></div></div>`;const open=state.activeField===field;const activeDesc=open&&state.highlightedIndex>=0?` aria-activedescendant="station-option-${state.highlightedIndex}"`:'';return `<div class="field"><label for="${field}">${label}</label><div class="input-shell"><input id="${field}" type="search" role="combobox" aria-autocomplete="list" aria-haspopup="listbox" aria-expanded="${open}" aria-controls="options-${field}"${activeDesc} autocomplete="off" autocapitalize="words" spellcheck="false" placeholder="${placeholder}"/></div><div id="options-${field}" class="searchlist" role="listbox" aria-label="${esc(label)} suggestions" ${open?'':'hidden'}></div></div>`;}
function setup(){const originField=stationField('origin','Boarding station','Start typing a station');const locateAction=!state.locating&&!state.detected?'<button class="detect-action" id="locate" type="button"><span class="detect-icon" aria-hidden="true">◎</span><span>Use my current station</span></button>':'';const detectedNote=state.detected&&state.origin?'<span class="detected-inline"><span aria-hidden="true">●</span> Detected near your location</span>':'';root.innerHTML=`<main class="shell">${brand}<section class="hero"><div class="hero-copy"><div class="eyebrow">YOUR JOURNEY, IN FOCUS</div><h1>Your stop.<br/>Right on time.</h1><p class="lead">Choose where you're going. We'll keep track of the stations ahead and let you know when it's time to get ready.</p></div><div class="panel setup-panel"><h2 class="sectiontitle">SET UP YOUR JOURNEY</h2><div class="boarding-group">${originField}<div class="origin-actions">${detectedNote}${state.locating?message('Finding your nearest station…'):locateAction}</div></div>${stationField('destination','Destination station','Start typing a station')}<button id="set" class="primary" ${!state.origin||!state.destination||state.origin.id===state.destination.id||state.busy?'disabled':''} ${state.busy?'aria-busy="true"':''}>${state.busy?'<span class="journey-button-loading"><span class="journey-spinner" aria-hidden="true"></span>Finding your train…</span>':'Set my journey'}</button>${state.busy?'<div class="journey-search-status" role="status" aria-live="polite"><span class="journey-search-pulse" aria-hidden="true"><span></span><span></span><span></span></span><span>Checking live services and connections. Please wait…</span></div>':''}${state.noRoute?routeUnavailableMessage(state.message):message(state.message,!!state.message)}</div></section>${footer}</main>${state.offline?offline():''}`;wireSetup();}
function resetSearch(){searchGeneration++;clearTimeout(debounce);queryController?.abort();queryController=null;state.searchResults=[];state.searchLoading=false;state.searchQuery='';state.searchError='';state.highlightedIndex=-1;}
function editField(field){state[field]=null;if(field==='origin'){state.detected=false;state.manual=true;}state.activeField=field;state.message='';state.noRoute=false;resetSearch();state.activeField=field;setup();queueMicrotask(()=>document.getElementById(field)?.focus());}
function wireSetup(){
 document.getElementById('set')?.addEventListener('click',setJourney);
 document.getElementById('retry-no-route')?.addEventListener('click',()=>void setJourney());
 document.getElementById('edit-origin')?.addEventListener('click',()=>editField('origin'));
 document.getElementById('edit-destination')?.addEventListener('click',()=>editField('destination'));
 document.getElementById('locate')?.addEventListener('click',locate);
 for(const field of ['origin','destination']){
  const el=document.getElementById(field);if(!el)continue;
  el.addEventListener('input',()=>{state[field]=null;state.activeField=field;state.searchResults=[];state.searchError='';state.highlightedIndex=-1;clearTimeout(debounce);queryController?.abort();const generation=++searchGeneration;const q=el.value.trim();state.searchQuery=q;const cached=localStationMatches(q);state.searchResults=cached;state.searchLoading=q.length>=2&&cached.length===0;syncButton();renderOptions(field);if(q.length<2){state.searchLoading=false;renderOptions(field);return;}if(cached.length){state.highlightedIndex=0;renderOptions(field);}debounce=window.setTimeout(async()=>{queryController=new AbortController();try{const result=await searchStations(q,queryController.signal);if(generation!==searchGeneration||state.activeField!==field||el.value.trim()!==q||state[field])return;mergeStationIndex(result);state.searchResults=result;state.searchLoading=false;state.highlightedIndex=result.length?0:-1;renderOptions(field);}catch(e){if(e.name!=='AbortError'&&generation===searchGeneration&&state.activeField===field&&!state[field]){state.searchLoading=false;state.searchError='Station search is temporarily unavailable. Please try again.';state.searchResults=[];state.highlightedIndex=-1;renderOptions(field);}}},SEARCH_DEBOUNCE_MS);});
  el.addEventListener('focus',()=>{state.activeField=field;state.searchQuery=el.value.trim();state.searchResults=localStationMatches(state.searchQuery);state.highlightedIndex=state.searchResults.length?0:-1;renderOptions(field);});
  el.addEventListener('keydown',e=>handleComboboxKey(e,field));
 }
 document.addEventListener('click',outsideOnce,{once:true});
}
function handleComboboxKey(e,field){if(state.activeField!==field)return;if(e.key==='Escape'){state.activeField=null;state.highlightedIndex=-1;renderOptions(field);return;}if(!state.searchResults.length)return;if(e.key==='ArrowDown'){e.preventDefault();state.highlightedIndex=(state.highlightedIndex+1)%state.searchResults.length;renderOptions(field);}else if(e.key==='ArrowUp'){e.preventDefault();state.highlightedIndex=(state.highlightedIndex-1+state.searchResults.length)%state.searchResults.length;renderOptions(field);}else if(e.key==='Enter'&&state.highlightedIndex>=0){e.preventDefault();pickStation(field,state.highlightedIndex);}}
function outsideOnce(e){const target=e.target;if(state.activeField&&!target.closest('.field')){state.activeField=null;state.highlightedIndex=-1;renderOptions('origin');renderOptions('destination');}else document.addEventListener('click',outsideOnce,{once:true});}
function renderOptions(field){const box=document.getElementById(`options-${field}`);if(!box)return;const open=state.activeField===field&&!state[field]&&state.searchQuery.length>=2;box.hidden=!open;const input=document.getElementById(field);input?.setAttribute('aria-expanded',String(open));if(!open){box.innerHTML='';return;}if(state.searchLoading&&!state.searchResults.length){box.innerHTML='<div class="searchstate searchloading"><span class="spinner" aria-hidden="true"></span>Finding stations…</div>';return;}if(state.searchError){box.innerHTML=`<div class="searchstate searcherror">${esc(state.searchError)}</div>`;return;}if(!state.searchResults.length){box.innerHTML='<div class="searchstate">No matching train or metro stations.</div>';return;}box.innerHTML=state.searchResults.map(optionMarkup).join('');box.querySelectorAll('[data-pick]').forEach(btn=>btn.addEventListener('mousedown',e=>{e.preventDefault();pickStation(field,Number(btn.dataset.pick));}));}
function pickStation(field,index){const chosen=state.searchResults[index];if(!chosen)return;const other=field==='origin'?state.destination:state.origin;if(other?.id===chosen.id){state.message='Boarding and destination stations must be different.';state.noRoute=false;return setup();}state[field]=chosen;if(field==='origin'){state.manual=true;state.detected=false;}resetSearch();state.activeField=null;state.message='';state.noRoute=false;setup();}
function syncButton(){const button=document.getElementById('set');if(button)button.disabled=!state.origin||!state.destination||state.origin.id===state.destination.id||state.busy;}
async function locate(){state.locating=true;state.message='';state.noRoute=false;setup();if(!navigator.geolocation){state.locating=false;state.manual=true;state.message='Location is not available in this browser. Choose your boarding station manually.';return setup();}navigator.geolocation.getCurrentPosition(async p=>{try{state.location={lat:p.coords.latitude,lon:p.coords.longitude};const stations=await nearbyStations(state.location);const nearest=nearestStation(stations,state.location);state.locating=false;if(nearest){state.origin=nearest;state.detected=true;state.manual=false;state.message='';}else{state.manual=true;state.message='We could not find a supported train or metro station nearby. Choose your boarding station manually.';}}catch{state.locating=false;state.manual=true;state.message='We could not check nearby stations just now. You can still choose your boarding station manually.';}setup();},()=>{state.locating=false;state.manual=true;state.message='Location permission was not available. Choose your boarding station manually.';setup();},GEO.options);}
function renderJourneyStable(){const x=window.scrollX,y=window.scrollY;renderJourney();requestAnimationFrame(()=>window.scrollTo(x,y));}
async function enrichJourney(generation){if(!state.journey)return;state.enriching=true;renderJourneyStable();const statusTimer=window.setTimeout(()=>{if(generation===journeyGeneration&&state.enriching){state.enriching=false;renderJourneyStable();}},5000);try{const updated=await getJourney(state.journey.origin,state.journey.destination,qaRailPilot);if(generation!==journeyGeneration||!state.journey)return;adoptJourneyUpdate(updated);state.lastChecked=new Date();state.message='';checkAlerts();}catch{}finally{clearTimeout(statusTimer);if(generation===journeyGeneration){state.enriching=false;renderJourneyStable();}}}
async function setJourney(){if(!state.origin||!state.destination||state.origin.id===state.destination.id)return;const generation=++journeyGeneration;state.busy=true;state.enriching=false;state.message='';state.noRoute=false;state.noRouteLimited=false;state.noRouteAlerts=null;state.noRouteAlertsLoading=false;setup();try{const j=await getJourney(state.origin,state.destination,true);if(generation!==journeyGeneration)return;state.journey=j;state.suggestedConnection=null;state.lastSuggestionCheck=0;state.onboard=false;clearActiveTrip();state.busy=false;state.paused=false;state.lastChecked=new Date();state.alert='';fired.clear();startTracking();startPolling();renderJourney();void enrichJourney(generation);}catch(e){if(generation!==journeyGeneration)return;state.busy=false;state.enriching=false;state.journey=null;state.noRoute=e instanceof ApiRequestError&&(e.code==='NO_ROUTE'||e.code==='SEARCH_TIME_LIMIT');state.noRouteLimited=e instanceof ApiRequestError&&e.code==='SEARCH_TIME_LIMIT';state.message=e.message;setup();if(state.noRoute)void loadQaNoRouteAlerts(generation);}}
function stopTracking(){if(watchId!==null&&navigator.geolocation)navigator.geolocation.clearWatch(watchId);watchId=null;clearInterval(pollId);pollId=undefined;}
function startTracking(){if(watchId!==null||!navigator.geolocation||state.paused)return;try{watchId=navigator.geolocation.watchPosition(p=>{state.location={lat:p.coords.latitude,lon:p.coords.longitude};checkAlerts();renderJourneyStable();},()=>{},{...GEO.options,maximumAge:20000});}catch{}}
function startPolling(){clearInterval(pollId);if(!state.paused)pollId=window.setInterval(()=>{if(!document.hidden&&navigator.onLine)void refresh();},REFRESH_MS);}
async function refresh(){if(!state.journey||state.checking||state.paused)return;const generation=journeyGeneration;state.checking=true;try{const updated=await getJourney(state.journey.origin,state.journey.destination,qaRailPilot);if(generation!==journeyGeneration)return;adoptJourneyUpdate(updated);state.lastChecked=new Date();state.message='';checkAlerts();}catch(e){state.message=`Unable to refresh live train details: ${e.message}`;}finally{if(generation===journeyGeneration){state.checking=false;renderJourneyStable();void suggestOnwardConnection();}}}
// A stale or distant GPS reading must never trigger a missed-connection warning.
function connectionAtRisk(j){
 if(!state.onboard||state.paused||!j||!Array.isArray(j.legs))return null;
 for(let i=1;i<j.legs.length;i++){
  const previous=j.legs[i-1],next=j.legs[i];
  const arrival=Date.parse(previous.destination?.arrival||previous.arrival||'');
  const departure=Date.parse(next.origin?.departure||next.departure||'');
  if(!Number.isFinite(arrival)||!Number.isFinite(departure))continue;
  // Three minutes is a conservative planning buffer, not a platform guarantee.
  if(arrival+3*60000>departure&&Date.now()<departure+45*60000)
   return {index:i,name:next.origin?.name||'the interchange',arrival,departure};
 }
 return null;
}
async function suggestOnwardConnection(){
 const risk=connectionAtRisk(state.journey);
 if(!risk||state.suggestingConnection||state.checking||!navigator.onLine||Date.now()-state.lastSuggestionCheck<120000)return;
 state.lastSuggestionCheck=Date.now();
 const original=state.journey,generation=journeyGeneration;
 const transfer=original.legs[risk.index]?.origin;
 if(!transfer)return;
 state.suggestingConnection=true;
 try{
  const onward=await getJourney(transfer,original.destination,true);
  if(generation!==journeyGeneration||state.journey!==original||!state.onboard)return;
  const departure=Date.parse(onward.legs?.[0]?.departure||onward.legs?.[0]?.origin?.departure||'');
  if(!Number.isFinite(departure)||departure<risk.arrival+3*60000)return;
  state.suggestedConnection={index:risk.index,departure,transfer:transfer.name,journeyId:original.id};
  state.alert='Connection at risk at '+transfer.name+'. A later onward service departing at '+fmtTime(new Date(departure).toISOString())+' is available. Use “I missed my connection” to update your route.';
 }catch{}finally{state.suggestingConnection=false;if(generation===journeyGeneration)renderJourneyStable();}
}
function updateDelayConnectionWarning(){
 const risk=connectionAtRisk(state.journey);
 if(!risk)return;
 state.alert='Connection at risk at '+risk.name+': the first train arrival leaves less than 3 minutes to change. Check the next onward service using “I missed my connection”.';
}
function checkAlerts(){updateDelayConnectionWarning();if(!state.journey||state.paused||!state.location)return;const p=progress(state.journey,state.location);if(!p.nearest)return;for(const key of alertKeys(state.journey,p.index)){if(fired.has(key))continue;fired.add(key);const text=key==='destination-two'?`2 stops to ${state.journey.destination.name}`:key==='destination-one'?`1 stop to ${state.journey.destination.name}`:`Change trains at ${state.journey.transfers.find(t=>'transfer-'+t.id===key)?.name??'the next interchange'}`;state.alert=text;if(Notification.permission==='granted')try{new Notification('Sydney Station Alert',{body:text,tag:state.journey.id+'-'+key});}catch{}}}
function displayedProgress(j){
 const gps=progress(j,state.location);
 if(!state.onboard)return progress(j,null);
 if(gps.nearest)return gps;
 const now=Date.now();let index=0;
 j.stops.forEach((s,i)=>{if(i>0&&i<j.stops.length-1&&!s.arrival&&!s.departure)return;const time=s.arrival||s.departure;const ts=time?Date.parse(time):NaN;if(Number.isFinite(ts)&&ts<=now)index=i;});
 const count=(from,to)=>j.stops.slice(from+1,to+1).filter((s,i)=>from+i+1===j.stops.length-1||s.arrival||s.departure).length;
 const next=(j.transfers||[]).map(t=>j.stops.findIndex(s=>s.id===t.id||s.name===t.name)).find(i=>i>index);
 return {index,remaining:count(index,j.stops.length-1),toChange:next===undefined?null:count(index,next),nearest:null};
}
function mapMarkup(j,p){const all=j.legs.flatMap(l=>l.path.length?l.path:l.stops.map(s=>({lat:s.lat,lon:s.lon})));if(!all.length)return '<p class="muted">Map geometry is unavailable. Follow the station list below.</p>';const coords=[...all,...(state.location?[state.location]:[])];const minLon=Math.min(...coords.map(s=>s.lon)),maxLon=Math.max(...coords.map(s=>s.lon)),minLat=Math.min(...coords.map(s=>s.lat)),maxLat=Math.max(...coords.map(s=>s.lat));const spanX=Math.max(.003,maxLon-minLon),spanY=Math.max(.003,maxLat-minLat);const x=lon=>35+(lon-minLon)/spanX*290;const y=lat=>250-(lat-minLat)/spanY*205;const legs=j.legs.map(l=>{const path=l.path.length?l.path:l.stops.map(s=>({lat:s.lat,lon:s.lon}));return `<polyline points="${path.map(q=>`${x(q.lon).toFixed(1)},${y(q.lat).toFixed(1)}`).join(' ')}" fill="none" stroke="${l.mode==='metro'?'#dc7b3d':'#566c61'}" stroke-width="4.5" stroke-linecap="round" stroke-linejoin="round"/>`;}).join('');const markers=j.stops.map((s,i)=>`${i===p.index?`<circle class="map-active-pulse" cx="${x(s.lon)}" cy="${y(s.lat)}" r="11" fill="none" stroke="#ef7a2d" stroke-width="2"/>`:''}<circle cx="${x(s.lon)}" cy="${y(s.lat)}" r="${i===p.index?7:i===j.stops.length-1?6:3.8}" fill="${i===p.index?'#ef7a2d':i===j.stops.length-1?'#202421':'#fff'}" stroke="${i===p.index?'#ef7a2d':'#66766d'}" stroke-width="2"/>`).join('');const position=state.location?`<circle cx="${x(state.location.lon)}" cy="${y(state.location.lat)}" r="10" fill="#ef7a2d" opacity=".18"/><circle cx="${x(state.location.lon)}" cy="${y(state.location.lat)}" r="4" fill="#ef7a2d"/>`:'';return `<svg class="map" role="img" aria-label="Rail route map with station positions and your current location when available" viewBox="0 0 360 280" preserveAspectRatio="xMidYMid meet"><rect x="0" y="0" width="360" height="280" fill="#f2f3ef"/>${legs}${markers}${position}<text x="14" y="270" class="maplabel">${esc(j.legs.length>1?'Rail segments shown separately ·':'Rail route ·')} ${esc(j.destination.name)}</text></svg>`;}
function sydneyDayLabel(value,now=new Date()){const time=new Date(value);if(!Number.isFinite(time.getTime()))return '';const day=new Intl.DateTimeFormat('en-CA',{timeZone:'Australia/Sydney',year:'numeric',month:'2-digit',day:'2-digit'});const current=day.format(now),target=day.format(time);if(target===current)return '';const tomorrow=new Date(now.getTime()+86400000);if(target===day.format(tomorrow))return 'Tomorrow, ';return new Intl.DateTimeFormat('en-AU',{timeZone:'Australia/Sydney',day:'numeric',month:'short'}).format(time)+', ';}
function datedTrainTime(value){return value?sydneyDayLabel(value)+fmtTime(value):'';}
function stopDisplayTime(s,i){const value=i===0?(s.departure||s.arrival):(s.arrival||s.departure);return datedTrainTime(value);}
function displayLineName(line){
 const raw=String(line||'').trim();const code=raw.toUpperCase();
 const names={SCO:'South Coast Line',SHL:'Southern Highlands Line',BMT:'Blue Mountains Line',CCN:'Central Coast & Newcastle Line',HUN:'Hunter Line',T1:'T1 North Shore & Western Line',T2:'T2 Inner West & Leppington Line',T3:'T3 Liverpool & Inner West Line',T4:'T4 Eastern Suburbs & Illawarra Line',T5:'T5 Cumberland Line',T6:'T6 Lidcombe & Bankstown Line',T7:'T7 Olympic Park Line',T8:'T8 Airport & South Line',T9:'T9 Northern Line',M1:'M1 Metro North West & Bankstown'};
 return names[code]||raw;
}
function transferDisplay(j,s){
 const index=j.legs.findIndex((leg,i)=>i>0&&(leg.origin?.id===s.id||leg.origin?.name===s.name));
 if(index<1)return '';
 const arriving=j.legs[index-1],connecting=j.legs[index];
 const arrival=arriving.destination?.arrival||arriving.arrival;
 const departure=connecting.origin?.departure||connecting.departure;
 const arrivalPlatform=arriving.destination?.platform;
 const departurePlatform=connecting.origin?.platform||connecting.platform;
 const format=(time,platform)=>[time?fmtTime(time):'Time unavailable',platform?'Platform '+platform:'Platform unavailable'].join(' · ');
 const wait=arrival&&departure?Math.round((new Date(departure)-new Date(arrival))/60000):NaN;
 const waitLabel=Number.isFinite(wait)&&wait>=0?' · '+wait+' min to change':'';
 return `<div class="transfer-detail"><small><span class="transfer-label">ARRIVE · ${esc(displayLineName(arriving.line))}</span>${esc(format(arrival,arrivalPlatform))}</small><small><span class="transfer-label">CONNECT · ${esc(displayLineName(connecting.line))}</span>${esc(format(departure,departurePlatform))}</small><span class="transfer">↗ Change trains here${esc(waitLabel)}</span></div>`;
}
async function recoverMissedConnection(legIndex){
 if(!state.onboard||state.checking||!state.journey||legIndex<1)return;
 const original=state.journey,prior=original.legs.slice(0,legIndex),transfer=original.legs[legIndex]?.origin;
 if(!transfer||!prior.length)return;
 const recoveryRequestedAt=Date.now();const generation=journeyGeneration;state.checking=true;state.recoveringConnection=true;state.message='Finding another train from '+transfer.name+'…';renderJourneyStable();
 try{
  const onward=await getJourney(transfer,original.destination,true);
  if(generation!==journeyGeneration||state.journey!==original)return;
  const departure=Date.parse(onward.legs?.[0]?.departure||onward.legs?.[0]?.origin?.departure||'');
  if(!Number.isFinite(departure)||departure<recoveryRequestedAt)throw new Error('No connection departing after your recovery request was verified. Please retry shortly.');
  const legs=[...prior,...onward.legs];
  const stops=[];for(const leg of legs)for(const station of leg.stops||[leg.origin,leg.destination]){if(!station)continue;const last=stops[stops.length-1];if(last&&(last.id===station.id||last.name===station.name)){stops[stops.length-1]={...last,...station,arrival:last.arrival||station.arrival,departure:station.departure||last.departure};}else stops.push(station);}
  const transfers=[];for(let i=1;i<legs.length;i++){const previous=legs[i-1].destination,current=legs[i].origin;transfers.push({id:current.id,name:current.name,mode:current.mode,lat:current.lat,lon:current.lon,arrivalPlatform:previous.platform,departurePlatform:current.platform});}
  state.suggestedConnection=null;state.journey={...original,legs,stops,transfers,id:original.id+'-recovered-'+Date.now(),fetchedAt:new Date().toISOString()};
  state.lastChecked=new Date();state.alert='New connection found from '+transfer.name+'. Check the updated boarding time and platform.';state.message='';fired.clear();saveActiveTrip();
 }catch(e){if(generation===journeyGeneration)state.message='Unable to find a replacement connection: '+(e?.message||'Please try again.');}
 finally{if(generation===journeyGeneration){state.checking=false;state.recoveringConnection=false;renderJourneyStable();}}
}
function renderJourney(){const j=state.journey;if(!j){setup();return;}const p=displayedProgress(j);const latest=state.lastChecked?fmtTime(state.lastChecked.toISOString()):'not yet';const stationItems=j.stops.map((s,i)=>{const transfer=transferDisplay(j,s);const time=stopDisplayTime(s,i);const bypassed=i>0&&i<j.stops.length-1&&!s.arrival&&!s.departure;const detail=[time,s.platform?`Platform ${s.platform}`:''].filter(Boolean).join(' · ');return `<li class="stop ${bypassed?'bypassed ':''}${i===p.index?'current':''} ${i===j.stops.length-1?'destination':''} ${i<p.index?'past':''}"><strong>${esc(s.name)}</strong>${transfer|| (bypassed?'<small>Does not stop</small>':detail?`<small>${esc(detail)}</small>`:'')}${transfer&&state.onboard?`<button type="button" class="secondary missed-connection-action" data-recover-leg="${j.legs.findIndex((leg,k)=>k>0&&(leg.origin?.id===s.id||leg.origin?.name===s.name))}" ${state.checking?"disabled":""}>${state.recoveringConnection?"Finding another train…":"I missed my connection"}</button>`:""}${i===p.index&&!bypassed?'<small>Nearest station</small>':''}${i===0?(state.onboard?'<small class="boarding-confirmed">✓ Boarded this selected train</small>':`<button id="board" class="secondary boarding-action" type="button">I boarded the ${esc(datedTrainTime(j.legs[0]?.departure||s.departure||s.arrival))} train</button><small>Confirm only when you are on this selected service.</small>`):''}</li>`;}).join('');root.innerHTML=`<main class="shell">${brand}<section class="journeyhead"><div class="eyebrow">JOURNEY MONITOR</div><h1>${esc(j.origin.name)} <span style="color:#e6762d">→</span> ${esc(j.destination.name)}</h1><div class="subtitle">${j.omittedNonRail?'Mixed-mode suggestion · rail portion':j.legs.length>1?`${j.legs.length-1} train change${j.legs.length>2?'s':''} required`:'Direct rail journey'} · ${j.legs.map(l=>esc(displayLineName(l.line))).join(' / ')}</div></section><div class="toolbar"><span class="pill">${state.onboard?'● ON BOARD':state.paused?'MONITORING PAUSED':'● MONITORING'}</span><button id="refresh" class="secondary" ${state.checking?'disabled':''}>↻ Refresh</button><button id="pause" class="secondary">${state.paused?'Resume':'Pause'}</button><button id="${state.onboard?'abandon':'new'}" class="subtle">${state.onboard?'Abandon trip':'New journey'}</button></div>${state.alert?`<div class="alarm" role="alert">● ${esc(state.alert)}</div>`:''}${notificationReliabilityNotice()}${serviceUpdate(j)}${serviceDetails(j)}${message(state.message,true)}${j.omittedNonRail?message(`TfNSW suggested a mixed-mode journey. Non-rail legs are omitted from this display.${j.railStart&&!j.railStartsAtOrigin?` The rail portion begins at ${esc(j.railStart.name)}.`:''} Rail stops and map geometry show trains and metro only.`):''}<section class="panel"><div class="statusrow"><div><div class="eyebrow">REMAINING</div><div class="bigstatus">${p.remaining} <small>${j.omittedNonRail&&!j.railStartsAtOrigin?'rail ':''}stop${p.remaining===1?'':'s'} ${j.omittedNonRail&&!j.railStartsAtOrigin?'shown to destination':'to destination'}</small></div>${crowdingSummary(j)}${carriageCrowding(j)}${p.toChange!==null?`<div class="stateline" style="color:#ae5d2a;font-weight:720">${p.toChange} stop${p.toChange===1?'':'s'} to change · ${p.remaining} to ${esc(j.destination.name)}</div>`:''}</div><div class="muted ${state.checking?'checking':''}">${state.enriching?'Checking live service details…':`Last checked ${latest}`}</div></div><div class="divider"></div><div class="layout"><section><div class="eyebrow">STATIONS AHEAD</div><ol class="route">${stationItems}</ol></section><div class="sidecards"><section class="tinycard"><div class="eyebrow">YOUR ROUTE</div>${mapMarkup(j,p)}<div class="muted" style="margin-top:10px">${state.location?'Orange dot marks your location.':'Allow location access to see your position.'} ${j.legs.some(l=>!l.path.length)?'Some segments use station coordinates where TfNSW geometry is missing.':''}</div></section></div></div></section>${footer}</main>${state.offline?offline():''}`;document.getElementById('refresh')?.addEventListener('click',()=>void refresh());document.getElementById('pause')?.addEventListener('click',()=>{state.paused=!state.paused;if(state.paused)stopTracking();else{startTracking();startPolling();}renderJourneyStable();});root.querySelectorAll('[data-recover-leg]').forEach(button=>button.addEventListener('click',()=>{const leg=Number(button.dataset.recoverLeg);const station=state.journey?.legs?.[leg]?.origin?.name||'this interchange';if(window.confirm('Confirm missed connection at '+station+'?\n\nSelect OK to find another train and update your journey, or Cancel to keep your current journey.'))void recoverMissedConnection(leg);}));document.getElementById('board')?.addEventListener('click',()=>{state.onboard=true;saveActiveTrip();renderJourneyStable();});document.getElementById('abandon')?.addEventListener('click',()=>{clearActiveTrip();stopTracking();journeyGeneration++;state.onboard=false;state.enriching=false;state.journey=null;state.message='';state.noRoute=false;state.alert='';state.origin=null;state.destination=null;state.activeField=null;state.searchResults=[];setup();});document.getElementById('new')?.addEventListener('click',()=>{clearActiveTrip();stopTracking();journeyGeneration++;state.onboard=false;state.enriching=false;state.journey=null;state.message='';state.noRoute=false;state.alert='';state.activeField=null;state.searchResults=[];setup();});}
function offline(){return '<div class="offline" role="status">You’re offline. Showing the most recently loaded journey details.</div>';}
window.addEventListener('online',()=>{state.offline=false;state.journey?void refresh():setup();});
window.addEventListener('offline',()=>{state.offline=true;state.journey?renderJourney():setup();});
window.addEventListener('beforeunload',stopTracking);
document.addEventListener('visibilitychange',()=>{if(!document.hidden&&state.journey&&!state.paused){startTracking();checkAlerts();renderJourneyStable();void refresh();}});
window.addEventListener('pageshow',()=>{if(state.journey&&!state.paused){startTracking();checkAlerts();renderJourneyStable();}});

if('Notification' in window&&Notification.permission==='default'){document.addEventListener('click',e=>{if(e.target.id==='set')void Notification.requestPermission().catch(()=>{});},{capture:true});}
const restoredTrip=loadActiveTrip();
if(restoredTrip){
  state.journey=restoredTrip;
  state.origin=restoredTrip.origin;
  state.destination=restoredTrip.destination;
  state.onboard=true;
  state.paused=false;
  state.lastChecked=new Date(restoredTrip.fetchedAt||Date.now());
  journeyGeneration++;
  startTracking();
  startPolling();
  renderJourney();
  if(navigator.onLine)void refresh();
}else{
  setup();
}
void warmStationIndex();
