# S1 Implementation Status

Status: S1 PoC implemented (2026-10-24, branch `feat/s1-poc-full`). Evidence = CI runs linked from the PR; anything not covered there is NOT TESTED.

Scope follows ADR-006 (user-approved 2026-10-24), which supersedes the earlier S1-A defaults in `s1_decision_register.md` (admin-created accounts only, fixed token lifetime).

Implemented:
- `examples/poc_backend`: Laravel 13 + Sanctum + Filament 5 + Spatie on MySQL; self-registration, email verification, phone SMS OTP (hashed, single use, attempt cap, hourly/phone limits, cooldown, cache lock), email/phone + password login, long-lived member tokens with optional idle expiry, revoke-on-logout, admin 7-day sliding idle logout, Filament members / notices / three-tab settings, Mitake / EVERY8D / SMSGO / fake adapters, minimal audit events, Pest tests, HTTP E2E smoke.
- `examples/poc_flutter`: Riverpod + Dio + go_router app; register, email-sent, phone OTP, login, session restore, logout, profile edit, notice list/detail; widget tests and a real Flutter→API→MySQL E2E test in CI.
- `packages/*/shared_core`: used by both PoC hosts (path dependency); registration API and notice detail added to the Dart contract; SMS result carries an error code.

Not done / NOT TESTED: real SMS sending (no provider accounts), Mitake success codes against the official PDF, SMSGO BIG5 body encoding, real email delivery, Android physical device, iOS, password reset, Tiandi integration (S2), multi-App isolation beyond separate deployments.
