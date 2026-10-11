/**
 * SSA-03: the backend is the source of exact vehicle matches; the browser
 * independently refuses wrong-trip, stale, future or malformed occupancy.
 * Never match crowding by line, departure time or proximity.
 */
export interface SelectedCrowdingLeg {
  mode: string;
  tripIds?: Array<string | number>;
}
export interface IncomingCarriage {
  level?: string;
  position?: number | string | null;
  name?: string | null;
  [key: string]: unknown;
}
export interface IncomingCrowdingLeg {
  legId?: string;
  mode?: string;
  tripId?: string | null;
  vehicleMatched?: boolean;
  level?: string;
  updatedAt?: string | null;
  carriages?: IncomingCarriage[];
  availability?: string;
  [key: string]: unknown;
}
export interface IncomingCrowding {
  available?: boolean;
  level?: string;
  updatedAt?: string | null;
  checkedAt?: string | null;
  legs?: IncomingCrowdingLeg[];
  [key: string]: unknown;
}
const known = ['quiet','moderate','busy','very_busy'] as const;
const ageLimit = 5 * 60 * 1000;
const observationRecent = (timestamp: unknown, now: number): boolean => {
  if (typeof timestamp !== 'string' || !timestamp) return false;
  const ts=Date.parse(timestamp);
  return Number.isFinite(ts) && ts >= now-ageLimit && ts <= now+60000;
};
const normalizedId = (x: string | number | null | undefined): string =>
  String(x ?? '').trim().toLowerCase();
const recognized = (level: unknown): level is typeof known[number] =>
  typeof level === 'string' && known.some(item=>item===level);

export function sanitizedSelectedCrowding(
  journey: { legs?: SelectedCrowdingLeg[] } | null | undefined,
  response: IncomingCrowding | null | undefined,
  now=Date.now()
): IncomingCrowding {
  const unavailable: IncomingCrowding={available:false,level:'unknown',updatedAt:null,legs:[]};
  const selected=journey?.legs;
  if (!Array.isArray(selected)||!selected.length||!Array.isArray(response?.legs)||
    selected.length!==response.legs.length) return unavailable;
  const legs: IncomingCrowdingLeg[]=[];
  let overall='unknown',rank=-1,newest=0;
  for (let i=0;i<selected.length;i++){
    const service=selected[i],record=response.legs[i];
    if(!service||!record||record.legId!==`leg-${i}`||service.mode!==record.mode) return unavailable;
    const selectedIds=(service.tripIds||[]).map(normalizedId).filter(Boolean);
    const matchedId=normalizedId(record.tripId);
    // Malformed or mismatched metadata invalidates the whole server payload.
    // It cannot establish that the vehicle belongs to the boarded train.
    if(record.vehicleMatched && (!matchedId||!selectedIds.includes(matchedId)))return unavailable;
    if(!record.vehicleMatched && matchedId) return unavailable;
    const fresh=record.vehicleMatched&&observationRecent(record.updatedAt,now);
    const level=fresh&&recognized(record.level)?record.level:'unknown';
    const carriages=fresh&&Array.isArray(record.carriages)?record.carriages.filter(car=>
      car&&recognized(car.level)).map(car=>({...car})):[];
    const dated=fresh?record.updatedAt:null;
    const shown:IncomingCrowdingLeg={...record,level,carriages,updatedAt:dated,
      vehicleMatched:Boolean(fresh),tripId:fresh?matchedId:null};
    legs.push(shown);
    if(fresh){
      const ms=Date.parse(record.updatedAt!);if(ms>newest)newest=ms;
      const score=known.indexOf(level as typeof known[number]);
      if(score>rank){rank=score;overall=level;}
    }
  }
  return {available:rank>=0,level:overall,updatedAt:newest?new Date(newest).toISOString():null,
    checkedAt:response?.checkedAt||null,legs};
}

/**
 * Re-check at RENDER time. A saved journey must not continue to show occupancy
 * after the phone has slept, the browser was closed, or the vehicle feed aged.
 */
export function crowdingCurrentlyVisible(
  data: IncomingCrowding | null | undefined, now=Date.now()
): boolean {
  return Boolean(data?.available && Array.isArray(data.legs) &&
    data.legs.some(leg=>leg.vehicleMatched&&recognized(leg.level)&&
      observationRecent(leg.updatedAt,now)));
}
