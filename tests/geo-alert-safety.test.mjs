import test from 'node:test';
import assert from 'node:assert/strict';
import {
 GPS_FIX_MAX_AGE_MS, GPS_MAX_ACCURACY_METRES, GPS_STATION_RADIUS_METRES,
 locationIsFresh, reliableGpsFix, nearbyGpsStop, progressAtIndex, savedAlertKeys
} from '../dist/assets/geo-alert-safety.js';

const now=Date.parse('2026-10-21T03:00:00+11:00');
const fix=(lat,lon,accuracy=12,timestamp=now)=>({
 timestamp,coords:{latitude:lat,longitude:lon,accuracy}
});
const p=(name,id,lat,lon,time)=>({
 name,id,lat,lon,...(time?{arrival:new Date(now+time*60000).toISOString()}:{})
});
const stations=[
 p('Hurstville','A',-33.967,151.103,0),
 p('Bypassed','X',-33.964,151.118,null),
 p('Kogarah','B',-33.962,151.132,5),
 p('Wolli Creek','C',-33.9287,151.153,12),
 p('Central','D',-33.883,151.207,25)
];
const journey=()=>({stops:stations,transfers:[stations[3]]});

test('GPS accepts only fresh, accurate and valid browser positions',()=>{
 assert.deepEqual(reliableGpsFix(fix(-33.962,151.132),now),
  {lat:-33.962,lon:151.132,accuracy:12,timestamp:now});
 assert.equal(reliableGpsFix(fix(-33.962,151.132,GPS_MAX_ACCURACY_METRES+1),now),null);
 assert.equal(reliableGpsFix(fix(-33.962,151.132,10,now-GPS_FIX_MAX_AGE_MS-1),now),null);
 assert.equal(reliableGpsFix(fix(-33.962,151.132,10,now+5001),now),null);
 assert.equal(reliableGpsFix(fix(-33.962,151.132,-1),now),null);
 assert.equal(reliableGpsFix(fix(123,151.132),now),null);
 assert.equal(reliableGpsFix(fix(-33.962,Infinity),now),null);
 assert.equal(reliableGpsFix(null,now),null);
 assert.equal(locationIsFresh({lat:-33.962,lon:151.132,accuracy:10,timestamp:now-15000},now),true);
 assert.equal(locationIsFresh({lat:-33.962,lon:151.132,accuracy:10,timestamp:now-47000},now),false);
});

test('location-triggered station alerts require proximity to a stopping station',()=>{
 const route=journey();
 const near=reliableGpsFix(fix(-33.962,151.132),now);
 assert.equal(nearbyGpsStop(route,near,now),2);
 assert.equal(nearbyGpsStop(route,reliableGpsFix(fix(-33.964,151.118),now),now),null,
  'A skipped station should not trigger an alert');
 assert.equal(nearbyGpsStop(route,{lat:0,lon:0,accuracy:5,timestamp:now},now),null);
 assert.equal(nearbyGpsStop(route,{...near,timestamp:now-GPS_FIX_MAX_AGE_MS-1},now),null);
 assert.ok(GPS_STATION_RADIUS_METRES<1700,'Alert radius must be narrower than map radius');
});

test('monotonic progress count excludes bypassed stations, including transfer count',()=>{
 const route=journey();
 assert.deepEqual(progressAtIndex(route,2),{
  index:2,remaining:2,toChange:1,nearest:stations[2]
 });
 assert.deepEqual(progressAtIndex(route,3),{
  index:3,remaining:1,toChange:null,nearest:stations[3]
 });
 assert.equal(progressAtIndex(route,4).remaining,0);
 assert.equal(progressAtIndex(route,-8).index,0);
});

test('persisted notification keys are deduplicated and limited to this route',()=>{
 const route={...journey(),notifiedAlertKeys:[
  'destination-two','destination-two','transfer-C','not-ours','transfer-z',
  null,123,'destination-one'
 ]};
 assert.deepEqual(savedAlertKeys(route),[
  'destination-two','transfer-C','destination-one'
 ]);
 assert.deepEqual(savedAlertKeys(journey()),[]);
});
