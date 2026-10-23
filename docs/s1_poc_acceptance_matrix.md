# S1 Acceptance Test Matrix — Auth + Profile + Notices

Status: PLANNED / NOT TESTED. Companion to `s1_poc_implementation_design.md`. This is a test contract proposal, **not** evidence of execution.

## Evidence policy
For each case capture test ID, environment, test command, test data category (synthetic only), result PASS/FAIL/BLOCKED/NOT TESTED, CI run URL, commit SHA and tester/date. Do not store bearer tokens, passwords, real PII, or production URLs in evidence. Do not equate API mocks or successful APK build with real end-to-end testing.

## Backend functional and security tests
| ID | Scenario | Expected result |
|---|---|---|
| AUTH-01 | Valid seeded account logs in | 200, opaque bearer token issued, token not in logs |
| AUTH-02 | Wrong password | 401, generic error, no user enumeration |
| AUTH-03 | Missing bearer on /me/profile | 401 with standard error envelope |
| AUTH-04 | Logout with current bearer | current token revoked, subsequent request 401 |
| AUTH-05 | Expired/revoked bearer | 401 and no profile leakage |
| PROF-01 | User reads own profile | 200, approved fields only |
| PROF-02 | User edits permitted field | 200, persisted, read-back consistent |
| PROF-03 | User submits invalid field | 422 with field errors, no persistence |
| PROF-04 | User attempts to edit role/VIP/verified flags | rejected/ignored safely; no privilege change |
| PROF-05 | User tries to edit another user's profile | 403/404, no cross-user write |
| NOTICE-01 | Filament authorized admin creates draft | draft saved, member API does not expose it |
| NOTICE-02 | Authorized admin publishes notice | member list/detail shows published notice |
| NOTICE-03 | Admin unpublishes notice | member API hides it immediately |
| NOTICE-04 | Unauthorized account tries Filament notice CRUD | 403/redirect denied, no write |
| NOTICE-05 | Direct request to draft notice ID | 404 without draft body disclosure |
| NOTICE-06 | Bad pagination/input | 422 or documented validation error |
| AUDIT-01 | Login/logout/publish meaningful events | event type, actor and request id, no secrets |
| AUDIT-02 | Client supplied malicious request id | validated/normalized; no log injection |
| API-01 | Successful API envelope | {data,meta?} and X-Request-Id |
| API-02 | Failure envelope | {error:{code,message,fields?,request_id}} |
| SEC-01 | Filament and API call same application service | same publish/visibility policy, no divergent logic |
| SEC-02 | Independent App DB boundary | no shared production database/SSO dependencies |

**Notes:** Status codes for forbidden profile fields, notice pagination and exact fields must be fixed in the reviewed OpenAPI contract before tests are made authoritative.

## Flutter unit, widget and integration tests
| ID | Scenario | Expected result |
|---|---|---|
| FL-01 | Valid login | token saved using secure storage, profile loaded |
| FL-02 | Invalid login | actionable error, token absent |
| FL-03 | Restart app with saved token | session restored or intentionally expired per approved policy |
| FL-04 | API 401 | local session invalidated and login shown |
| FL-05 | Profile edit validation | invalid input blocked; successful edit reflected |
| FL-06 | Notice list/detail | published notices only; empty/loading/error states work |
| FL-07 | Logout | server revocation attempted and local credential cleared |
| FL-08 | Device logs | no bearer token, password or sensitive data |
| BUILD-01 | Flutter format/analyze/test | exit code 0 and captured logs |
| BUILD-02 | Android debug APK | built from recorded SHA; artifact downloadable |
| E2E-01 | Flutter → real Laravel API → MySQL | login/profile/notice flow PASS with synthetic data |
| E2E-02 | Filament publish → Flutter refresh | newly published notice visible on Android |
| DEVICE-01 | Physical Android device | install/login/profile/notices/logout evidenced |
| IOS-01 | iOS build/device | NOT TESTED until macOS build/test authorized |

## Release gates
- G0: requirements, field list, admin roles and visibility approved.
- G1: backend schema/API/policies implemented, tests green.
- G2: Flutter client + widgets implemented, tests green.
- G3: real disposable end-to-end backend and Filament workflow passes.
- G4: Android APK build and physical-device tests pass.
- G5: portability checked without Tiandi brand/schema coupling.
- G6: review, pinned commit SHA and CI evidence recorded; only then release candidate.

Do not declare a gate PASS when any required case is NOT TESTED. No production deployment or integration into Tiandi during S1.
