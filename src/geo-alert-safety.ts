import { distanceMetres } from './constants.js';
import { progress } from './logic.js';

/**
 * A browser can deliver an old or inaccurate geolocation fix after a tab is
 * resumed. Such a fix must never trigger a new stop notification.
 */
export const GPS_FIX_MAX_AGE_MS = 45_000;
export const GPS_MAX_ACCURACY_METRES = 400;
export const GPS_STATION_RADIUS_METRES = 800;

export interface VerifiedLocation {
  lat: number;
  lon: number;
  timestamp: number;
  accuracy: number;
}
export interface BrowserPosition {
  timestamp: number;
  coords: {latitude: number; longitude: number; accuracy: number};
}
export interface StationPoint {
  id?: string | number;
  name?: string;
  lat?: number;
  lon?: number;
  arrival?: string | null;
  departure?: string | null;
}
export interface TrackedJourney {
  stops: StationPoint[];
  transfers: StationPoint[];
}
const validCoordinate = (lat: number, lon: number) =>
  Number.isFinite(lat) && Number.isFinite(lon) &&
  lat >= -90 && lat <= 90 && lon >= -180 && lon <= 180;

export function locationIsFresh(
  location: VerifiedLocation | null | undefined,
  now = Date.now()
): location is VerifiedLocation {
  if (!location || !validCoordinate(location.lat, location.lon)) return false;
  const {timestamp, accuracy} = location;
  if (!Number.isFinite(timestamp) || !Number.isFinite(accuracy) ||
      accuracy < 0 || accuracy > GPS_MAX_ACCURACY_METRES) return false;
  const age = now - timestamp;
  return age >= -5000 && age <= GPS_FIX_MAX_AGE_MS;
}

export function reliableGpsFix(
  position: BrowserPosition | null | undefined,
  now = Date.now()
): VerifiedLocation | null {
  if (!position?.coords) return null;
  const result = {
    lat: position.coords.latitude,
    lon: position.coords.longitude,
    accuracy: position.coords.accuracy,
    timestamp: position.timestamp
  };
  return locationIsFresh(result, now) ? result : null;
}

/**
 * Only a stopping station near a recently verified GPS position can generate
 * an alert. This is intentionally tighter than the map's 1.7 km radius.
 * The caller may also reject any index behind the confirmed progress anchor.
 */
export function nearbyGpsStop(
  journey: TrackedJourney,
  location: VerifiedLocation | null,
  now = Date.now()
): number | null {
  if (!locationIsFresh(location, now) || !Array.isArray(journey?.stops)) return null;
  const p = progress(journey, location);
  if (!p.nearest || !Number.isInteger(p.index)) return null;
  const nearest = journey.stops[p.index];
  if (!Number.isFinite(nearest?.lat) || !Number.isFinite(nearest?.lon)) return null;
  const distance = distanceMetres(nearest, location);
  return Number.isFinite(distance) && distance <= GPS_STATION_RADIUS_METRES
    ? p.index : null;
}

function isStop(stops: StationPoint[], index: number) {
  const point = stops[index];
  return index === 0 || index === stops.length - 1 ||
    Boolean(point?.arrival || point?.departure);
}
function stopCount(stops: StationPoint[], from: number, to: number) {
  let total = 0;
  for (let i = from + 1; i <= to; i++) {
    if (isStop(stops, i)) total++;
  }
  return total;
}

/** Monotonic display at an already verified onboard station (including express routes). */
export function progressAtIndex(journey: TrackedJourney, index: number) {
  const stops = journey.stops;
  if (!stops?.length) return {index:0,remaining:0,toChange:null,nearest:null};
  const safeIndex = Math.max(0, Math.min(stops.length - 1, Math.floor(index)));
  const next = (journey.transfers || [])
    .map(t => stops.findIndex(s => s.id === t.id || s.name === t.name))
    .find(i => i > safeIndex);
  return {
    index:safeIndex,
    remaining:stopCount(stops, safeIndex, stops.length - 1),
    toChange:next === undefined ? null : stopCount(stops, safeIndex, next),
    nearest:stops[safeIndex]
  };
}

/** Restrict persisted alert keys to known keys for this specific saved route. */
export function savedAlertKeys(journey: TrackedJourney & {notifiedAlertKeys?: unknown}) {
  const raw = journey?.notifiedAlertKeys;
  if (!Array.isArray(raw)) return [];
  const allowed = new Set([
    'destination-two',
    'destination-one',
    ...(journey.transfers || []).map(t => 'transfer-' + t.id)
  ]);
  return [...new Set(raw.filter((key): key is string =>
    typeof key === 'string' && allowed.has(key)))];
}
