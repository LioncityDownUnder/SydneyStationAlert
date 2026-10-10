// @ts-nocheck
export class ApiRequestError extends Error {
    code;
    constructor(code, message) {
        super(message);
        this.code = code;
        this.name = 'ApiRequestError';
    }
}
async function get(path, params, signal, timeoutMs = 17000) { const q = new URLSearchParams(params); const c = new AbortController(); let timedOut=false; const t = setTimeout(() => { timedOut=true; c.abort(); }, timeoutMs); const cancel = () => c.abort(); signal?.addEventListener('abort', cancel, { once: true }); try {
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
catch (e) {
    if (e?.name === 'AbortError') {
        if (timedOut) throw new ApiRequestError('UPSTREAM_UNAVAILABLE', path === 'journey' ? 'Journey search took too long. Please try again.' : 'Transport data took too long to respond. Please try again.');
        throw e;
    }
    throw e;
}
finally {
    clearTimeout(t);
    signal?.removeEventListener('abort', cancel);
} }
export const searchStations = (q, signal) => get('stations', { q }, signal);
export const preloadStations = (signal) => get('station-index', {}, signal);
export const nearbyStations = (point) => get('nearby', { lat: String(point.lat), lon: String(point.lon) });
export const getJourney = (from, to, coreOnly = false) => get('journey', { from: from.id, to: to.id, fromName: from.name, toName: to.name, ...(coreOnly?{coreOnly:'1',...((window.location.pathname === '/qatest' || window.location.pathname.startsWith('/qatest/'))  ? {qaRailPilot:'1'} : {})}:{}) }, undefined, coreOnly ? ((window.location.pathname === '/qatest' || window.location.pathname.startsWith('/qatest/')) ? 32000 : 20000) : 30000);

// QA-only: full normalized journeys that the commuter may explicitly confirm.
export const getBoardingOptions = (from,to) => get('boarding-options',{from:from.id,to:to.id,fromName:from.name,toName:to.name},undefined,32000);
