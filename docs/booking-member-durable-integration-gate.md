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

Current main comparison (2026-10-27): PR #47 is 26 commits ahead and 23 behind main; GitHub reports mergeable, but the branch must still be checked against the latest main. This gate remains NOT ACCEPTED.

- [x] PR #47 Backend, Mobile and documentation CI passed at head `53cd991a7eb4078eb393a7c9d020a23f0c1faeb5` (2026-10-27). This is branch-level evidence, not latest-main integration acceptance.
- [x] PR #43 latest head `fa04cc0d5f09e3b2ec74a355b8b1292a7ac36e06` is merged into main; `M08GuestBookingTest.php` has identical blob SHA `31b1cedf640a7f9df0565248ece93edda1cbe3bf` in #43, main and #47. `BookingEngine.php` in main equals #43 (`8a70af7c...`), while #47 intentionally differs for durable handoff and family delegation. Final combined-tree guest inbox/booking and MySQL tests remain required.
- [ ] Review PR #42's latest head against #47's durable job implementation and tests; resolve conflicts explicitly. The three #42-only design/risk/gate documents were copied into #47 (commit `ff000c3118bd091f1381bf0bccaf4fbd2531c126`); this does not prove all differing code paths are reconciled.
- [ ] Run MySQL tests for notification replay, missing booking/member, cancellation, waitlist promotion and Google worker retry.
- [ ] Confirm deployment queue connection, worker supervision and rollback/restore readiness before any migration.
- [ ] Record evidence and unresolved issues in Shared #35 and related booking trackers; do not close issues merely because CI is green.

No automatic merge, deployment or issue closure.

## Historical PR #28 reconciliation (source checked 2026-10-27)

- PR #28 was closed without merge. Its attendee-snapshot/idempotency protection is present on `main`: persisted attendee name/member ID and seat count are compared, with a rename exception for the booking member.
- PR #47 intentionally uses stable ordered attendee **member IDs** and seat count rather than mutable names, because guests and attendees without member IDs are forbidden. It additionally rechecks current booker activity and family delegation before returning protected replay data.
- This is **not** evidence of cross-PR acceptance. Retest identical replay (200), changed attendee ID/order/count (409), booker profile rename (200), revoked family delegation (403), inactive attendee/booker (403), and concurrent retry creation (exactly one booking; 200 vs 201) against the combined MySQL tree. Preserve these behaviors when resolving #42/#43/#47 conflicts.
