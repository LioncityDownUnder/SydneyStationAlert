/** Read-only comparison of parent station labels at both airport stations. */
const envs={
 qa:'https://trains.nytnetwork.work/qatest/api/index.php',
 production:'https://trains.nytnetwork.work/api/index.php'
};
async function call(base,params,timeout=45000){
 const url=new URL(base);
 Object.entries(params).forEach(([k,v])=>url.searchParams.set(k,String(v)));
 const t=Date.now();
 try{
  const response=await fetch(url,{signal:AbortSignal.timeout(timeout),cache:'no-store'});
  const data=await response.json().catch(()=>null);
  return {http:response.status,elapsedMs:Date.now()-t,
   error:data?.error?{code:data.error.code,message:data.error.message}:null,
   route:data?.data?.legs?.map(l=>({line:l.line,from:l.origin?.name,to:l.destination?.name}))||null};
 }catch(e){return {http:null,elapsedMs:Date.now()-t,error:String(e.name||e.message)};}
}
for(const [env,base] of Object.entries(envs)){
 for(const label of ['Domestic Airport','Sydney Domestic Airport','International Airport','Sydney International Airport']){
  const u=new URL(base);u.searchParams.set('action','stations');u.searchParams.set('q',label);
  const r=await fetch(u,{signal:AbortSignal.timeout(15000)});
  const data=await r.json().catch(()=>null);
  const matches=Array.isArray(data?.data)?data.data.filter(s=>s.name.toLowerCase()===label.toLowerCase()):[];
  console.log(JSON.stringify({type:'station-identity',env,label,http:r.status,matches:matches.map(x=>({id:x.id,name:x.name}))}));
  if(!matches.length)continue;
  const stop=matches[0];
  const journey=await call(base,{action:'journey',from:'200060',fromName:'Central',to:stop.id,toName:stop.name,coreOnly:'1'});
  console.log(JSON.stringify({type:'journey',env,from:'Central',to:label,stopId:stop.id,result:journey}));
 }
}
