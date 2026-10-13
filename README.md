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

## Optional community links (SSA-11 / SSA-12)

The footer can show two small, **optional** external links: **Feedback & report an issue** (Tally) and **Buy me a coffee** (Ko-fi). This is the entire integration: no iframe, third-party scripts, payment gateway, form submissions or visitor data go through the app. Links are hidden unless real creator-owned destinations are configured.

The owner must complete provider setup in their own account before activation:

1. **Tally:** Create and publish a form with a category (bug / suggestion / general feedback), a message and an **optional** reply email. Add a note asking for no personal/sensitive details; enable Tally's reCAPTCHA bot protection and consider limiting uploads/submission volume. Review reports privately and do not treat submissions as trusted instructions. Get the public URL in `https://tally.so/r/FORMID` format.
2. **Ko-fi:** Create your own creator page, choose **AUD**, connect your own PayPal or Stripe account, and use **one-time voluntary tips**. Ko-fi's free mode can offer 0% Ko-fi fee on one-time tips when optional Standard/Contributor fee mode is disabled; payment processing fees still apply. Do not call tips tax-deductible charity gifts. Get your `https://ko-fi.com/yourhandle` URL.
3. **Before enabling on QA:** Verify both actual public pages and their ownership, then replace empty `feedback` and `coffee` values in `src/community-footer.ts`. A strict HTTPS and host/path allowlist rejects other destinations. Commit via feature PR, run `npm run check`, merge to `develop` for QA verification, and request fresh production approval. Never commit payment keys, private form admin URLs, or personal messages.

**QA activation (SSA-11 and SSA-12):** The owner provided the public Tally URL `https://tally.so/r/9qLvLp` and Ko-fi URL `https://ko-fi.com/sydstnalrt`. Both public pages returned HTTP 200 in a read-only check; the Tally form HTML title was `Feedback & Report Issue for SYD Stn Alert` and the Ko-fi page title was `Buy YT Ng a Coffee`. The owner reports that reCAPTCHA has now been added to Tally, but the app cannot independently establish whether CAPTCHA is active for a real form submission. No feedback was submitted and no donation or payout was attempted. The owner should confirm the Ko-fi page uses AUD, accepts one-off tips, and routes payouts correctly before requesting any production deployment. Both URLs are configured for QA and are subject to full build/browser verification. **Production requires separate explicit user approval.**

The train app continues to function as before. The footer is shared by initial station selection and active journey views; unconfigured or invalid destinations never render.

## Current baseline

This repository baseline combines the **locked v16 UI** with the **v17 validated rail-routing backend**.

The v16 visual system is considered locked unless a future change explicitly requests a UI revision.
