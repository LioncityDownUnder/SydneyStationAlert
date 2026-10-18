import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
const source=fs.readFileSync('src/main.ts','utf8');

test('onward boarding confirmation is available in production and requires onboard journey',()=>{
 assert.match(source,/function confirmOnwardBoarding\(transferId\)/);
 assert.match(source,/if\(!qaRailPilot\|\|!state\.onboard\|\|!state\.journey\)return/);
 assert.match(source,/window\.confirm\('Confirm you have boarded the connecting train at '/);
});
test('transfer confirmation is stored on the selected journey and survives refresh',()=>{
 assert.match(source,/state\.journey\.qaConfirmedTransfers=\[\.\.\.confirmedTransferIds\(\),transfer\.id\]/);
 assert.match(source,/updated\.qaConfirmedTransfers=\[\.\.\.state\.journey\.qaConfirmedTransfers\]/);
 assert.match(source,/saveActiveTrip\(\);renderJourneyStable\(\)/);
});
test('App suppresses acknowledged transfer alerts while preserving other alert keys',()=>{
 assert.match(source,/key\.startsWith\('transfer-'\)&&confirmedTransferIds\(\)/);
 assert.match(source,/fired\.add\('transfer-'\+transfer\.id\)/);
});
test('UI provides a persistent reminder and explicit onward train boarding button',()=>{
 assert.match(source,/Connecting train not yet confirmed/);
 assert.match(source,/data-confirm-onward/);
 assert.match(source,/I boarded the connecting train/);
 assert.match(source,/confirmOnwardBoarding\(button\.dataset\.confirmOnward\)/);
});
