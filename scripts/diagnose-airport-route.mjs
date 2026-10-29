/**
 * Read-only, redacted live-route diagnosis. Intended for one-off GitHub Actions
 * runs, never a production endpoint or deploy job.
 *
 * Prints HTTP status, response category, timings, and rail leg names only.
 * No TfNSW keys, personal device data or full upstream payloads are printed.
 */
const host='https://trains.nytnetwork.work';
const base={qa:host+'/qatest/api/index.php',production:host+'/api/index.php'};
const pairs=[
  ['Hurstville','International Airport'],
  ['Hurstville','Wolli Creek'],
  ['Wolli Creek','International Airport']
];
async function request(url,timeout=43000){
 const started=Date.now();
 try{
  const res=await fetch(url,{signal:AbortSignal.timeout(timeout),headers:{Accept:'application/json'},cache:'no-store'});
  const body=await res.json().catch(()=>null);
  return {status:res.status,elapsedMs:Date.now()-started,body,
    diagnostics:res.headers.get('x-qa-journey-search'),
    timing:res.headers.get('server-timing')};
 }catch(e){return {status:null,elapsedMs:Date.now()-started,error:String(e?.name||'REQUEST_FAILED')};}
}
function stationList(response,needle){
 const data=Array.isArray(response.body?.data)?response.body.data:[];
 return data.filter(s=>typeof s?.name==='string'&&s.name.toLowerCase().includes(needle.toLowerCase()))
   .slice(0,6).map(s=>({id:String(s.id),name:s.name,mode:s.mode}));
}
function safeRoute(response){
 const result={status:response.status,elapsedMs:response.elapsedMs};
 if(response.error)result.requestError=response.error;
 if(response.diagnostics)result.qaSearchDiagnostics=response.diagnostics;
 if(response.timing)result.timing=response.timing;
 const failure=response.body?.error;
 if(failure){result.code=failure.code;result.message=failure.message;}
 const route=response.body?.data;
 if(route && Array.isArray(route.legs)){
  result.legs=route.legs.map(l=>({
   mode:l.mode,line:l.line,from:l.origin?.name,to:l.destination?.name,
   depart:l.departure,arrive:l.arrival
  }));
  result.transfers=(route.transfers||[]).map(t=>t.name);
 }
 return result;
}
function named(stations,name){
 const exact=stations.find(s=>s.name.toLowerCase()===name.toLowerCase());
 if(!exact)throw new Error('No exact match for '+name+' among station choices');
 return exact;
}
console.log('Airport route diagnosis at',new Date().toISOString());
const stationResponses={};
for(const [environment,api] of Object.entries(base)){
 stationResponses[environment]={};
 for(const name of ['Hurstville','International Airport','Wolli Creek']){
  const u=new URL(api);u.searchParams.set('action','stations');u.searchParams.set('q',name);
  const r=await request(u,15000);
  const options=stationList(r,name);
  stationResponses[environment][name]=options;
  console.log(JSON.stringify({type:'station',environment,name,status:r.status,options}));
 }
}
for(const [environment,api] of Object.entries(base)){
 const u=new URL(api);u.searchParams.set('action','station-index');
 const index=await request(u,25000);
 const all=Array.isArray(index.body?.data)?index.body.data:[];
 const matches=all.filter(s=>/^(International Airport|Hurstville|Wolli Creek)$/i.test(s.name||''))
   .map(s=>({name:s.name,id:String(s.id),mode:s.mode}));
 console.log(JSON.stringify({type:'station-index',environment,status:index.status,count:all.length,matches}));
}
for(const environment of ['qa','production']){
 const api=base[environment];
 for(const [fromName,toName] of pairs){
  try{
   const from=named(stationResponses[environment][fromName],fromName);
   const to=named(stationResponses[environment][toName],toName);
   const u=new URL(api);
   Object.entries({action:'journey',from:from.id,to:to.id,fromName,toName,coreOnly:'1'})
     .forEach(([k,v])=>u.searchParams.set(k,v));
   const r=await request(u,45000);
   console.log(JSON.stringify({type:'journey',environment,from:fromName,to:toName,result:safeRoute(r)}));
  }catch(e){console.log(JSON.stringify({type:'journey',environment,from:fromName,to:toName,diagnosticError:String(e.message)}));}
 }
}
console.log('Diagnosis completed (no production or QA changes).');
