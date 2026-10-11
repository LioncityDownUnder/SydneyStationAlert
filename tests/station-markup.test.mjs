import test from 'node:test';
import assert from 'node:assert/strict';
import {highlightName,optionMarkup,stationField} from '../dist/assets/station-markup.js';

test('station names are escaped and matching text is highlighted',()=>{
 assert.equal(highlightName('Town & Hall','hall'),'Town &amp; <mark>Hall</mark>');
 assert.equal(highlightName('<Central>','absent'),'&lt;Central&gt;');
 assert.equal(highlightName('Central',''),'Central');
});
test('station choices remain accessible, escaped, and show selected keyboard item',()=>{
 const html=optionMarkup({name:'<Gadigal>',mode:'metro'},2,'Gadi',2);
 assert.match(html,/role="option" aria-selected="true"/);
 assert.match(html,/id="station-option-2"/);
 assert.match(html,/<mark>Gadi<\/mark>/);
 assert.match(html,/Sydney Metro/);
 assert.doesNotMatch(html,/<Gadigal>/);
});
test('station picker preserves expanded and active descendant ARIA state',()=>{
 const html=stationField('origin','Boarding station','Search here',null,'origin',1);
 assert.match(html,/role="combobox"/);
 assert.match(html,/aria-expanded="true"/);
 assert.match(html,/aria-activedescendant="station-option-1"/);
 assert.match(html,/id="options-origin"/);
 const closed=stationField('destination','Destination','Search',null,'origin',-1);
 assert.match(closed,/aria-expanded="false"/);
 assert.doesNotMatch(closed,/aria-activedescendant/);
});
test('selected station renders escaped label, mode and change action',()=>{
 const html=stationField('origin','Boarding <station>','Start',{name:'Central & Town Hall',mode:'train'},null,-1);
 assert.match(html,/Central &amp; Town Hall/);
 assert.match(html,/Boarding &lt;station&gt;/);
 assert.match(html,/Sydney Trains/);
 assert.match(html,/id="edit-origin"/);
 assert.doesNotMatch(html,/role="combobox"/);
});
