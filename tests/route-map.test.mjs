import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { renderRouteMap } from '../dist/assets/route-map.js';

const A={lat:-33.88,lon:151.15};
const B={lat:-33.89,lon:151.16};
const C={lat:-33.90,lon:151.17};
const journey=(name='Central')=>({
  legs:[{mode:'train',path:[A,B],stops:[A,B]},{mode:'metro',path:[],stops:[B,C]}],
  stops:[A,B,C],destination:{name}
});

test('no geometry keeps existing accessible fallback',()=>{
  const empty={legs:[{mode:'train',path:[],stops:[]}],stops:[],destination:{name:'No geometry'}};
  assert.equal(renderRouteMap(empty,{index:0},null),
    '<p class="muted">Map geometry is unavailable. Follow the station list below.</p>');
});

test('train and Metro map path colours, geometry fallback and label',()=>{
  const svg=renderRouteMap(journey(),{index:0},null);
  assert.match(svg,/<svg class="map" role="img"/);
  assert.match(svg,/aria-label="Rail route map with station positions/);
  assert.match(svg,/stroke="#566c61"/);
  assert.match(svg,/stroke="#dc7b3d"/);
  assert.equal((svg.match(/<polyline /g)||[]).length,2);
  assert.match(svg,/Rail segments shown separately/);
  assert.match(svg,/Central<\/text>/);
  assert.doesNotMatch(svg,/opacity="\.18"/);
});

test('active station and GPS markers render only when applicable',()=>{
  const without=renderRouteMap(journey(),{index:1},null);
  const withPosition=renderRouteMap(journey(),{index:1},B);
  assert.match(without,/class="map-active-pulse"/);
  assert.match(withPosition,/class="map-active-pulse"/);
  assert.match(withPosition,/opacity="\.18"/);
  assert.match(withPosition,/r="4" fill="#ef7a2d"/);
});

test('upstream station name escapes HTML and inputs stay unchanged',()=>{
  const data=journey('</text><script>alert(1)</script>');
  const before=JSON.stringify(data);
  const svg=renderRouteMap(data,{index:2},B);
  assert.doesNotMatch(svg,/<script>/);
  assert.match(svg,/&lt;\/text&gt;&lt;script&gt;alert\(1\)&lt;\/script&gt;/);
  assert.equal(JSON.stringify(data),before);
});

test('main still delegates with current GPS and preserves boarding/recovery',()=>{
  const main=fs.readFileSync('src/main.ts','utf8');
  assert.match(main,/import \{ renderRouteMap \} from '\.\/route-map\.js'/);
  assert.match(main,/function mapMarkup\(j,p\)\{return renderRouteMap\(j,p,state\.location\);\}/);
  assert.doesNotMatch(main,/<svg class="map"/);
  assert.match(main,/function qaSameSelectedTrain\(a,b\)/);
  assert.match(main,/async function recoverMissedConnection\(legIndex\)/);
});
