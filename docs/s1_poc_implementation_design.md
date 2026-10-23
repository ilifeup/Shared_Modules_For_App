# S1 Flutter + Laravel + Filament Vertical Slice — Implementation Design

> Status: PROPOSAL / NOT TESTED. Scope: Auth + M01 + M02(minimal) + M06 + M07(minimal). This document does not approve migrations, production deployment, or changes to Tiandi.

## 1. Goal and acceptance evidence
Demonstrate that an **independently deployable application** can install a reusable client/API/admin contract and run: Filament admin creates and publishes a notice → Flutter logs in to the same disposable backend → Flutter reads/updates own profile and reads only published notices. Prove service-layer authorization and error handling. Record real command outputs, CI links, Android artifact and real-device result separately.

## 2. Boundaries
- Shared repo contains portable packages/contracts and disposable example/PoC host. Example host is **not** the fourth production backend.
- Every consumer App has its own user table, tokens, permissions, notice records and admin deployment.
- Do not pre-create M03/M08/M11 business schema, IoT features, payment workflows or central SSO.
- Existing Tiandi code stays untouched during S1; integration is S2.

## 3. Suggested directory layout (create only alongside working code)
```
contracts/openapi.yaml                # reviewed public API contract
packages/flutter/shared_core/         # Dart models, Dio client, auth interface
packages/laravel/shared_core/         # portable services, requests, policies
examples/poc_backend/                 # disposable Laravel+Filament host
examples/poc_flutter/                 # disposable Flutter app
```
These are proposals; confirm Composer package namespace, pub package name and cross-repo installation mechanism before publishing stable packages. Avoid premature monorepo tooling.

## 4. Proposed HTTP contract (review required)
| Verb | Path | Auth | Purpose |
|---|---|---|---|
| POST | /api/v1/auth/login | public | Exchange credentials for Sanctum opaque token |
| POST | /api/v1/auth/logout | bearer | Revoke current token |
| GET | /api/v1/me/profile | bearer | Read own profile |
| PATCH | /api/v1/me/profile | bearer | Update approved own fields only |
| GET | /api/v1/notices | bearer (PoC) | List published notices visible to member |
| GET | /api/v1/notices/{id} | bearer (PoC) | Read published notice details |

Success: `{data,meta?}`. Failure: `{error:{code,message,fields?,request_id}}`. Include `X-Request-Id` in responses. Return 401 for missing/revoked tokens, 403 for authenticated forbidden actions, 422 for invalid input, 404 for inaccessible/unpublished notices where revealing existence is undesirable. **Exact fields, token lifetime, profile editability, notice visibility, registration policy and public-vs-auth notice access require approval.** Do not infer from this proposal.

## 5. Candidate minimum schema (NOT APPROVED)
- users: app-local identity, password hash and only approved profile fields; no users.role shortcut.
- personal_access_tokens: Sanctum tokens in app-local database.
- Spatie permission tables: roles, permissions and mappings; policies enforce actions. VIP/verification separate from roles.
- notices: title, body, status, published_at, author_id and timestamps; publication filtering enforced server-side.
- audit_events (or existing app log mechanism): event type, actor id, request id, timestamp; exclude tokens/passwords/sensitive profile payload.
Do not run migrations or implement schema until fields and ownership are reviewed.

## 6. Laravel and Filament work
1. Implement Form Requests and services for auth/profile/notices, not duplicate rules in controller and Filament.
2. Implement Sanctum bearer auth, revoke-current-token logout and Policies.
3. Filament: authenticated admin notice CRUD, draft/publish; members cannot use admin resource.
4. Server-side tests for happy path, invalid credentials, revoked token, cross-account profile edit, unpublished notice, unauthorized publish, invalid payload, error envelope and request id.
5. No seed credentials in Git, no production data, use disposable test database.

## 7. Flutter work
1. Login form, loading/errors, token in secure storage, logout, profile display/edit and notices list/detail.
2. Dio attaches bearer token and handles 401, 403, 422 and request id; Riverpod owns UI state; no token logging.
3. Widget/unit tests for login errors, profile field validation, notice visibility and logout clearing local state.
4. Android build evidence is distinct from installation, real API access and device testing. iOS remains NOT TESTED until a supported macOS environment is approved.

## 8. Evidence matrix
| Gate | Evidence | Initial status |
|---|---|---|
| S1-A | Composer validate, Pint, Pest + database tests | NOT TESTED |
| S1-B | Flutter format/analyze/test | NOT TESTED |
| S1-C | Flutter Android debug APK | NOT TESTED |
| S1-D | Real Flutter ↔ Laravel API ↔ MySQL ↔ Filament workflow | NOT TESTED |
| S1-E | 401/403/422/404 security and visibility cases | NOT TESTED |
| S1-F | No Tiandi identifiers or central DB dependency in shared code | NOT TESTED |
| S1-G | Real Pixel/Android device validation | NOT TESTED |
| S1-H | iOS build/device | NOT TESTED |

## 9. Open decisions before implementation
1. Is member registration included or will test members be created by admin? (Recommend admin-created only for PoC.)
2. Which profile fields may members edit? (Recommend display name only in PoC; email changes require verification policy.)
3. Are notices member-only or public? (Recommend member-only for this PoC; future configurable access.)
4. Which admin roles can publish? (Recommend admin only for PoC; precise role permissions still require approval.)
5. Confirm test host runtime/version and no-cost CI strategy, package distribution strategy and actual shared contract naming.

## 10. Stop conditions
If requirements conflict, package installation cannot be demonstrated, CI fails after two repair attempts, or secrets/production data would be required: stop, report BLOCKED and request an explicit decision. No silent scope expansion.
