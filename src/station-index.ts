// Station-index storage and matching are independent of journey/UI state.
// @ts-nocheck
export function loadStationIndex(storage,key,maxAgeMs,now=Date.now()){
 try{
  const raw=storage.getItem(key);
  if(!raw)return [];
  const parsed=JSON.parse(raw);
  if(!parsed||!Array.isArray(parsed.stations)||now-Number(parsed.savedAt)>maxAgeMs){
   storage.removeItem(key);return [];
  }
  return parsed.stations;
 }catch{return [];}
}

export function saveStationIndex(storage,key,stations,now=Date.now()){
 try{storage.setItem(key,JSON.stringify({savedAt:now,stations}));}catch{}
}

export function findStationMatches(stations,query){
 const n=query.trim().toLocaleLowerCase();
 if(n.length<2)return [];
 return stations.filter(s=>s.name.toLocaleLowerCase().includes(n))
  .sort((a,b)=>{
   const an=a.name.toLocaleLowerCase(),bn=b.name.toLocaleLowerCase();
   const ap=an.startsWith(n)?0:1,bp=bn.startsWith(n)?0:1;
   return ap-bp||a.name.localeCompare(b.name);
  }).slice(0,12);
}

export function mergeStationEntries(existing,incoming){
 if(!incoming.length)return existing;
 const map=new Map(existing.map(s=>[s.id,s]));
 for(const s of incoming)map.set(s.id,s);
 return [...map.values()];
}
