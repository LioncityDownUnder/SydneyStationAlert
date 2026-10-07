// @ts-nocheck
export const GEO = { options: { enableHighAccuracy: true, timeout: 14000, maximumAge: 15000 }, nearbyMetres: 2500, progressMetres: 1700 };
export const REFRESH_MS = 60_000;
export const SEARCH_DEBOUNCE_MS = 220;
export const STATION_CACHE_MS = 12 * 60 * 60 * 1000;
export const TZ = 'Australia/Sydney';
export function distanceMetres(a, b) { const rad = Math.PI / 180; const dLat = (b.lat - a.lat) * rad; const dLon = (b.lon - a.lon) * rad; const h = Math.sin(dLat / 2) ** 2 + Math.cos(a.lat * rad) * Math.cos(b.lat * rad) * Math.sin(dLon / 2) ** 2; return 6371000 * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h)); }
