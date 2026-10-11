import { progress } from './logic.js';

/**
 * Pure progress and interchange-risk calculations.
 * Live GPS, boarding state, pause state and current time are supplied by the UI
 * coordinator. These helpers do not refresh journeys or select replacement trains.
 */
export interface MonitoringStation {
  id?: string | number;
  name?: string;
  lat?: number;
  lon?: number;
  arrival?: string | null;
  departure?: string | null;
}

export interface MonitoringLeg {
  arrival?: string | null;
  departure?: string | null;
  origin?: MonitoringStation | null;
  destination?: MonitoringStation | null;
}

export interface MonitoringJourney {
  stops: MonitoringStation[];
  transfers: MonitoringStation[];
  legs: MonitoringLeg[];
}

export interface MonitoringPosition {
  lat: number;
  lon: number;
}

export interface ConnectionRisk {
  index: number;
  name: string;
  arrival: number;
  departure: number;
}

/**
 * Matches the original three-minute warning threshold and 45-minute window.
 * It is only a warning; it never substitutes or reselects the onboard service.
 */
export function findConnectionAtRisk(
  journey: Pick<MonitoringJourney, 'legs'> | null | undefined,
  onboard: boolean,
  paused: boolean,
  now = Date.now()
): ConnectionRisk | null {
  if (!onboard || paused || !journey || !Array.isArray(journey.legs)) return null;
  for (let i = 1; i < journey.legs.length; i++) {
    const previous = journey.legs[i - 1];
    const next = journey.legs[i];
    const arrival = Date.parse(previous?.destination?.arrival || previous?.arrival || '');
    const departure = Date.parse(next?.origin?.departure || next?.departure || '');
    if (!Number.isFinite(arrival) || !Number.isFinite(departure)) continue;
    // Three minutes is a conservative planning buffer, not a platform guarantee.
    if (arrival + 3 * 60000 > departure && now < departure + 45 * 60000)
      return { index: i, name: next?.origin?.name || 'the interchange', arrival, departure };
  }
  return null;
}

/**
 * GPS progress takes priority while onboard; when GPS cannot place the train,
 * use scheduled stop timestamps without counting bypassed intermediate stations.
 */
export function calculateDisplayedProgress(
  journey: MonitoringJourney,
  location: MonitoringPosition | null,
  onboard: boolean,
  now = Date.now()
) {
  const gps = progress(journey, location);
  if (!onboard) return progress(journey, null);
  if (gps.nearest) return gps;
  let index = 0;
  journey.stops.forEach((stop, i) => {
    if (i > 0 && i < journey.stops.length - 1 && !stop.arrival && !stop.departure) return;
    const time = stop.arrival || stop.departure;
    const timestamp = time ? Date.parse(time) : NaN;
    if (Number.isFinite(timestamp) && timestamp <= now) index = i;
  });
  const count = (from: number, to: number) =>
    journey.stops.slice(from + 1, to + 1).filter(
      (stop, i) => from + i + 1 === journey.stops.length - 1 || stop.arrival || stop.departure
    ).length;
  const next = (journey.transfers || [])
    .map(transfer => journey.stops.findIndex(
      stop => stop.id === transfer.id || stop.name === transfer.name
    )).find(i => i > index);
  return {
    index,
    remaining: count(index, journey.stops.length - 1),
    toChange: next === undefined ? null : count(index, next),
    nearest: null
  };
}
