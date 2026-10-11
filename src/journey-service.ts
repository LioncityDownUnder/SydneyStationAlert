/**
 * Pure journey-service helpers.
 *
 * No browser or app state dependencies: callers continue to decide whether
 * a provisional match can be adopted. Confirmed boarding is validated by
 * qaSameSelectedTrain in main.ts and MUST NOT rely on these helpers alone.
 */
export interface JourneyStationIdentity {
  id?: string | number | null;
  departure?: string | null;
}

export interface JourneyLegIdentity {
  tripIds?: readonly (string | number)[] | null;
  line?: string | null;
  origin?: JourneyStationIdentity | null;
  destination?: JourneyStationIdentity | null;
  departure?: string | null;
}

export interface JourneyIdentity {
  legs?: readonly JourneyLegIdentity[] | null;
}

/**
 * Preserves the existing comparison semantics for candidate journeys.
 * When both legs have IDs, they must intersect. When either leg has no IDs,
 * the historical fallback compares line and station IDs instead.
 */
export function sameService(
  a: JourneyIdentity | null | undefined,
  b: JourneyIdentity | null | undefined
): boolean {
  if (!a || !b || !Array.isArray(a.legs) || !Array.isArray(b.legs)
    || a.legs.length !== b.legs.length) return false;
  return a.legs.every((leg, i) => {
    const other = b.legs?.[i];
    const ids = (leg.tripIds || []).map(x => String(x).toLowerCase());
    const otherIds = (other?.tripIds || []).map(x => String(x).toLowerCase());
    if (ids.length && otherIds.length) return ids.some(id => otherIds.includes(id));
    return leg.line === other?.line
      && leg.origin?.id === other?.origin?.id
      && leg.destination?.id === other?.destination?.id;
  });
}

/** Departure of the first journey leg, as Unix milliseconds (NaN if unknown). */
export function firstDepartureTime(j: JourneyIdentity | null | undefined): number {
  return Date.parse(j?.legs?.[0]?.departure || j?.legs?.[0]?.origin?.departure || '');
}
