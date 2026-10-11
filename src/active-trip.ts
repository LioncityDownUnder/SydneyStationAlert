/**
 * Browser persistence for a commuter-confirmed journey.
 * The storage key is supplied by the caller so QA and production remain isolated.
 * Storage errors are deliberately non-fatal; an unavailable localStorage must not
 * interrupt an in-progress journey.
 */
// @ts-nocheck
export function activeTripExpiry(journey, now=Date.now()){
 const arrival=journey?.legs?.[journey.legs.length-1]?.arrival;
 const timestamp=arrival?+new Date(arrival):NaN;
 return Number.isFinite(timestamp)?timestamp+4*60*60*1000:now+8*60*60*1000;
}

export function saveConfirmedTrip(storage,key,journey,now=Date.now()){
 if(!journey)return;
 try{
  storage.setItem(key,JSON.stringify({
   version:1,savedAt:now,expiresAt:activeTripExpiry(journey,now),journey
  }));
 }catch{}
}

export function clearConfirmedTrip(storage,key){
 try{storage.removeItem(key);}catch{}
}

export function loadConfirmedTrip(storage,key,now=Date.now()){
 try{
  const raw=storage.getItem(key);
  if(!raw)return null;
  const persisted=JSON.parse(raw);
  if(!persisted||persisted.version!==1||!persisted.journey
    ||!Array.isArray(persisted.journey.legs)
    ||!Array.isArray(persisted.journey.stops)
    ||Number(persisted.expiresAt)<=now){
   clearConfirmedTrip(storage,key);
   return null;
  }
  return persisted.journey;
 }catch{
  clearConfirmedTrip(storage,key);
  return null;
 }
}
