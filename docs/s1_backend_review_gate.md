# S1 Backend Implementation Review Gate

Status: **REQUESTING DESIGN REVIEW**, not production authorization.

User approved the S1-A user journey, not the exact security or persistent schema. This change only establishes a dependency manifest and CI preflight for a disposable Laravel harness.

## Decisions needed before implementing migrations/auth
1. Token policy: suggest Sanctum access tokens expire after 60 minutes for PoC; logout revokes current token; no refresh-token scheme. Confirm whether 60 minutes is acceptable.
2. Persistent member data: propose standard Laravel users id, name, email, password, plus optional display_name; use synthetic accounts only. Confirm exact profile field migration.
3. Notices: propose id, title, body, status (draft/published), published_at, timestamps; query published notices for authenticated members only. Confirm exact migration.
4. Roles: propose `member` and `admin` through Spatie, with explicit Filament panel authorization policy; only admin may create/publish notices. Confirm.
5. Disposable MySQL: CI service container, no production credentials or shared environment. Confirm.

These are proposals, not approved values. No migrations or security-sensitive code should be merged before review. Use Laravel 12 / Filament 4 / Spatie 6 only as preliminary compatibility targets, subject to matching Tiandi's actual Laravel 13 / Filament 5 / Spatie 7 stack before final implementation. Avoid locking in divergent versions.

Acceptance after approval: real app bootstrap, database migration, Sanctum 401/403/logout tests, member profile isolation, notice publication filtering, Filament admin vs member tests, then Flutter Dio integration and Android testing.
