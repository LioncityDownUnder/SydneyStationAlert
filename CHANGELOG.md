# Changelog

## 17.1.1 — Airport station identity correction (11 October 2026)

- **SSA-09:** Correct TfNSW platform-only labels: prefer the actual parent station name when a stop's `disassembledName` is only `Platform 1/2`. Domestic Airport and International Airport are shown separately with correct station IDs and platform numbers.
- Do not combine two different airport stops because both originally say `Platform 2`. Retain stop order, original departure/arrival times, stop count, and verified selected-service identity.
- Add deterministic regression fixtures for daytime T8 Circular Quay → International Airport, International Airport → Padstow, reverse International Airport → Circular Quay and Circular Quay → Padstow (both airport stations), including safe fallback when the parent name is missing.
- **Explicitly out of scope:** Revesby two-minute platform interchange, connection thresholds, route selection changes and missed-connection recovery. No frontend or deployment-workflow changes.
- Selectively promote only the exact QA-tested `api/lib/rail-normalization.php`, `tests/backend-test.php`, and `tests/fixtures/airport-daytime.json` from `develop@a09a947` atop production v17.1.0, preserving QA/production isolation and secrets/cache protection. QA CI/deploy/browser/API checks passed. In a separate QA parser check, real TfNSW Monday-noon Circular Quay → International Airport returned four usable journeys with both airport stops; the other three authenticated read-only requests returned HTTP 401, so complete live-feed validation was not claimed.


## 17.1.0 — Selected-train reliability and connection recovery (11 October 2026)

- **SSA-01:** Refresh only an explicitly confirmed train's timetable by strict TfNSW per-leg trip ID, station sequence and saved departure anchor. Show source planned-versus-estimated delay information where verifiable, and retain original times/train instead of silently switching services.
- **SSA-02:** Offer a catchable next rail service after a missed interchange, searching after the actual expected first-leg arrival and three-minute transfer buffer. Proactive risk suggestions still require commuter confirmation before an onward train is changed; preserve already-boarded legs and trip persistence.
- **SSA-03:** Reject stale, future-dated, conflicting or unidentifiable GTFS-Realtime vehicle occupancy. Match only verified exact trip IDs, suppress stale restored crowding after browser suspension and show an honest unavailable fallback.
- **SSA-07:** Show the TfNSW crowding observation age for the actual displayed train/leg, never a refreshed page/request timestamp; hide labels for missing, stale or unverified data.
- **Release discipline:** Selectively backport audited QA application/backend/test changes from `develop@9103931` onto production v17.0.1 rather than merging divergent branches. Preserve production deployment QA-subfolder exclusion, configuration/cache protection, PHP/module artifact checks and deeper trackwork-aware regression smoke.
- **Validation limits:** 122 QA Node tests, PHP fixtures and deployed QA browser/endpoint smokes passed before release candidate; production candidate must pass all gates before release. Exact TfNSW vehicle/trip availability and a genuine delayed multi-leg commuter journey remain field-unverified; no invisible automatic boarding or rail substitution is claimed.


## 17.0.1 — Maintenance baseline (11 October 2026)

- Promote the QA-verified modularisation of backend TfNSW transport, cache, journey routing, rail and station normalization, station discovery, service-alert matching, GTFS-Realtime crowding and performance instrumentation.
- Promote the QA-verified pure frontend modules for local confirmed journeys, selected service identity, station index/markup, crowding, notices, timetable/transfer display, journey monitoring and SVG route-map rendering.
- Retain the existing production rail-only routing, verified boarded-train identity, crowding fallbacks, manual missed-connection flow, UI styling and browser storage semantics. No SSA-01 live selected-train timetable enhancement is included.
- Add comprehensive PHP-module syntax checks and release artifact checks, preserving production/QA directory isolation and protected server configuration.
- Source snapshot: QA `develop` at `99bd681`; production release is from a selective, main-based release branch, not a wholesale develop merge. Automated smoke tests cannot prove arbitrary live TfNSW service data.

## Unreleased

### Performance hardening
- Profiled the slow Hurstville → Padstow core search and found the delay came from sequential future-probe TfNSW calls, not frontend rendering. The fast core path now searches the near-future probe window in parallel and keeps fallback interchange lookups batched. Production regression timing improved to 2.45s for Hurstville → Padstow, with the full smoke matrix passing under the 5-second target.

### Reliability
- Reduce TfNSW journey result volume for the fast core route request so difficult journeys are less likely to exceed the client timeout while preserving the fuller 30-trip search for background enrichment and disruption revalidation.

### Reliability
- Increase the fast core-journey client timeout from 8 seconds to 20 seconds so a slow but still valid TfNSW response is not aborted before the server-side request can finish. The production regression smoke still enforces the normal 5-second performance target.

### UI iteration 17
- Count only scheduled stopping stations in “stops to destination” and transfer countdowns. Express/bypassed stations remain visible in the route list but no longer inflate the remaining-stop count or destination alert thresholds.

### UI iteration 16
- Add an “I’m on this train” confirmation that pins the selected service locally in the browser. Confirmed onboard trips survive hard refreshes and browser restarts, resume monitoring from the saved route, and will not silently switch to a later service during refresh. An “Abandon trip” action clears the saved journey and returns to journey setup. Saved trips expire a few hours after the final arrival time.

### UI iteration 15
- Treat intermediate stations with no TfNSW arrival or departure time as bypassed stops rather than estimating a time. Bypassed stations are greyed out and labelled “Does not stop”.

### UI iteration 14
- Fill gaps in per-station timing when TfNSW omits times for intermediate stops. Missing times are interpolated only when bounded by known stops and are marked with `~` to show they are approximate rather than official timings.

### UI iteration 13
- Show a concise journey time beside each station in the route list. The origin uses departure time; intermediate, transfer, and destination stops use arrival time with departure as a fallback. Platform information stays on the same muted detail line.

### Regression hardening
- Add a production regression smoke suite that runs after successful deployments. It checks production health, direct Sydney Trains and Metro journeys, one- and two-interchange routes, the 5-second core-journey target, rail-only legs, and the full live enrichment response shape.

### UI iteration 12
- Simplify carriage crowding further by removing visible Car 1–Car 8 labels. The coloured carriage bars now stay on a single row and size themselves evenly to the detected train length, while screen-reader labels retain carriage number and crowding status.

### UI iteration 11
- Stabilize live refresh rendering: background refreshes no longer re-render at the start of a fetch, and journey updates preserve the current scroll position. Location updates and pause/resume also keep the page anchored instead of visually jumping.

### UI iteration 10
- Replace variable-size carriage crowding boxes with compact Car 1, Car 2, etc. labels and fixed-width colour bars. Green means Quiet, amber Moderate, and red Busy/Very busy; a small legend explains the colours. The heading now states the detected car count and Metro/train type.

### UI iteration 9
- Fix a crowding render crash that could make Refresh and Pause appear unresponsive after carriage data loaded. Carriage numbers now fall back to Car 1–6 when TfNSW reports position 0.

### UI iteration 8
- Prevent the background live-detail status from appearing stuck: the visible “Checking live service details…” message now clears after five seconds even if optional enrichment continues in the background.

### UI iteration 7
- Make carriage crowding understandable for first-time commuters: label cars as Car 1, Car 2, etc., add a short explanation, and hide the whole-train crowding summary when carriage-level data is available. TfNSW carriage codes remain available only as secondary metadata.

### UI iteration 6
- After the fast core journey appears, show a subtle “Checking live service details…” status in the existing last-checked area while disruption, crowding, and departure details load in the background.

### Performance pass
- Return and render the core rail journey first, before optional disruption, crowding, and departure-monitor enrichment. Optional live details now load in the background so they do not block the initial journey result.
- Trust a valid whole-trip TfNSW rail itinerary before attempting split-route validation. Live production checks after deployment returned the core journey in about 3.2 seconds for Hurstville → Padstow and Hurstville → Casula.

### UI iteration 5
- When a disrupted journey is automatically replaced, explain that the original service was affected and show the replacement route's interchange station(s) when available.

### UI iteration 4
- Add an expandable disruption-details section under the existing service alert, using the current notice styling. It distinguishes a selected-service impact from a broader line/station notice and shows the TfNSW description when available.

### UI cache fix
- Bump frontend asset cache keys so the new carriage crowding styles load immediately instead of rendering as unstyled concatenated text from a stale stylesheet.

### UI iteration 3
- Show a compact carriage-by-carriage crowding row when TfNSW provides usable carriage occupancy. Unknown carriage occupancy stays hidden; no new card or navigation is introduced.

### Journey performance
- Live timing showed Hurstville → Padstow at ~18.9s and Hurstville → Casula at ~17.1s. Transfer validation now checks only the selected primary interchange, and optional service-alert/crowding feeds fail fast so advisory data cannot hold up the core journey response.
- Post-deploy timing improved Hurstville → Padstow to ~7.4s and Hurstville → Casula to ~3.2s while retaining the expected T4 → T8 and T4 → T8 → T2 rail legs.
- Prioritize the selected route's known interchange and cap transfer validation to two candidates, reducing slow multi-call searches such as Hurstville → Padstow while preserving validated interchange routing.
- Journey requests now fail with a friendly timeout message instead of exposing the browser AbortError.

### UI iteration 2
- Show a single journey-level crowding line when TfNSW provides usable occupancy data: Quiet, Moderate, Busy, or Very busy. Unknown crowding remains hidden, with no carriage breakdown or CSS/layout changes.

### UI iteration 1
- Surface relevant service disruptions and automatic reroute notices using the existing alert treatment only; no layout, spacing, typography, or crowding UI changes.

### Crowding backend
- Live production verification confirmed exact-trip crowding matching works for both Sydney Trains and Metro. A live T4 service exposed an 8-car vehicle with occupancy currently unknown, while a live M1 Metro service exposed six carriage occupancy values with moderate/busy levels.
- `available` now means usable occupancy data is actually present; a matched vehicle whose occupancy is unknown is reported with `vehicleMatched: true` but does not falsely claim crowding availability.
- Added transfer, unknown-occupancy, and trip-ID mismatch regression coverage before UI work.
- Read TfNSW GTFS-realtime v2 vehicle-position feeds for Sydney Trains and Metro using the existing server-side API key.
- Parse whole-train and carriage occupancy, including carriage position, quiet-carriage flag, accessible/normal toilet metadata, and luggage-rack availability.
- Match crowding to the exact selected trip using GTFS trip identifiers already retained by the journey engine.
- Normalize occupancy conservatively to quiet, moderate, busy, very_busy, or unknown; unknown data is never fabricated.
- Cache vehicle-position feeds for 15 seconds and fail open when realtime crowding is unavailable.
- Return crowding as backend metadata only; the locked UI is unchanged.

### Service disruption backend
- Materially disrupted selected journeys are now revalidated automatically against the current Trip Planner results. The backend skips exact affected services, preserves the normal fastest-route ranking, and returns revalidation metadata without changing the locked UI.
- Live verification identified TfNSW exact-service identifiers (RealtimeTripId, AVMSTripID, gtfsTripId, and tripCode) in Trip Planner journey legs and exact affected trips in add_info alerts.
- Material trip-scoped disruptions now require an exact selected-trip match, preventing a cancellation or skipped-stop alert for another train on the same T4/T8 line from forcing journey revalidation.
- Live production verification against Hurstville → Central confirmed the TfNSW `add_info` payload uses structured `affected.lines` metadata. The matcher was hardened to use those rail line entities rather than recursively harvesting nested trip/stop data, eliminating unrelated bus/other-line alerts.
- Read current TfNSW Trip Planner Service Alerts through the documented `add_info` endpoint.
- Cache the disruption feed for 60 seconds and fail open if the optional alert endpoint is unavailable.
- Normalize current alerts into info, warning, and major severity levels.
- Match alerts against the selected journey's stations and rail line identifiers so unrelated notices are excluded.
- Flag material changes such as cancellations, skipped stops, early termination, platform changes, suspensions, and station/line closures for journey revalidation.
- Add typed `serviceStatus` metadata to journey responses without changing the locked v16 UI.
- Add regression fixtures for relevant vs unrelated alerts and material stopping-pattern changes.

## 17.0.0 — 2026-10-08

Current production baseline.

- Locked v16 Platform Signal UI and accessibility styling.
- Footer: **Designed for Sydney commuters by Y.T. Ng**.
- 12-hour station metadata cache and autocomplete.
- Passenger-readable platform normalization.
- Rail/metro-only route handling; bus/ferry/coach legs excluded.
- TfNSW class-100 interchange walking supported.
- v17 validated routing engine:
  - full rail stopSequence parsing;
  - intermediate station preservation;
  - transfer-station deduplication;
  - fastest complete rail journey ranking by final arrival;
  - interchange candidate cross-checking and onward-service validation.
- Server-side TfNSW credentials only.
- Namecheap shared-hosting PHP deployment architecture.

The v16 visual system is locked unless a future change explicitly requests a UI revision.
