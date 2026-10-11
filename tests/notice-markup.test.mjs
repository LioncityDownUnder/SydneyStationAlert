import test from 'node:test';
import assert from 'node:assert/strict';
import {
  renderNotificationReliabilityNotice,
  renderRailTravelLinks
} from '../dist/assets/notice-markup.js';

test('alert reliability guidance stays collapsible and closed by default',()=>{
 const markup=renderNotificationReliabilityNotice('default',false);
 assert.match(markup,/^<details id="alert-reliability-notice" class="notice">/);
 assert.match(markup,/<summary>Alert Reliability Notice<\/summary>/);
 assert.match(markup,/Notifications not yet enabled\./);
 assert.match(markup,/Location and timetable checks may stop when your phone is locked/);
 assert.match(markup,/do not rely on it as your only stop reminder/);
 assert.doesNotMatch(markup,/\sopen(?:\s|>)/);
});

test('notice expanded state and notification permissions retain existing messages',()=>{
 const cases=[
  ['granted','Notifications enabled while the app is active.'],
  ['denied','Notifications blocked in browser settings.'],
  ['default','Notifications not yet enabled.'],
  ['unsupported','Notifications unavailable in this browser.'],
 ];
 for(const [permission,message] of cases){
  const closed=renderNotificationReliabilityNotice(permission,false);
  const opened=renderNotificationReliabilityNotice(permission,true);
  assert.ok(closed.includes(message),`missing ${permission} notice`);
  assert.ok(opened.includes(message),`missing ${permission} notice`);
  assert.match(opened,/^<details id="alert-reliability-notice" class="notice" open>/);
 }
 assert.match(renderNotificationReliabilityNotice('unknown',false),/Notifications unavailable/);
});

test('rail travel links appear only when enabled, with safe external link attributes',()=>{
 assert.equal(renderRailTravelLinks(false),'');
 const markup=renderRailTravelLinks(true);
 assert.match(markup,/^<p class="muted">Check /);
 assert.match(markup,/https:\/\/transportnsw\.info\/alerts/);
 assert.match(markup,/https:\/\/transportnsw\.info\/trip/);
 assert.match(markup,/>TfNSW service alerts<\/a>/);
 assert.match(markup,/>TfNSW Trip Planner<\/a>/);
 assert.equal((markup.match(/target="_blank" rel="noopener noreferrer"/g)||[]).length,2);
 assert.match(markup,/other transport arrangements\.<\/p>$/);
});

test('notice helpers are deterministic and contain no script elements',()=>{
 const markup=renderNotificationReliabilityNotice('granted',true)+renderRailTravelLinks(true);
 assert.equal(markup,renderNotificationReliabilityNotice('granted',true)+renderRailTravelLinks(true));
 assert.doesNotMatch(markup,/<script|onclick=|onerror=/i);
});
