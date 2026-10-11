import test from 'node:test';
import assert from 'node:assert/strict';
import {
  sydneyDayLabel, datedTrainTime, stopDisplayTime, displayLineName, transferDisplay
} from '../dist/assets/timetable-display.js';

const local=(day,time)=>`2026-10-${day}T${time}:00+11:00`;
const station=(id,name)=>({id,name});

test('Sydney date labels distinguish same day, tomorrow, other days and invalid times',()=>{
 const now=new Date(local('11','23:45'));
 assert.equal(sydneyDayLabel(local('11','08:00'),now),'');
 assert.equal(sydneyDayLabel(local('12','00:15'),now),'Tomorrow, ');
 assert.equal(sydneyDayLabel(local('14','09:00'),now),'14 Oct, ');
 assert.equal(sydneyDayLabel('not-a-date',now),'');
});

test('Sydney calendar day rather than UTC governs departure labels',()=>{
 const now=new Date(local('11','01:00'));
 // Both timestamps are 10 October UTC but 11 October in Sydney.
 assert.equal(sydneyDayLabel(local('11','00:30'),now),'');
 assert.equal(sydneyDayLabel(local('12','00:30'),now),'Tomorrow, ');
});

test('stops prefer departure at origin and arrival elsewhere, retaining fallbacks',()=>{
 const stop={departure:local('11','10:00'),arrival:local('11','09:58')};
 assert.equal(stopDisplayTime(stop,0),datedTrainTime(stop.departure));
 assert.equal(stopDisplayTime(stop,1),datedTrainTime(stop.arrival));
 assert.equal(stopDisplayTime({arrival:stop.arrival},0),datedTrainTime(stop.arrival));
 assert.equal(stopDisplayTime({departure:stop.departure},2),datedTrainTime(stop.departure));
 assert.equal(stopDisplayTime({},0),'');
 assert.equal(datedTrainTime(null),'');
 assert.match(datedTrainTime(local('12','00:15')),/\d/);
});

test('known rail and Metro line codes display long labels with unknown codes preserved',()=>{
 assert.equal(displayLineName('T4'),'T4 Eastern Suburbs & Illawarra Line');
 assert.equal(displayLineName(' m1 '),'M1 Metro North West & Bankstown');
 assert.equal(displayLineName('CCN'),'Central Coast & Newcastle Line');
 assert.equal(displayLineName('X99'),'X99');
 assert.equal(displayLineName('  Mystery  '),'Mystery');
 assert.equal(displayLineName(undefined),'');
});

const interchange=(arrival=local('11','10:04'),departure=local('11','10:09'))=>({
 legs:[
  {line:'T4',destination:{...station('B','Town Hall'),arrival,platform:'2'},arrival},
  {line:'T1',origin:{...station('B','Town Hall'),departure,platform:'3'},departure}
 ]
});

test('transfer markup shows arrival/departure details, interchange platforms and change buffer',()=>{
 const html=transferDisplay(interchange(),station('B','Town Hall'));
 assert.match(html,/transfer-detail/);
 assert.match(html,/T4 Eastern Suburbs &amp; Illawarra Line/);
 assert.match(html,/T1 North Shore &amp; Western Line/);
 assert.match(html,/Platform 2/);
 assert.match(html,/Platform 3/);
 assert.match(html,/5 min to change/);
 assert.match(html,/Change trains here/);
 assert.equal(transferDisplay(interchange(),station('Z','Unrelated')),'');
});

test('missing platform or timetable displays fallback and negative change time is not described as wait',()=>{
 const missing={legs:[
  {line:'T4',destination:{id:'B',name:'Town Hall'}},
  {line:'T1',origin:{id:'B',name:'Town Hall'}}
 ]};
 const html=transferDisplay(missing,station('B','Town Hall'));
 assert.match(html,/Time unavailable/);
 assert.match(html,/Platform unavailable/);
 assert.doesNotMatch(html,/min to change/);
 const negative=transferDisplay(interchange(local('11','10:10'),local('11','10:05')),station('B','Town Hall'));
 assert.doesNotMatch(negative,/min to change/);
});

test('transfer markup escapes any upstream line and platform strings',()=>{
 const route={legs:[
  {line:'<script>bad</script>',destination:{id:'B',name:'Town Hall',platform:'<img>',arrival:local('11','10:01')}},
  {line:'T4',origin:{id:'B',name:'Town Hall',platform:'&2',departure:local('11','10:09')}}
 ]};
 const html=transferDisplay(route,station('B','Town Hall'));
 assert.doesNotMatch(html,/<script>|<img>/);
 assert.match(html,/&lt;script&gt;bad&lt;\/script&gt;/);
 assert.match(html,/Platform &lt;img&gt;/);
 assert.match(html,/Platform &amp;2/);
});

test('transfer markup is pure and does not modify either input',()=>{
 const route=interchange(),stop=station('B','Town Hall');
 const before=JSON.stringify({route,stop});
 transferDisplay(route,stop);
 assert.equal(JSON.stringify({route,stop}),before);
});
