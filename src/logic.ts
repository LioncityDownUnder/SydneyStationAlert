// @ts-nocheck
import { distanceMetres, GEO } from './constants.js';
export function nearestStation(stations, position, maxDistance = GEO.nearbyMetres) { let candidate = null; let best = maxDistance; for (const station of stations) {
    const d = distanceMetres(station, position);
    if (d < best) {
        best = d;
        candidate = station;
    }
} return candidate; }
function isStoppingStop(stops, i) { const s = stops[i]; return i === 0 || i === stops.length - 1 || Boolean(s?.arrival || s?.departure); }
function stoppingDistance(stops, fromIndex, toIndex) { let count = 0; for (let i = fromIndex + 1; i <= toIndex; i++) if (isStoppingStop(stops, i)) count++; return count; }
export function progress(journey, position) { const stops = journey.stops; if (!stops.length)
    return { index: 0, remaining: 0, toChange: null, nearest: null }; let index = 0; if (position) {
    let best = Infinity;
    stops.forEach((s, i) => { if (!isStoppingStop(stops, i)) return; const d = distanceMetres(s, position); if (d < best) {
        best = d;
        index = i;
    } });
    if (best > GEO.progressMetres)
        return { index: 0, remaining: stoppingDistance(stops, 0, stops.length - 1), toChange: null, nearest: null };
} const remaining = stoppingDistance(stops, index, stops.length - 1); const nextTransfer = journey.transfers.map(t => stops.findIndex(s => s.id === t.id || s.name.toLowerCase() === t.name.toLowerCase())).find(i => i > index); return { index, remaining, toChange: nextTransfer === undefined ? null : stoppingDistance(stops, index, nextTransfer), nearest: stops[index] ?? null }; }
export function alertKeys(journey, index) { const stops = journey.stops; const keys = []; const remaining = stoppingDistance(stops, index, stops.length - 1); if (remaining === 2)
    keys.push('destination-two'); if (remaining === 1)
    keys.push('destination-one'); journey.transfers.forEach(t => { const ti = stops.findIndex(s => s.id === t.id || s.name === t.name); if (ti >= index && stoppingDistance(stops, index, ti) <= 1)
    keys.push('transfer-' + t.id); }); return keys; }
export function fmtTime(iso) { if (!iso)
    return '—'; const date = new Date(iso); return Number.isNaN(+date) ? '—' : new Intl.DateTimeFormat('en-AU', { timeZone: 'Australia/Sydney', hour: 'numeric', minute: '2-digit', hour12: true }).format(date).toLowerCase(); }
export function escapeHTML(text) { return text.replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c] ?? c)); }
