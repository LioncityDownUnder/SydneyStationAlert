#!/usr/bin/env node
/**
 * Read-only QA journey latency probe. Node 20+; no dependencies.
 * Example:
 * node scripts/diagnose-journey.mjs --from STATION_ID --to STATION_ID --from-name Padstow --to-name "Central Station" --runs 3
 *
 * IDs must come from the QA station picker / API. Do not use production URL.
 */
import { performance } from 'node:perf_hooks';

const args = Object.fromEntries(process.argv.slice(2).flatMap((v,i,a) => v.startsWith('--') && a[i+1] && !a[i+1].startsWith('--') ? [[v.slice(2),a[i+1]]] : []));
const base = args.base || 'https://trains.nytnetwork.work/qatest/';
const from = args.from;
const to = args.to;
const runs = Number(args.runs || 3);
const timeout = Number(args.timeout || 35000);
const includeFull = args['include-full'] === 'true';
if (!from || !to || from === to || !Number.isInteger(runs) || runs < 1 || runs > 10 || !Number.isInteger(timeout) || timeout < 1000 || timeout > 60000) {
  console.error('Usage: node scripts/diagnose-journey.mjs --from STATION_ID --to STATION_ID [--from-name Padstow] [--to-name Destination] [--runs 3] [--include-full true] [--timeout 35000]');
  process.exitCode = 2;
} else {
  const baseUrl = new URL(base);
  if (baseUrl.protocol !== 'https:' || baseUrl.hostname !== 'trains.nytnetwork.work' || !baseUrl.pathname.startsWith('/qatest/')) {
    throw new Error('Safety guard: only the /qatest/ environment is permitted.');
  }
  const results = [];
  for (let i = 0; i < runs; i++) {
    for (const mode of includeFull ? ['core', 'full'] : ['core']) {
      const url = new URL('api/index.php', baseUrl);
      const params = {action:'journey',from,to,fromName:args['from-name']||from,toName:args['to-name']||to};
      if (mode === 'core') params.coreOnly = '1';
      url.search = new URLSearchParams(params).toString();
      const start = performance.now();
      let status = null, error = null, code = null, bytes = null;
      try {
        const response = await fetch(url, {signal:AbortSignal.timeout(timeout), headers:{Accept:'application/json'}, cache:'no-store'});
        status = response.status;
        const body = await response.text();
        bytes = Buffer.byteLength(body);
        try { const parsed = JSON.parse(body); code = parsed?.error?.code || null; }
        catch { error = 'Non-JSON response'; }
      } catch (e) { error = e?.name === 'TimeoutError' ? 'TIMEOUT' : String(e?.message || e); }
      const row = {run:i+1, mode, elapsed_ms:Math.round(performance.now()-start), http_status:status, api_error_code:code, response_bytes:bytes, error};
      results.push(row);
      console.log(JSON.stringify(row));
    }
  }
  for (const mode of includeFull ? ['core','full'] : ['core']) {
    const rows = results.filter(r=>r.mode===mode);
    const sorted = rows.map(r=>r.elapsed_ms).sort((a,b)=>a-b);
    console.log(JSON.stringify({summary:mode, requests:rows.length, median_ms:sorted[Math.floor(sorted.length/2)], max_ms:sorted.at(-1), failures:rows.filter(r=>r.error || r.http_status !== 200).length}));
  }
  if (results.some(r=>r.error || r.http_status !== 200)) process.exitCode=1;
}
