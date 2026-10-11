import test from 'node:test';
import assert from 'node:assert/strict';
import {renderServiceUpdate,renderServiceDetails} from '../dist/assets/service-notice-markup.js';

const message=text=>'<p class="notice" role="status">'+text+'</p>';
const journey=(level,alert,extras={})=>({serviceStatus:{level,alerts:[alert],...extras},transfers:[]});

test('service notice suppresses OK, unavailable and empty alerts',()=>{
 for(const level of ['ok','unavailable'])assert.equal(renderServiceUpdate(journey(level,{title:'Hidden'}),message),'');
 assert.equal(renderServiceUpdate({serviceStatus:{level:'major',alerts:[]}},message),'');
 assert.equal(renderServiceDetails({serviceStatus:{alerts:[]}}),'');
});

test('major and warning alerts preserve live announcement and escape unsafe text',()=>{
 for(const level of ['major','warning']){
  const result=renderServiceUpdate(journey(level,{title:'<Delayed> & changed'}),message);
  assert.match(result,/class="alarm" role="alert"/);
  assert.match(result,/&lt;Delayed&gt; &amp; changed/);
  assert.doesNotMatch(result,/<Delayed>/);
 }
});

test('replacement notice preserves interchange labels and safely escapes them',()=>{
 const route=journey('major',{title:'disrupted'},{revalidationAttempted:true,replacementFound:true});
 route.transfers=[{name:'Town & Hall'}];
 const result=renderServiceUpdate(route,message);
 assert.match(result,/Original service affected/);
 assert.match(result,/Town &amp; Hall/);
});

test('lower severity uses message callback and notice details are escaped',()=>{
 const route=journey('info',{title:'Service information',description:'<b>Unsafe</b> & notice',materialChange:true});
 const result=renderServiceUpdate(route,message);
 assert.match(result,/class="notice"/);
 const details=renderServiceDetails(route);
 assert.match(details,/View disruption details/);
 assert.match(details,/Selected service may be affected/);
 assert.match(details,/&lt;b&gt;Unsafe&lt;\/b&gt; &amp; notice/);
 assert.doesNotMatch(details,/<b>Unsafe<\/b>/);
});
