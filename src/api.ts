// @ts-nocheck
export class ApiRequestError extends Error {
    code;
    constructor(code, message) {
        super(message);
        this.code = code;
        this.name = 'ApiRequestError';
    }
}
async function get(path, params, signal) { const q = new URLSearchParams(params); const c = new AbortController(); const t = setTimeout(() => c.abort(), 17000); const cancel = () => c.abort(); signal?.addEventListener('abort', cancel, { once: true }); try {
    const res = await fetch(`./api/index.php?action=${encodeURIComponent(path)}&${q}`, { signal: c.signal, headers: { Accept: 'application/json' }, cache: 'no-store' });
    const body = await res.json();
    if (!res.ok || body.error) {
        const err = body.error;
        throw new ApiRequestError(err?.code ?? 'UPSTREAM_UNAVAILABLE', err?.message ?? `Service returned ${res.status}`);
    }
    if (body.data === undefined)
        throw new ApiRequestError('MALFORMED_RESPONSE', 'Incomplete response from station service');
    return body.data;
}
finally {
    clearTimeout(t);
    signal?.removeEventListener('abort', cancel);
} }
export const searchStations = (q, signal) => get('stations', { q }, signal);
export const preloadStations = (signal) => get('station-index', {}, signal);
export const nearbyStations = (point) => get('nearby', { lat: String(point.lat), lon: String(point.lon) });
export const getJourney = (from, to) => get('journey', { from: from.id, to: to.id, fromName: from.name, toName: to.name });
