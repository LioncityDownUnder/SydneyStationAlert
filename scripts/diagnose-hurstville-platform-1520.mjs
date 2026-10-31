/**
 * READ ONLY: inspect normalized platform provenance for the Hurstville 3:20 pm
 * train toward Circular Quay. Do not write to either application environment.
 * Redact trip IDs; stop IDs only appear if necessary for platform provenance.
 */
const bases={production:"https://trains.nytnetwork.work/api/index.php",qa:"https://trains.nytnetwork.work/qatest/api/index.php"};
const params={
 action:"boarding-options",from:"222010",to:"200020",fromName:"Hurstville",toName:"Circular Quay"
};
const fmt=v=>v?new Intl.DateTimeFormat("en-AU",{timeZone:"Australia/Sydney",hour:"2-digit",minute:"2-digit",hour12:false}).format(new Date(v)):"";
function summary(route){
 const legs=route.legs||[];
 return {
  firstDeparture:fmt(legs[0]?.departure),
  destination:route.destination?.name,
  legs:legs.map(x=>({line:x.line,origin:x.origin?.name,originStopId:x.origin?.id,
   originPlatform:x.origin?.platform,firstStopPlatform:x.stops?.[0]?.platform,
   firstStopId:x.stops?.[0]?.id,lastStopPlatform:x.stops?.at(-1)?.platform,
   lastStopId:x.stops?.at(-1)?.id,departure:fmt(x.departure),arrival:fmt(x.arrival)})),
  stops:(route.stops||[]).map(s=>({name:s.name,stopId:s.id,platform:s.platform,
     time:fmt(s.departure||s.arrival)}))
 };
}
for(const [env,base] of Object.entries(bases)){
 for(const action of ["boarding-options","journey"]){
  const url=new URL(base);
  Object.entries({...params,action,...(action==="journey"?{coreOnly:"1"}:{})}).forEach(([k,v])=>url.searchParams.set(k,v));
  const now=Date.now();
  try{
   const response=await fetch(url,{signal:AbortSignal.timeout(45000),cache:"no-store",headers:{Accept:"application/json"}});
   const result=await response.json();
   const data=result.data;
   const routes=Array.isArray(data)?data:data?.legs?[data]:[];
   console.log(JSON.stringify({type:"journey-platform-probe",env,action,http:response.status,elapsedMs:Date.now()-now,
    checkedSydney:new Intl.DateTimeFormat("en-AU",{timeZone:"Australia/Sydney",dateStyle:"full",timeStyle:"medium"}).format(new Date()),
    errorCode:result.error?.code,routeCount:routes.length,
    routes:routes.slice(0,8).map(summary)}));
  }catch(e){console.log(JSON.stringify({env,action,error:String(e.name||e.message)}));}
 }
}
