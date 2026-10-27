# Member-only booking + durable side-effects integration gate

Status: **IN PROGRESS / NOT ACCEPTED / DO NOT MERGE** (2026-10-27).

## Branch ancestry and verification

- PR #47 is based on PR #43's earlier member-only booking commit `4bd9beeedf67314e4bf4db9d84a11a7e10127be2`; subsequent PR #43 changes must be reviewed and ported or incorporated before acceptance.
- PR #43 added guest API denial regression coverage in `fa04cc0d5f09e3b2ec74a355b8b1292a7ac36e06`; corresponding cases were separately ported into #47.
- PR #42 contains the durable handoff work and removed obsolete guest notification delivery/tests. Review any further #42 changes against this integration branch before accepting either PR.
- PR #46's canonical Guest / register member / Verified Member access policy must remain compatible with booking authorization. Authentication is not membership qualification.

## Mandatory behavior

1. Guests may read **public announcements only**; no individual/group booking, waitlist, booking-change notification, personal inbox or notification API access. No administrator override.
2. Booking actors come from authenticated server requests, never client-provided IDs. Every attendee has an existing active member ID and required family-delegation authority.
3. A notification job targets only the current member booker; a missing booking group or member must never trigger a guest/group fallback.
4. Replayed notification jobs must not duplicate delivery. Cancellation, event changes and waitlist promotions must be tested with member-only data.
5. Booking state and durable database-queue intent must commit or roll back together; Google sync uses current authoritative state and must not perform external network calls within the booking transaction.
6. A green CI result is not integration acceptance: verify MySQL schema migration and destructive guest-data purge, backup/restore plan, worker configuration, retry behavior and cross-PR test execution.

## Remaining evidence required

- [ ] PR #47 latest Backend, Mobile and documentation CI all pass at the same head SHA.
- [ ] Diff PR #43's latest head against the #47 base and integrate all applicable fixes; run guest-inbox and guest-booking tests on the final combined tree.
- [ ] Review PR #42's latest head against #47's durable job implementation and tests; resolve conflicts explicitly.
- [ ] Run MySQL tests for notification replay, missing booking/member, cancellation, waitlist promotion and Google worker retry.
- [ ] Confirm deployment queue connection, worker supervision and rollback/restore readiness before any migration.
- [ ] Record evidence and unresolved issues in Shared #35 and related booking trackers; do not close issues merely because CI is green.

No automatic merge, deployment or issue closure.
