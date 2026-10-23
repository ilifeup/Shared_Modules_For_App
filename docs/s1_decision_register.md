# S1 Decision Register — Before Schema and API Implementation

Status: S1-A scope approved by user on 2026-10-23. Security details and precise schema remain subject to separate review.

| Key | Decision required | Recommended PoC default | Status |
|---|---|---|---|
| D-S1-01 | Account provisioning | Filament admin creates synthetic test users; no public registration in PoC | APPROVED (scope) |
| D-S1-02 | Editable profile fields | display_name only; email/phone/verification not editable | APPROVED (scope) |
| D-S1-03 | Notice visibility | authenticated users only; unpublished never visible | APPROVED (scope) |
| D-S1-04 | Notice publish authorization | admin permission via Spatie+Policy; member read only | APPROVED (scope); exact permission rules pending |
| D-S1-05 | Token expiration/session | explicit short-lived test policy and revoke-on-logout; decide exact lifetime | OPEN |
| D-S1-06 | Test environment | disposable GitHub Actions MySQL and local/dev environment only, no paid cloud | APPROVED (scope) |
| D-S1-07 | Package distribution | local path dependencies in PoC; versioned packages later after portability test | OPEN |
| D-S1-08 | Profile schema | only fields necessary for PoC, separate role tables; migration requires review | OPEN |

User explicitly selected option A: admin-created test account → Flutter login → view/edit display name → read admin-published member notices → logout. Public registration, email verification, VIP, booking, check-in and payment are out of scope. This approval does not finalize token lifetime, migration field definitions or additional privileges.

Approval must be recorded by an explicit user decision or linked approved ADR, not inferred from CI PASS. Until then work may proceed on documentation, disposable test harnesses and non-authoritative mock contracts, but not freeze or deploy user/security schema.

Reference: `s1_poc_implementation_design.md`, `s1_poc_acceptance_matrix.md`, `docs/specs/three_apps_shared_modules_spec_v1.md`.
