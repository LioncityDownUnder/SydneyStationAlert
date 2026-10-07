# Changelog

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
