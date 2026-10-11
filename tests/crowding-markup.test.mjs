import test from 'node:test';
import assert from 'node:assert/strict';
import { crowdingSummary, carriageCrowding } from '../dist/assets/crowding-markup.js';

const stamp=()=>new Date().toISOString();
function verified(level,legs) {
  const observed=stamp();
  return {crowding:{available:true,level,updatedAt:observed,legs:legs.map((leg,i)=>({
    legId:'leg-'+i,vehicleMatched:true,updatedAt:observed,...leg
  }))}};
}

test('no crowding markup for absent, unverified or stale occupancy', () => {
  assert.equal(crowdingSummary({}), '');
  assert.equal(carriageCrowding({}), '');
  assert.equal(crowdingSummary({ crowding: { available: false, level: 'busy' } }), '');
  assert.equal(crowdingSummary({ crowding: { available: true, level: 'unknown' } }), '');
  assert.equal(crowdingSummary({ crowding: { available: true, level: 'unexpected' } }), '');
  assert.equal(carriageCrowding(verified('busy',[])), '');
  assert.equal(carriageCrowding(verified('busy',[{mode:'metro',level:'busy',carriages:[{level:'unknown'}]}])), '');
  const old=verified('busy',[{mode:'train',level:'busy',carriages:[{level:'busy'}]}]);
  old.crowding.legs[0].updatedAt='2020-01-01T00:00:00Z';
  assert.equal(crowdingSummary(old),'');
  assert.equal(carriageCrowding(old),'');
});

test('whole-train levels display only when carriage levels are unavailable', () => {
  const fallback = verified('very_busy',[{ mode: 'train', level:'very_busy',carriages: [{ level: 'unknown' }] }]);
  assert.match(crowdingSummary(fallback), /^<div class="stateline">Crowding: Very busy<\/div><div class="muted crowding-freshness">TfNSW crowding observed less than a minute ago<\/div>$/);
  assert.match(crowdingSummary(verified('quiet',[{mode:'train',level:'quiet',carriages:[]}])), /Crowding: Quiet.*TfNSW crowding observed less than a minute ago/);
  const withCars = verified('busy',[{ mode: 'metro', level:'busy',carriages: [{ level: 'moderate', position: 1 }] }]);
  assert.equal(crowdingSummary(withCars), '');
  assert.match(carriageCrowding(withCars), /Carriage crowding · 1-car Metro train/);
});

test('carriages retain ascending physical positions and accessible level names', () => {
  const journey = verified('busy',[{ mode: 'metro', level:'busy',carriages: [
    { level: 'busy', position: 3, name: 'C3' },
    { level: 'quiet', position: 1, name: 'C1' },
    { level: 'unknown', position: 2, name: 'hidden' },
    { level: 'moderate', position: 2, name: 'C2' },
  ] }]);
  const before = JSON.stringify(journey);
  const html = carriageCrowding(journey);
  assert.match(html, /Carriage crowding · 3-car Metro train/);
  assert.match(html, /--car-count:3/);
  assert.ok(html.indexOf('code C1') < html.indexOf('code C2'));
  assert.ok(html.indexOf('code C2') < html.indexOf('code C3'));
  assert.match(html, /aria-label="Car 1, Quiet · TfNSW code C1"/);
  assert.match(html, /aria-label="Car 2, Moderate · TfNSW code C2"/);
  assert.match(html, /aria-label="Car 3, Busy · TfNSW code C3"/);
  assert.doesNotMatch(html, /hidden/);
  assert.equal(JSON.stringify(journey), before, 'rendering must not mutate live crowding data');
});

test('carriage codes from feed are safely escaped in labels and attributes', () => {
  const html = carriageCrowding(verified('very_busy',[{mode:'train',level:'very_busy',carriages:[
    { level: 'very_busy', position: 1, name: '<car & "one">' }
  ]}]));
  assert.match(html, /1-car train/);
  assert.match(html, /aria-label="Car 1, Very busy/);
  assert.match(html, /&lt;car &amp; &quot;one&quot;&gt;/);
  assert.doesNotMatch(html, /<car &/);
});

test('only first rail leg with recent known carriage data is rendered', () => {
  const html = carriageCrowding(verified('busy',[
    { mode: 'train',level:'unknown', carriages: [{ level: 'unknown', name: 'skip' }] },
    { mode: 'metro',level:'moderate', carriages: [{ level: 'moderate', position: 1, name: 'metro1' }] },
    { mode: 'train',level:'busy',carriages: [{ level: 'busy', position: 1, name: 'later' }] }
  ]));
  assert.match(html, /Metro train/);
  assert.match(html, /metro1/);
  assert.doesNotMatch(html, /later|skip/);
});
