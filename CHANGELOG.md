# Changelog

## Unreleased

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
