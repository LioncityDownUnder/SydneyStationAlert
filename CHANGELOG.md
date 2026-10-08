# Changelog

## Unreleased

### Journey performance
- Live timing showed Hurstville → Padstow at ~18.9s and Hurstville → Casula at ~17.1s. Transfer validation now checks only the selected primary interchange, and optional service-alert/crowding feeds fail fast so advisory data cannot hold up the core journey response.
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
