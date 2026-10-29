/**
 * Read-only probe of two TfNSW airport stop names and IDs.
 * Prints only station IDs/names, safe itinerary legs and error codes;
 * never writes to QA/production, and does not print credentials.
 */
const BASES={
 qa:'https://trains.nytnetwork.work/qatest/api/index.php',
 production:'https://trains.nytnetwork.work/api/index.php'
};
const names=['Hurstville','International Airport','Sydney International Airport'];
async function call(api,params,timeout=45000){
 const url=new URL(api);
 for(const [k,v] of Object.entries(params))url.searchParams.set(k,String(v));
 const started=Date.now();
 try{
  const response=await fetch(url,{signal:AbortSignal.timeout(timeout),cache:'no-store'});
  return {status:response.status,elapsedMs:Date.now()-started,
   body:await response.json().catch(()=>null),
   diagnostics:response.headers.get('x-qa-journey-search')};
 }catch(e){return {status:null,elapsedMs:Date.now()-started,error:String(e?.name||'network_error')};}
}
function safeRoute(result){
 const body=result.body||{},route=body.data;
 return {
  status:result.status,elapsedMs:result.elapsedMs,
  error:result.error||null,code:body.error?.code||null,
  message:body.error?.message||null,
  diagnostics:result.diagnostics,
  stops:route?.stops?.length||0,
  legs:Array.isArray(route?.legs)?route.legs.map(l=>({
   line:l.line,from:l.origin?.name,to:l.destination?.name,
   fromId:l.origin?.id,toId:l.destination?.id,
   depart:l.departure,arrive:l.arrival
  })):[],
  transfers:route?.transfers?.map(t=>t.name)||[]
 };
}
console.log('Airport station label probe UTC',new Date().toISOString());
for(const [env,api] of Object.entries(BASES)){
 const selections={};
 for(const name of names){
  const result=await call(api,{action:'stations',q:name},15000);
  const found=Array.isArray(result.body?.data)?result.body.data:[];
  const airport=found.filter(s=>/airport|hurstville/i.test(s.name||'')).slice(0,15)
    .map(s=>({id:String(s.id),name:s.name,mode:s.mode}));
  const exact=airport.find(s=>s.name.toLowerCase()===name.toLowerCase());
  selections[name]=exact||null;
  console.log(JSON.stringify({type:'station-search',environment:env,query:name,http:result.status,options:airport,exact}));
 }
 for(const target of ['International Airport','Sydney International Airport']){
  const origin=selections.Hurstville,destination=selections[target];
  if(!origin||!destination){
   console.log(JSON.stringify({type:'journey',environment:env,target,reason:'NO_EXACT_STATION_SEARCH_MATCH'}));continue;
  }
  const result=await call(api,{action:'journey',coreOnly:'1',from:origin.id,fromName:origin.name,to:destination.id,toName:destination.name});
  console.log(JSON.stringify({type:'journey',environment:env,target,
   fromId:origin.id,toId:destination.id,result:safeRoute(result)}));
 }
}
console.log('Read-only alias probe completed.');
