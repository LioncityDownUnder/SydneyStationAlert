/**
 * Exhaustive read-only live station-picker identity check against all station
 * parents with explicitly named railway platforms in a public GTFS snapshot.
 *
 * Safety: low concurrency (one request at a time), 250 ms inter-request pause;
 * stops on 429/503 to avoid stressing the live provider. No journey calls.
 * Source is dated 2024 and is not guaranteed to contain all current stations.
 */
const layer='https://portal.data.nsw.gov.au/arcgis/rest/services/Hosted/TfNSW_GTFS_Stops_Public/FeatureServer/0';
const api='https://trains.nytnetwork.work/qatest/api/index.php';
async function query(url,params,ms=30000){
 const u=new URL(url);for(const [k,v] of Object.entries(params))u.searchParams.set(k,String(v));
 const r=await fetch(u,{signal:AbortSignal.timeout(ms),headers:{Accept:'application/json'}});
 const data=await r.json().catch(()=>null);
 if(!r.ok||data?.error)throw Error('HTTP '+r.status+':'+String(data?.error?.code||'unknown'));
 return data;
}
async function all(where){
 const count=(await query(layer+'/query',{f:'json',where,returnCountOnly:true})).count;
 const out=[];
 for(let offset=0;offset<count;offset+=500){
  const res=await query(layer+'/query',{f:'json',where,outFields:'stop_id,stop_name,parent_station,location_type,objectid',
   returnGeometry:false,resultOffset:offset,resultRecordCount:500,orderByFields:'objectid ASC'},40000);
  out.push(...(res.features||[]).map(x=>x.attributes));
 }
 if(out.length!==count)throw Error('Incomplete historical GTFS scan');
 return out;
}
function normal(name){
 let s=String(name||'').trim();
 s=s.replace(/,\s*(?:platform|plat\.?|pl\.?)\s*:?\s*\d+[a-z]?(?:\s*,\s*[^,]+)?\s*$/i,'');
 s=s.replace(/\s+(?:station|railway station)\s*$/i,'').trim().replace(/,+$/,'').trim().toLowerCase();
 return s==='sydney international airport'?'international airport':
  s==='sydney domestic airport'?'domestic airport':s;
}
const parent=await all("location_type = '1' AND stop_id LIKE '2%'");
const child=await all("parent_station LIKE '2%'");
const grouped=new Map();
for(const x of child){
 const id=String(x.parent_station||'');
 if(!grouped.has(id))grouped.set(id,[]);
 grouped.get(id).push(x);
}
const targets=parent.map(p=>({
 id:String(p.stop_id),gtfsName:p.stop_name,
 platforms:(grouped.get(String(p.stop_id))||[]).filter(c=>
  /,\s*platform\s*:?\s*\d+[a-z]?\b/i.test(c.stop_name||'')&&
  !/_ENT|_DP|_PCE|_CCP|_CNC/i.test(String(c.stop_id||'')))
})).filter(p=>p.platforms.length);
console.log(JSON.stringify({type:'start',railParentCount:targets.length,
 platformCount:targets.reduce((s,p)=>s+p.platforms.length,0),
 note:'Historical public GTFS station list; each station picker identity checked using its numeric parent ID.'}));
const results=[];
for(let i=0;i<targets.length;i++){
 const t=targets[i];
 const url=new URL(api);url.searchParams.set('action','stations');url.searchParams.set('q',t.id);
 try{
  const response=await fetch(url,{signal:AbortSignal.timeout(17000),headers:{Accept:'application/json'}});
  if(response.status===429||response.status===503){
   console.log(JSON.stringify({type:'stopped',status:response.status,index:i,
    note:'Provider rate limit or temporary unavailability — audit stops to avoid repeated calls.'}));
   break;
  }
  const body=await response.json().catch(()=>null);
  const options=Array.isArray(body?.data)?body.data:[];
  const chosen=options.find(s=>String(s.id)===t.id)||null;
  const candidate=chosen||options.find(s=>normal(s.name)===normal(t.gtfsName))||null;
  const flags=t.platforms.filter(p=>candidate&&
   normal(candidate.name)!==normal(p.stop_name)).map(p=>({id:p.stop_id,name:p.stop_name}));
  results.push({id:t.id,gtfsParentName:t.gtfsName,
   selectorName:candidate?.name||null,selectorId:candidate?.id||null,
   status:!response.ok?'http_'+response.status:
      !candidate?'not_found':
      candidate.id!==t.id?'id_mismatch':
      flags.length?'name_mismatch':'matches',
   mismatchedPlatforms:flags.slice(0,4)});
 }catch(e){results.push({id:t.id,gtfsParentName:t.gtfsName,status:'error',error:String(e.name||e.message)});}
 if((i+1)%40===0)console.log('AUDIT_PROGRESS',i+1,targets.length);
 await new Promise(resolve=>setTimeout(resolve,250));
}
const counts={};for(const r of results)counts[r.status]=(counts[r.status]||0)+1;
const flagged=results.filter(r=>r.status!=='matches');
console.log(JSON.stringify({type:'summary',checked:results.length,total:targets.length,counts,
 flaggedCount:flagged.length,flagged,remaining:targets.length-results.length}));
console.log('EXHAUSTIVE_LIVE_STATION_PICKER_AUDIT_COMPLETED',new Date().toISOString());
