export type Point = { lat: number; lon: number };
export type Station = Point & { id: string; name: string; mode: 'train'|'metro' };
export type Stop = Station & { platform: string|null; arrival: string|null; departure: string|null };
export type RailLeg = { id:string; mode:'train'|'metro'; line:string; tripIds?:string[]; origin:Stop; destination:Stop; stops:Stop[]; path:Point[]; departure:string|null; arrival:string|null; platform:string|null };
export type ServiceAlertSeverity = 'info'|'warning'|'major';
export type ServiceAlert = {
  id:string;
  severity:ServiceAlertSeverity;
  title:string;
  description:string;
  affectedLines:string[];
  affectedStations:string[];
  affectedTrips:string[];
  startsAt:string|null;
  endsAt:string|null;
  materialChange:boolean;
};
export type ServiceStatus = {
  level:'ok'|'info'|'warning'|'major'|'unavailable';
  hasMaterialChange:boolean;
  updatedAt:string;
  alerts:ServiceAlert[];
  revalidationAttempted?:boolean;
  replacementFound?:boolean;
  originalJourneyId?:string;
};
export type Journey = { id:string; origin:Station; destination:Station; legs:RailLeg[]; stops:Stop[]; transfers:Station[]; omittedNonRail:boolean; fetchedAt:string; source:'live'|'validated'|'demo'; nextDepartures:string[]; serviceStatus?:ServiceStatus };
export type ApiErrorCode='CONFIG_MISSING'|'BAD_REQUEST'|'UPSTREAM_UNAVAILABLE'|'NO_ROUTE'|'NO_STATIONS'|'MALFORMED_RESPONSE'|'RATE_LIMITED';
export type ApiResult<T>={data:T;error?:never}|{data?:never;error:{code:ApiErrorCode;message:string}};
