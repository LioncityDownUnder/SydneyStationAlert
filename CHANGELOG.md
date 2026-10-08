# Changelog

## Unreleased

### Service disruption backend
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
