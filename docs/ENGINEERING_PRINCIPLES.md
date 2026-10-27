# Shared Modules — Highest Engineering Principle

**Project priority: P1 Shared Modules (ilifeup/Shared_Modules_For_App) > P2 Tiandi App (ilifeup/tiandi-app).** Both repositories are active. Shared modules and shared contracts must be reviewed and implemented first where appropriate; domain-specific Tiandi business behavior remains in Tiandi App.

## Mandatory research-first workflow
1. Inspect real code, tests, CI, logs, current dependencies and both repositories' integration boundaries before proposing changes.
2. Research official Laravel/Flutter/package docs, upstream issues, relevant GitHub implementations/discussions and Reddit real-world experience. Check versions, maintenance and known failures.
3. Compare proven alternatives for security, reliability, compatibility, maintenance, deployment cost and licensing; prefer framework-native or mature solutions to speculative custom frameworks.
4. Determine ownership: cross-app reusable contracts, API error envelopes, request correlation, logging interfaces, common Flutter UI/network components belong in Shared Modules when genuinely reusable; Tiandi-only booking, calendar semantics and domain workflows stay in Tiandi App. Do not force business-specific workflows into shared packages.
5. Implement and test Shared Modules changes first; review CI, release/integration compatibility, then adapt Tiandi App. Avoid unreviewed cross-repository breakage.
6. For queue/background work and frontend/backend coordination, cover durable intent, retries, idempotency, timeouts, terminal failures, status APIs, Flutter recovery and end-to-end failure scenarios.
7. Significant PRs must link researched evidence, alternatives, rationale, migration/rollback notes and relevant tests. Do not claim production readiness without worker/scheduler and deployment verification.

**No CI-green-only merge; validate full affected workflows.**
