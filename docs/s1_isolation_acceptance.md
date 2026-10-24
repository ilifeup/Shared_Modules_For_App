# S1 Isolation Acceptance — Test Plan

Status: contract design recorded; cross-App runtime integration **NOT TESTED**.

| ID | Test | Expected | Status |
|---|---|---|---|
| ISO-01 | Separate MySQL test databases for Tiandi, Wuxiang and EachCore | No shared users or tokens tables | NOT TESTED |
| ISO-02 | Same synthetic email exists in multiple Apps | Distinct account IDs and passwords permitted | NOT TESTED |
| ISO-03 | Use Tiandi token against Wuxiang API | HTTP 401 | NOT TESTED |
| ISO-04 | Use Tiandi token against EachCore API | HTTP 401 | NOT TESTED |
| ISO-05 | Logout Tiandi current token | Other App sessions unaffected | NOT TESTED |
| ISO-06 | Run one App migration | Other two schemas unchanged | NOT TESTED |
| ISO-07 | Inspect shared code for central DB/auth assumptions | None | REVIEW PENDING |

The code-only boundary smoke verifies the decision metadata but does not prove ISO-01 through ISO-07 in a real Laravel environment. Real E2E must wait for independently approved user/notices schema and authorization policy.

Token expiry: 1440 minutes, as approved in ADR-003.
