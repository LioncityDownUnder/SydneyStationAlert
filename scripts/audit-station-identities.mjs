/**
 * Read-only network audit of authoritative-ish TfNSW GTFS parent/child stop
 * data. Metadata is a historical open-data snapshot; compare against the live
 * app to confirm suspect mismatches before classifying defects.
 *
 * Only public ArcGIS reads and a small number of read-only station searches.
 * Never call trip-planning for hundreds of routes or change deployments.
 */
const layer='https://portal.data.nsw.gov.au/arcgis/rest/services/Hosted/TfNSW_GTFS_Stops_Public/FeatureServer/0';
const app='https://trains.nytnetwork.work/qatest/api/index.php';
async function get(url,params={},deadline=30000){
 const q=new URL(url);
 Object.entries({f:'json',...params}).forEach(([k,v])=>q.searchParams.set(k,String(v)));
 const res=await fetch(q,{signal:AbortSignal.timeout(deadline),headers:{Accept:'application/json'}});
 const content=await res.json();
 if(!res.ok||content.error)throw Error('HTTP '+res.status+' '+JSON.stringify(content.error||'').slice(0,300));
 return content;
}
async function allRecords(where){
 const fields='stop_id,stop_name,location_type,parent_station,portal_last_updated,objectid';
 const count=(await get(layer+'/query',{where,returnCountOnly:'true'})).count;
 const records=[],size=500;
 for(let offset=0;offset<count;offset+=size){
  const resp=await get(layer+'/query',{where,outFields:fields,returnGeometry:'false',
   resultRecordCount:size,resultOffset:offset,orderByFields:'objectid ASC'},45000);
  const page=resp.features?.map(f=>f.attributes)||[];
  if(!page.length)throw Error('Unexpected empty ArcGIS page at '+offset+' for '+where);
  records.push(...page);
 }
 if(records.length!==count)throw Error('Count mismatch '+records.length+' vs '+count);
 return records;
}
function displayName(name){
 let result=String(name||'').trim();
 result=result.replace(/\s*,?\s*(?:platform|plat\.?|pl\.?)\s*([0-9]+[a-z]?)\s*$/i,'');
 result=result.replace(/\s+(Station|Railway Station)\s*$/i,'');
 return result.trim().replace(/,+$/,'').trim();
}
function identity(name){
 const result=displayName(name).toLowerCase();
 return result==='sydney international airport'?'international airport':result;
}
function isGenericPlatform(name){
 return /^(?:platform|plat\.?|pl\.?)\s*:?\s*\d+[a-z]?$/i.test(String(name||'').trim());
}
function summarizeName(name){return {raw:name,cleaned:displayName(name),identity:identity(name)};}
console.log('STATION_IDENTITY_AUDIT_STARTED',new Date().toISOString());
const parents=await allRecords("location_type = '1' AND stop_id LIKE '2%'");
const children=await allRecords("parent_station LIKE '2%'");
const parentMap=new Map(parents.map(p=>[String(p.stop_id),p]));
const childMap=new Map();
for(const child of children){
 const pid=String(child.parent_station||'');
 if(!childMap.has(pid))childMap.set(pid,[]);
 childMap.get(pid).push(child);
}
console.log(JSON.stringify({type:'inventory',totalParentCandidates:parents.length,
 totalChildCandidates:children.length,
 parentsWithChildren:parents.filter(p=>childMap.has(String(p.stop_id))).length,
 orphanedChildren:children.filter(c=>!parentMap.has(String(c.parent_station))).length,
 sourceUpdateSamples:[...new Set(parents.map(p=>p.portal_last_updated))].slice(0,8)}));
const mismatches=[];
const generics=[];
const missingChildren=[];
for(const p of parents){
 const family=childMap.get(String(p.stop_id))||[];
 if(!family.length){missingChildren.push(p);continue;}
 const differences=[],uncertain=[];
 for(const child of family){
  if(!child.stop_name || isGenericPlatform(displayName(child.stop_name))){uncertain.push(child);continue;}
  if(identity(child.stop_name)!==identity(p.stop_name))differences.push(child);
 }
 if(differences.length){
  mismatches.push({id:String(p.stop_id),name:p.stop_name,cleanName:displayName(p.stop_name),
   childCount:family.length,differences:differences.slice(0,5).map(s=>({id:s.stop_id,name:s.stop_name,canonical:identity(s.stop_name)}))});
 }
 if(uncertain.length)generics.push({id:String(p.stop_id),name:p.stop_name,count:uncertain.length});
}
console.log(JSON.stringify({type:'analysis',parentCount:parents.length,
 parentsWithNoChild:missingChildren.length,unknownChildNames:generics.length,
 nameMismatchParentCount:mismatches.length,
 mismatchExamples:mismatches.slice(0,80),
 missingChildExamples:missingChildren.slice(0,12).map(p=>({id:p.stop_id,name:p.stop_name})),
 genericExamples:generics.slice(0,20)}));
// Only read the live station-selector endpoint for suspicious families,
// not hundreds of parallel route-planner calls. Limits avoid abuse.
const suspicious=mismatches.slice(0,60);
const sampleParents=parents.filter(p=>childMap.has(String(p.stop_id)))
  .filter(p=>/Airport|Central|Redfern|Hurstville|Wolli Creek|Revesby|Chatswood|Epping|Martin Place|Town Hall|Parramatta|Sydney Olympic Park|Mount Kuring|Kings Cross/i.test(p.stop_name||'')).slice(0,25);
const cases=[...new Map([...suspicious.map(p=>({id:p.id,name:p.name})),...sampleParents.map(p=>({id:String(p.stop_id),name:p.stop_name}))].map(p=>[p.id,p])).values()].slice(0,72);
const comparisons=[];
for(let i=0;i<cases.length;i++){
 const cand=cases[i];
 try{
  const url=new URL(app);
  url.searchParams.set('action','stations');
  url.searchParams.set('q',displayName(cand.name));
  const res=await fetch(url,{signal:AbortSignal.timeout(15000),headers:{Accept:'application/json'}});
  const body=await res.json();
  const results=Array.isArray(body?.data)?body.data:[];
  const exact=results.find(s=>String(s.id)===cand.id)||
   results.find(s=>identity(s.name)===identity(cand.name))||null;
  comparisons.push({id:cand.id,gtfsName:cand.name,http:res.status,
   selector:exact?{name:exact.name,id:String(exact.id),sameId:String(exact.id)===cand.id,
    identityMatches:identity(exact.name)===identity(cand.name)}:null,
   ambiguous:results.length>1?results.slice(0,3).map(x=>({name:x.name,id:String(x.id)})):undefined});
 }catch(e){
  comparisons.push({id:cand.id,gtfsName:cand.name,error:String(e.name||e.message)});
 }
 if(i%12===0)console.log('AUDIT_PROGRESS',i+1,'of',cases.length);
 await new Promise(resolve=>setTimeout(resolve,200));
}
console.log(JSON.stringify({type:'live-sample-check',queried:comparisons.length,
 matchedSelectors:comparisons.filter(c=>c.selector).length,
 nameDisagreements:comparisons.filter(c=>c.selector && !c.selector.identityMatches),
 unknown:comparisons.filter(c=>!c.selector).slice(0,50),
 sample:comparisons.slice(0,70)}));
console.log('STATION_IDENTITY_AUDIT_COMPLETED',new Date().toISOString());
