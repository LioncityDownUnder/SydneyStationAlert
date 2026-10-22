# Production release candidate review — 2026-10-18

**Status: planning only; NOT deployable.** This branch starts from production `main`. It contains no QA application feature changes. Do not merge this PR as a release.

## Evidence
- QA staging deployments #319 and #320: successful (user screenshot).
- Manual QA: 10/16 checks passed, zero reported failures; six explicitly deferred to a future onboard test (connecting-train confirmation, refresh/cancel, missed connection, alternative train, offline/reconnect).
- GitHub compare `main...develop`: diverged, develop 227 ahead and 41 behind main. Changes span API, routing, alerts, monitoring, UI, tests and workflows.
- `main` production workflow has stronger PHP module checks and deployed-commit health verification than `develop`. Preserve them.

## Required release engineering
1. Define an explicit set of feature commits/files from `develop` to ship. Do not blindly merge `develop` into `main`.
2. Reconcile main-only changes before porting features; preserve production workflow verification, smoke checks, status workflows, and protected deployment settings.
3. Confirm the Revesby two-minute interchange rule is unchanged.
4. Review QA-only flags and environment gates: connecting-train confirmation is QA-gated; production release needs a conscious product decision on enabling it and separate tests.
5. Prepare a distinct, code-bearing release candidate branch/PR against `main`. Run typecheck, full Node/PHP test suite, candidate build, security/artifact checks, and Chromium tests including live QA where possible.
6. Require explicit production authorization before merging any code into `main`. A push to `main` triggers production deployment.

## Deferred commuter tests
- Confirm onward train boarding
- Refresh after onward boarding
- Cancel confirmation
- Missed connection recovery
- Different-train boarding
- Offline/reconnect

Track outcomes in issue #24. These are deferred, not passed.
