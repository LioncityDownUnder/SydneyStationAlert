/**
 * Complete structural parent-to-platform name comparison for the public TfNSW
 * GTFS reference snapshot. This is NOT real-time proof of train availability.
 * Scope is every numeric (2...) station parent in that snapshot, including
 * metro/intercity, with explicitly named "Platform N" child stops.
 * No route-planner calls, no writes, and no credentials.
 */
const layer='https://portal.data.nsw.gov.au/arcgis/rest/services/Hosted/TfNSW_GTFS_Stops_Public/FeatureServer/0';
const app='https://trains.nytnetwork.work/qatest/api/index.php';
async function get(url,params={},timeout=45000){
 const u=new URL(url);
 Object.entries({f:'json',...params}).forEach(([k,v])=>u.searchParams.set(k,String(v)));
 const response=await fetch(u,{signal:AbortSignal.timeout(timeout),headers:{Accept:'application/json'}});
 const body=await response.json();
 if(!response.ok||body.error)throw Error('Request failed '+response.status+': '+JSON.stringify(body.error));
 return body;
}
async function all(where){
 const total=(await get(layer+'/query',{where,returnCountOnly:'true'})).count;
 const out=[];
 for(let start=0;start<total;start+=500){
  const page=await get(layer+'/query',{where,outFields:'stop_id,stop_name,location_type,parent_station,portal_last_updated,objectid',
   returnGeometry:'false',resultRecordCount:500,resultOffset:start,orderByFields:'objectid ASC'});
  if(!page.features?.length)throw Error('Missing page '+start);
  out.push(...page.features.map(x=>x.attributes));
 }
 if(out.length!==total)throw Error('Truncated source inventory');
 return out;
}
function cleaned(name){
 return String(name||'').trim()
 .replace(/,\s*(?:platform|plat\.?|pl\.?)\s*:?\s*\d+[a-z]?(?:\s*,\s*[^,]+)?\s*$/i,'')
 .replace(/\s+(?:station|railway station)\s*$/i,'')
 .trim().replace(/,+$/,'').trim();
}
function canonical(name){
 const clean=cleaned(name).toLowerCase();
 return clean==='sydney international airport'?'international airport':clean;
}
const railPlatformName=name=>/\bplatform\s*:?\s*\d+[a-z]?\b/i.test(String(name||''));
console.log('RAIL_PLATFORM_IDENTITY_SCAN_STARTED',new Date().toISOString());
const parents=await all("location_type = '1' AND stop_id LIKE '2%'");
const children=await all("parent_station LIKE '2%'");
const byParent=new Map();
for(const item of children){const id=String(item.parent_station);if(!byParent.has(id))byParent.set(id,[]);byParent.get(id).push(item);}
let platforms=0;
const railParents=[],mismatch=[],duplicates=new Map();
for(const parent of parents){
 const eligible=(byParent.get(String(parent.stop_id))||[]).filter(p=>railPlatformName(p.stop_name));
 if(!eligible.length)continue;
 railParents.push(parent);platforms+=eligible.length;
 const discrepancies=eligible.filter(p=>canonical(p.stop_name)!==canonical(parent.stop_name));
 if(discrepancies.length)mismatch.push({id:String(parent.stop_id),parentName:parent.stop_name,
  parentIdentity:canonical(parent.stop_name),platformCount:eligible.length,
  differingPlatforms:discrepancies.map(x=>({id:x.stop_id,name:x.stop_name,identity:canonical(x.stop_name)}))});
 const normalized=canonical(parent.stop_name);
 if(!duplicates.has(normalized))duplicates.set(normalized,[]);
 duplicates.get(normalized).push({id:String(parent.stop_id),name:parent.stop_name});
}
const collisions=[...duplicates].filter(([name,items])=>items.length>1).map(([name,items])=>({identity:name,items}));
console.log(JSON.stringify({type:'scope',date:new Date().toISOString(),referenceUpdateDates:[...new Set(parents.map(p=>p.portal_last_updated))],
 totalNumericParentCandidates:parents.length,totalReferencedChildStops:children.length,
 railParentsWithNamedPlatforms:railParents.length,namedPlatformStops:platforms,
 mismatchingParentCount:mismatch.length,duplicateRailParentNames:collisions.length,
 note:'Historical GTFS reference; all named-platform families in this dataset, not a guarantee of every live station or service.'}));
console.log(JSON.stringify({type:'platform-mismatches',all:mismatch}));
console.log(JSON.stringify({type:'duplicate-names',all:collisions}));
const toCheck=[
 ...mismatch.map(x=>({id:x.id,name:x.parentName,kind:'platform-mismatch'})),
 ...railParents.filter(x=>/Airport|Central|Parramatta|Revesby|North Sydney|Wangaratta|Mount Kuring|Wolli Creek|Gadigal|Tallawong|Sydenham|Chatswood|Martin Place|Town Hall|Domestic/i.test(x.stop_name||'')).map(x=>({id:String(x.stop_id),name:x.stop_name,kind:'sample'}))
];
const selected=[...new Map(toCheck.map(s=>[s.id,s])).values()].slice(0,100);
const tested=[];
for(const x of selected){
 const u=new URL(app);
 u.searchParams.set('action','stations');
 u.searchParams.set('q',cleaned(x.name));
 try{
  const response=await fetch(u,{signal:AbortSignal.timeout(16000),headers:{Accept:'application/json'}});
  const raw=await response.json();
  const list=Array.isArray(raw?.data)?raw.data:[];
  const chosen=list.find(s=>String(s.id)===x.id)||null;
  tested.push({kind:x.kind,id:x.id,gtfsName:x.name,http:response.status,
    chosen:chosen?{id:String(chosen.id),name:chosen.name,
      matchingName:canonical(chosen.name)===canonical(x.name)}:null,
    nearAlternatives:chosen?undefined:list.slice(0,3).map(y=>({id:y.id,name:y.name}))});
 }catch(e){tested.push({kind:x.kind,id:x.id,error:String(e.name||e.message)});}
 await new Promise(resolve=>setTimeout(resolve,180));
}
console.log(JSON.stringify({type:'live-picker-checks',count:tested.length,results:tested}));
console.log('RAIL_PLATFORM_IDENTITY_SCAN_COMPLETED',new Date().toISOString());
