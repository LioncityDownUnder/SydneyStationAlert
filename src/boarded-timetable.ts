/**
 * SSA-01: apply TfNSW timetable values only to the commuter's EXACT selected
 * trip IDs and unchanged stopping sequence. This module never selects trains.
 */
export interface BoardedStop {
  id: string | number;
  arrival?: string | null;
  departure?: string | null;
  plannedArrival?: string | null;
  plannedDeparture?: string | null;
  platform?: string | null;
  [key: string]: unknown;
}
export interface BoardedLeg {
  id?: string;
  mode: string;
  line?: string;
  tripIds?: readonly (string | number)[];
  origin: BoardedStop;
  destination: BoardedStop;
  stops: BoardedStop[];
  arrival?: string | null;
  departure?: string | null;
  platform?: string | null;
  [key: string]: unknown;
}
export interface BoardedJourney {
  id: string;
  origin: BoardedStop;
  destination: BoardedStop;
  legs: BoardedLeg[];
  stops: BoardedStop[];
  boardedDepartureAnchor?: string;
  timingVerifiedAt?: string;
  [key: string]: unknown;
}
const id = (value: string | number | null | undefined) => String(value ?? '');
const sameStops = (a: readonly BoardedStop[], b: readonly BoardedStop[]) =>
  Array.isArray(a) && Array.isArray(b) && a.length === b.length && a.length >= 2 &&
  a.every((s, i) => id(s.id) !== '' && id(s.id) === id(b[i]?.id));
const validIds = (leg: BoardedLeg) =>
  (leg.tripIds || []).map(value => id(value).trim().toLowerCase()).filter(Boolean);
export function canCheckBoardedTimetable(journey: BoardedJourney | null | undefined): boolean {
  return !!journey?.legs?.length && journey.legs.every(leg =>
    validIds(leg).length > 0 && sameStops(leg.stops, leg.stops));
}
export function selectedTimetableRequest(journey: BoardedJourney) {
  const anchor = journey.boardedDepartureAnchor ||
    journey.legs[0]?.departure || journey.legs[0]?.origin?.departure;
  return {
    anchor,
    legs: journey.legs.map(leg => ({
      mode: leg.mode, originId: id(leg.origin.id), destinationId: id(leg.destination.id),
      tripIds: (leg.tripIds || []).slice(0, 8), stopIds: leg.stops.map(stop => id(stop.id))
    }))
  };
}
const patchStop = (oldStop: BoardedStop, next: BoardedStop): BoardedStop => ({
  ...oldStop,
  ...Object.fromEntries(
    (['arrival', 'departure', 'plannedArrival', 'plannedDeparture', 'platform'] as const)
      .filter(key => next[key] !== undefined && next[key] !== null && next[key] !== '')
      .map(key => [key, next[key]])
  )
});
export function mergeVerifiedBoardedTimetable(
  current: BoardedJourney, candidate: BoardedJourney,
  verifiedAt: string
): BoardedJourney | null {
  if (!canCheckBoardedTimetable(current) || !candidate ||
      current.legs.length !== candidate.legs?.length ||
      !sameStops(current.stops, candidate.stops) ||
      id(current.origin.id) !== id(candidate.origin?.id) ||
      id(current.destination.id) !== id(candidate.destination?.id)) return null;
  const anchor = Date.parse(selectedTimetableRequest(current).anchor || '');
  const changed = Date.parse(candidate.legs[0]?.departure ||
    candidate.legs[0]?.origin?.departure || '');
  if (!Number.isFinite(anchor) || !Number.isFinite(changed) ||
      Math.abs(changed - anchor) > 2 * 3600 * 1000) return null;
  for (let i = 0; i < current.legs.length; i++) {
    const before = current.legs[i], after = candidate.legs[i];
    if (!after || before.mode !== after.mode ||
        id(before.origin.id) !== id(after.origin.id) ||
        id(before.destination.id) !== id(after.destination.id) ||
        !sameStops(before.stops, after.stops)) return null;
    const expected = validIds(before), actual = validIds(after);
    if (!actual.length || !expected.some(value => actual.includes(value))) return null;
  }
  return {
    ...current,
    boardedDepartureAnchor: selectedTimetableRequest(current).anchor,
    timingVerifiedAt: verifiedAt,
    fetchedAt: verifiedAt,
    legs: current.legs.map((before, i) => {
      const after = candidate.legs[i]!;
      return {
        ...before,
        origin: patchStop(before.origin, after.origin),
        destination: patchStop(before.destination, after.destination),
        stops: before.stops.map((stop, n) => patchStop(stop, after.stops[n]!)),
        departure: after.departure || before.departure,
        arrival: after.arrival || before.arrival,
        platform: after.platform || before.platform
      };
    }),
    stops: current.stops.map((stop, i) => patchStop(stop, candidate.stops[i]!))
  };
}

/** Positive delay derived only from TfNSW estimated vs planned timestamps. */
export function estimatedStopDelayMinutes(stop: BoardedStop, first: boolean): number | null {
  const planned = first ? stop.plannedDeparture : stop.plannedArrival;
  const estimated = first ? stop.departure : stop.arrival;
  if (!planned || !estimated) return null;
  const delta = (Date.parse(estimated) - Date.parse(planned)) / 60000;
  return Number.isFinite(delta) && delta >= 1 ? Math.round(delta) : null;
}
