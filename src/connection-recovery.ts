/**
 * SSA-02: pure, conservative missed-connection candidate and recovery logic.
 * A live delay may trigger a suggestion, never a silent change of boarded train.
 */
export interface RecoveryStop {
  id?: string | number;
  name?: string;
  mode?: string;
  arrival?: string | null;
  departure?: string | null;
  plannedArrival?: string | null;
  plannedDeparture?: string | null;
  platform?: string | null;
  [key: string]: unknown;
}
export interface RecoveryLeg {
  id?: string;
  mode?: string;
  line?: string;
  tripIds?: (string | number)[];
  origin?: RecoveryStop;
  destination?: RecoveryStop;
  stops?: RecoveryStop[];
  arrival?: string | null;
  departure?: string | null;
  [key: string]: unknown;
}
export interface RecoveryJourney {
  id: string;
  origin: RecoveryStop;
  destination: RecoveryStop;
  legs: RecoveryLeg[];
  stops: RecoveryStop[];
  transfers?: RecoveryStop[];
  fetchedAt?: string;
  timingVerifiedAt?: string;
  [key: string]: unknown;
}
export interface RecoveryRisk {
  index: number;
  name: string;
  arrival: number;
  departure: number;
}
const minutes = 60000;
const time = (v: string | null | undefined): number => Date.parse(v || '');
const stopId = (stop: RecoveryStop | undefined): string => String(stop?.id ?? '');
const lastArrival = (leg: RecoveryLeg): number =>
  time(leg.destination?.arrival || leg.arrival);
const firstDeparture = (leg: RecoveryLeg): number =>
  time(leg.origin?.departure || leg.departure);
const sameId = (a: RecoveryStop | undefined, b: RecoveryStop | undefined): boolean =>
  stopId(a) !== '' && stopId(a) === stopId(b);

/** Require at least three minutes to change, measured from the expected arrival. */
export function earliestCatchableDeparture(
  prior: RecoveryLeg[], requestedAt: number, bufferMinutes = 3
): number | null {
  const last = prior[prior.length - 1];
  const arrival = last ? lastArrival(last) : NaN;
  if (!last || !Number.isFinite(arrival) || !Number.isFinite(requestedAt)) return null;
  return Math.max(requestedAt, arrival + bufferMinutes * minutes);
}

export function validOnwardConnection(
  route: RecoveryJourney | null | undefined,
  transfer: RecoveryStop, destination: RecoveryStop,
  prior: RecoveryLeg[], notBefore: number
): boolean {
  if (!route || !Array.isArray(route.legs) || !route.legs.length ||
      !Number.isFinite(notBefore) || !sameId(route.origin,transfer) ||
      !sameId(route.destination,destination)) return false;
  const required = earliestCatchableDeparture(prior,notBefore);
  if (required === null) return false;
  let minimum = required;
  for (const leg of route.legs) {
    if ((leg.mode !== 'train' && leg.mode !== 'metro') || !leg.origin || !leg.destination) return false;
    const depart = firstDeparture(leg), arrival = lastArrival(leg);
    if (!Number.isFinite(depart) || !Number.isFinite(arrival) ||
        depart < minimum || arrival <= depart ||
        !Array.isArray(leg.stops) || leg.stops.length < 2 ||
        !sameId(leg.stops[0],leg.origin) ||
        !sameId(leg.stops[leg.stops.length - 1],leg.destination)) return false;
    minimum = arrival + 2 * minutes;
  }
  for (let i = 1; i < route.legs.length; i++) {
    if (!sameId(route.legs[i - 1]?.destination,route.legs[i]?.origin)) return false;
  }
  return sameId(route.legs[0]?.origin,transfer) &&
    sameId(route.legs[route.legs.length - 1]?.destination,destination);
}

/**
 * Require RECENT, exact-trip-verified TfNSW estimated lateness (not GPS
 * guesses or the old saved schedule) before automatic *suggestion*.
 */
export function verifiedLateConnectionRisk(
  journey: RecoveryJourney | null | undefined, risk: RecoveryRisk | null,
  now = Date.now()
): boolean {
  if (!journey || !risk || risk.index < 1 || !journey.legs?.[risk.index] ||
      !Number.isFinite(now)) return false;
  const verification = time(journey.timingVerifiedAt);
  if (!Number.isFinite(verification) || verification > now + minutes ||
      now - verification > 5 * minutes) return false;
  const previous = journey.legs[risk.index - 1];
  const connecting = journey.legs[risk.index];
  if (!previous || !connecting) return false;
  const planned = time(previous.destination?.plannedArrival);
  const estimated = lastArrival(previous);
  const connection = firstDeparture(connecting);
  if (![planned, estimated, connection].every(Number.isFinite)) return false;
  return estimated - planned >= minutes &&
    planned + 3 * minutes <= connection &&
    estimated + 3 * minutes > connection &&
    now < connection + 45 * minutes;
}

/** Keep confirmed earlier legs and original boarding anchor; swap only onward legs. */
export function buildRecoveredJourney(
  existing: RecoveryJourney, legIndex: number,
  onward: RecoveryJourney, requestedAt: number
): RecoveryJourney | null {
  if (!Number.isInteger(legIndex) || legIndex < 1 || legIndex >= existing.legs.length) return null;
  const prior = existing.legs.slice(0,legIndex);
  const transfer = existing.legs[legIndex]?.origin;
  const earliest = earliestCatchableDeparture(prior,requestedAt);
  if (!transfer || earliest === null ||
      !validOnwardConnection(onward,transfer,existing.destination,prior,earliest)) return null;
  const legs = [...prior, ...onward.legs];
  const stops: RecoveryStop[] = [];
  for (const leg of legs) {
    for (const station of leg.stops || [leg.origin,leg.destination]) {
      if (!station) continue;
      const previous = stops[stops.length - 1];
      if (previous && sameId(previous,station)) {
        stops[stops.length - 1] = {
          ...previous,
          ...station,
          arrival: previous.arrival || station.arrival || null,
          departure: station.departure || previous.departure || null
        };
      } else stops.push({...station});
    }
  }
  const transfers: RecoveryStop[] = [];
  for (let i = 1; i < legs.length; i++) {
    const arrived = legs[i - 1]?.destination, departed = legs[i]?.origin;
    if (!arrived || !departed) return null;
    transfers.push({...departed,arrivalPlatform:arrived.platform,departurePlatform:departed.platform});
  }
  return {
    ...existing,
    legs, stops, transfers,
    id: existing.id+'-recovered-'+requestedAt,
    fetchedAt: new Date(requestedAt).toISOString(),
    // Never leave crowding associated with a now-removed onward train.
    crowding:{available:false,level:'unknown',legs:[]},
    timingVerifiedAt:undefined,
    recoveryRequestedAt:requestedAt
  };
}
