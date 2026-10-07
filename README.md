# Sydney Station Alert

Mobile-first, accessible Sydney Trains / Sydney Metro rail journey utility. Warm neutral **Platform Signal** design. Built with strict TypeScript, zero client runtime dependencies, a PHP 8+ server-side TfNSW API proxy, automated Node and PHP tests, and a static SVG route map.

## Requirements

- Node.js 20+ and TypeScript 5+
- PHP 8.2+ with `curl`, `mbstring`, JSON and HTTPS enabled
- TfNSW Open Data Trip Planner API key
- HTTPS for geolocation and notification permissions on phones

## Development

1. Clone the repository and run `npm install`.
2. Run `npm run check` to typecheck, test, and build.
3. Set `TFNSW_API_KEY` as a server environment variable, or create `api/config.local.php` returning `['TFNSW_API_KEY' => 'your key']`. This file is Git-ignored.
4. Run `php -S localhost:8080 -t dist` and visit `http://localhost:8080`.
5. Check `http://localhost:8080/api/index.php?action=health`.

`npm run typecheck` checks TypeScript; `npm test` runs frontend/source regression checks; `php tests/backend-test.php` runs provider/parser fixtures; `npm run build` produces `dist/`.

## Hosting deployment

1. Confirm PHP 8.2+, `curl`, `mbstring`, `mod_rewrite`, and HTTPS.
2. Run `npm run build`.
3. Upload the contents of `dist/` into the cPanel document root.
4. Configure `TFNSW_API_KEY` only on the server. Never commit `config.local.php`, `.env`, or real credentials.
5. Verify `/api/index.php?action=health`, station search, live routing, geolocation, and notifications.

## Behaviour and accuracy

- Station search covers Sydney Trains and Sydney Metro and filters out non-rail stops.
- Journey routing uses TfNSW as the live source while independently validating/ranking complete rail-only itineraries.
- The v17 backend consumes full TfNSW rail `stopSequence` data to preserve intermediate stations.
- Multi-line journeys can cross-check plausible interchange stations and onward rail availability.
- Bus, ferry and coach legs are excluded from rail route progress; pedestrian interchange links are allowed.
- Platform codes are normalized to passenger-readable values where possible.
- Journey monitoring refreshes automatically and supports manual refresh and pause/resume.
- The route map is a self-contained SVG and uses provider geometry when available.
- Times are displayed in the Australia/Sydney timezone.

## Security

- `TFNSW_API_KEY` stays server-side.
- Server inputs are validated and raw TfNSW payloads are normalized before reaching the browser.
- `api/config.local.php`, build output, caches, logs and secrets are excluded from version control.

## Current baseline

This repository baseline combines the **locked v16 UI** with the **v17 validated rail-routing backend**.

The v16 visual system is considered locked unless a future change explicitly requests a UI revision.
