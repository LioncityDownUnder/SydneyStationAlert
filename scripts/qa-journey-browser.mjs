/**
 * Chromium end-to-end commuter workflows against the built QA site.
 * All transport responses are deterministic fixtures; NO live TfNSW calls,
 * geolocation simulation or production writes occur here.
 *
 * Start the static QA build (as deploy-qa.yml does), then run:
 *   node scripts/qa-journey-browser.mjs
 */
import assert from 'node:assert/strict';
import { chromium } from 'playwright';

const base = process.env.QA_BROWSER_URL || 'http://127.0.0.1:8765/qatest/';
if (!new URL(base).pathname.startsWith('/qatest/')) {
  throw new Error('Journey browser tests must use the /qatest/ path');
}
const ACTIVE_KEY = 'sydstnalert:qa:active-trip:v1';
const START = Date.now() + 30 * 60000;
const when = minutes => new Date(START + minutes * 60000).toISOString();
const stations = [
  {id:'222010',name:'Hurstville',mode:'train',lat:-33.9670,lon:151.1030},
  {id:'221710',name:'Kogarah',mode:'train',lat:-33.9620,lon:151.1320},
  {id:'220510',name:'Wolli Creek',mode:'train',lat:-33.9287,lon:151.1530},
  {id:'200010',name:'Central',mode:'train',lat:-33.8830,lon:151.2070},
  {id:'202010',name:'International Airport',mode:'train',lat:-33.9344,lon:151.1655},
  {id:'221110',name:'Revesby',mode:'train',lat:-33.9516,lon:151.0145},
  {id:'221310',name:'Padstow',mode:'train',lat:-33.9528,lon:151.0300}
];
const byName = Object.fromEntries(stations.map(s => [s.name,s]));
function stop(name,arrival,departure) {
  return {...byName[name],
    ...(arrival === null ? {} : {arrival:when(arrival)}),
    ...(departure === null ? {} : {departure:when(departure)})
  };
}
function leg(line,tripId,stops) {
  const first=stops[0],last=stops[stops.length-1];
  return {
    id:tripId,mode:'train',line,tripIds:[tripId],origin:first,destination:last,stops,
    path:stops.map(s => ({lat:s.lat,lon:s.lon})),
    departure:first.departure,arrival:last.arrival
  };
}
function directJourney() {
  const stops=[
    stop('Hurstville',null,0),
    stop('Kogarah',5,5),
    stop('Wolli Creek',12,12),
    stop('Central',26,null)
  ];
  return {
    id:'browser-direct-t4',origin:stops[0],destination:stops.at(-1),
    stops,transfers:[],legs:[leg('T4','browser-t4-central',stops)],
    fetchedAt:new Date().toISOString(),serviceStatus:{level:'ok'}
  };
}
function interchangeJourney() {
  const stops=[
    stop('Hurstville',null,0),
    stop('Kogarah',5,5),
    stop('Wolli Creek',12,21),
    stop('International Airport',29,29),
    stop('Revesby',49,49),
    stop('Padstow',54,null)
  ];
  return {
    id:'browser-interchange-t4-t8',origin:stops[0],destination:stops.at(-1),
    stops,transfers:[stops[2]],
    legs:[
      leg('T4','browser-t4-wolli',stops.slice(0,3)),
      leg('T8','browser-t8-padstow',stops.slice(2))
    ],
    fetchedAt:new Date().toISOString(),serviceStatus:{level:'ok'}
  };
}
async function browserScenario(name,action) {
  const context=await browser.newContext({timezoneId:'Australia/Sydney'});
  const page=await context.newPage();
  const errors=[];
  let journeyRequests=0;
  page.on('pageerror',error => errors.push(error.message));
  // Deterministic browser geolocation and notification spies. Nothing is sent
  // to the operating system or production. No fix is emitted automatically.
  await page.addInitScript(() => {
    const watchers=new Map();
    let nextWatch=1;
    Object.defineProperty(navigator,'geolocation',{configurable:true,value:{
      watchPosition(callback){const id=nextWatch++;watchers.set(id,callback);return id;},
      clearWatch(id){watchers.delete(id);}
    }});
    window.__emitGps=({lat,lon,accuracy=12,timestamp=Date.now()})=>{
      const position={timestamp,coords:{latitude:lat,longitude:lon,accuracy}};
      for(const callback of watchers.values())callback(position);
    };
    window.__notifications=[];
    class MockNotification {
      static permission='granted';
      static requestPermission=async()=> 'granted';
      constructor(title,options){window.__notifications.push({title,body:options?.body,tag:options?.tag});}
    }
    Object.defineProperty(window,'Notification',{configurable:true,value:MockNotification});
  });
  await page.route('**/api/index.php?*',async route=>{
    const url=new URL(route.request().url());
    const q=url.searchParams;
    const response=data=>route.fulfill({
      status:200,contentType:'application/json',body:JSON.stringify({data})
    });
    switch(q.get('action')) {
      case 'station-index': return response(stations);
      case 'stations': return response(stations.filter(s=>
        s.name.toLowerCase().includes((q.get('q')||'').toLowerCase())));
      case 'journey': {
        journeyRequests++;
        const from=q.get('from'),to=q.get('to');
        assert.equal(from,byName.Hurstville.id);
        if(to===byName.Central.id)return response(directJourney());
        if(to===byName.Padstow.id)return response(interchangeJourney());
        return route.fulfill({status:404,contentType:'application/json',
          body:JSON.stringify({error:{code:'NO_ROUTE',message:'Fixture route absent'}})});
      }
      case 'crowding': return response({available:false,level:'unknown',legs:[]});
      case 'boarded-timetable': return response({status:'unavailable'});
      case 'onward-options': return response({options:[]});
      default: throw new Error('Unexpected mocked transport action: '+q.get('action'));
    }
  });
  try {
    await page.goto(base,{waitUntil:'domcontentloaded'});
    await action(page);
    assert.equal(errors.length,0,'Browser JavaScript errors: '+errors.join('; '));
    assert.ok(journeyRequests>=1,'Journey search should call the mocked API');
    console.log('PASS Chromium journey: '+name);
  } finally {
    await context.close();
  }
}
async function selectRoute(page,destination) {
  await page.locator('#origin').fill('Hurstville');
  await page.getByRole('option',{name:/Hurstville/}).click();
  await page.locator('#destination').fill(destination);
  await page.getByRole('option',{name:new RegExp(destination)}).click();
  await page.locator('#set').click();
  await page.getByText('JOURNEY MONITOR').waitFor({timeout:12000});
  const title=await page.locator('.journeyhead h1').innerText();
  assert.match(title,/Hurstville/);
  assert.ok(title.includes(destination),title);
}
async function board(page) {
  await page.locator('#board').click();
  await page.locator('.pill').filter({hasText:'ON BOARD'}).waitFor();
  assert.ok(await page.evaluate(key=>localStorage.getItem(key)!==null,ACTIVE_KEY),
    'Boarded trip must be saved locally');
}
async function persisted(page) {
  return page.evaluate(key=>{
    const raw=localStorage.getItem(key);
    return raw?JSON.parse(raw):null;
  },ACTIVE_KEY);
}

const browser=await chromium.launch({headless:true});
try {
  await browserScenario('Hurstville–Central boarding and hard refresh',async page=>{
    await selectRoute(page,'Central');
    assert.equal(await page.locator('.route .stop').count(),4);
    await board(page);
    assert.equal((await persisted(page)).journey.id,'browser-direct-t4');
    await page.reload({waitUntil:'domcontentloaded'});
    await page.locator('.pill').filter({hasText:'ON BOARD'}).waitFor();
    assert.equal(await page.locator('.route .stop').count(),4);
    assert.equal((await persisted(page)).journey.id,'browser-direct-t4');
    assert.equal(await page.locator('#board').count(),0,
      'Restored journey must not require boarding again');
  });

  await browserScenario('Interchange confirmation and hard refresh',async page=>{
    await selectRoute(page,'Padstow');
    assert.equal(await page.locator('.route .stop').count(),6);
    await board(page);
    await page.getByText('Connecting train not yet confirmed').waitFor();
    const confirm=page.locator('[data-confirm-onward]');
    assert.equal(await confirm.count(),1);
    page.once('dialog',dialog=>dialog.dismiss());
    await confirm.click();
    await page.getByText('Connecting train not yet confirmed').waitFor();
    assert.deepEqual((await persisted(page)).journey.qaConfirmedTransfers||[],
      [],'Cancelling must not confirm the transfer');
    page.once('dialog',dialog=>dialog.accept());
    await confirm.click();
    await page.getByText('Connecting train not yet confirmed').waitFor({state:'hidden'});
    assert.deepEqual((await persisted(page)).journey.qaConfirmedTransfers,
      [byName['Wolli Creek'].id]);
    await page.reload({waitUntil:'domcontentloaded'});
    await page.locator('.pill').filter({hasText:'ON BOARD'}).waitFor();
    assert.equal(await page.locator('[data-confirm-onward]').count(),0,
      'Confirmed transfer must stay confirmed after reload');
    assert.equal(await page.getByText('Connecting train not yet confirmed').count(),0);
    assert.deepEqual((await persisted(page)).journey.qaConfirmedTransfers,
      [byName['Wolli Creek'].id]);
  });

  await browserScenario('GPS freshness, non-regression and notifications survive refresh',async page=>{
    await selectRoute(page,'Central');
    const emit=async (station,accuracy=12,timestamp=Date.now())=>
      page.evaluate(({lat,lon,accuracy,timestamp})=>
        window.__emitGps({lat,lon,accuracy,timestamp}),
        {lat:byName[station].lat,lon:byName[station].lon,accuracy,timestamp});
    const notices=()=>page.evaluate(()=>window.__notifications.slice());
    await emit('Kogarah');
    assert.equal((await notices()).length,0,'GPS must not alert before boarding');
    await board(page);
    await page.getByText('2 stops to Central').waitFor();
    assert.match(await page.locator('.bigstatus').innerText(),/2\s+stops/);
    assert.deepEqual((await persisted(page)).journey.notifiedAlertKeys,['destination-two']);
    await emit('Central',999);
    assert.match(await page.locator('.bigstatus').innerText(),/2\s+stops/,
      'Inaccurate GPS must not advance the displayed stop count');
    await emit('Central',12,Date.now()-120000);
    assert.match(await page.locator('.bigstatus').innerText(),/2\s+stops/,
      'Stale GPS must not advance the displayed stop count');
    await emit('Wolli Creek');
    await page.getByText('1 stop to Central').waitFor();
    assert.match(await page.locator('.bigstatus').innerText(),/1\s+stop/);
    assert.deepEqual((await notices()).map(x=>x.body),
      ['2 stops to Central','1 stop to Central']);
    await emit('Kogarah');
    assert.match(await page.locator('.bigstatus').innerText(),/1\s+stop/,
      'Backwards GPS jitter must not regress confirmed station progress');
    const saved=await persisted(page);
    assert.equal(saved.journey.monitoringGpsIndex,2,
      'Wolli Creek should be the confirmed third stop (array index 2)');
    assert.deepEqual(saved.journey.notifiedAlertKeys,
      ['destination-two','destination-one']);
    await page.reload({waitUntil:'domcontentloaded'});
    await page.locator('.pill').filter({hasText:'ON BOARD'}).waitFor();
    assert.match(await page.locator('.bigstatus').innerText(),/1\s+stop/);
    await page.evaluate(({lat,lon})=>window.__emitGps({lat,lon}),{
      lat:byName['Wolli Creek'].lat,lon:byName['Wolli Creek'].lon
    });
    assert.equal((await notices()).length,0,'Refresh must not replay previously notified stops');
    assert.deepEqual((await persisted(page)).journey.notifiedAlertKeys,
      ['destination-two','destination-one']);
  });

  await browserScenario('Abandon trip clears local persistence',async page=>{
    await selectRoute(page,'Central');
    await board(page);
    await page.locator('#abandon').click();
    await page.locator('#origin').waitFor();
    assert.equal(await persisted(page),null,'Abandon must remove saved trip');
    await page.reload({waitUntil:'domcontentloaded'});
    await page.locator('#origin').waitFor();
    assert.equal(await persisted(page),null,'Abandon must survive hard reload');
    assert.equal(await page.locator('.journeyhead').count(),0);
  });
} finally {
  await browser.close();
}
