# Baseline 53 clean integration gate (2026-10-28)

## Non-negotiable
- This branch starts at tested PR #53 HEAD 4fe7cc987acbb8bec3283762c60dfbdf655f0068.
- Do not merge automatically. Do not force-push or rewrite #41/#46/#47/#49.
- Database authority: users.id, users.is_active = true, users.verified_status = 2. OTP is a process, not membership status.
- M08: no guest booking, no anonymous attendee, exactly one verified user ID per booking. Authorized family delegation only.
- M08GuestBookingTest.php must remain deleted. Green CI alone is not acceptance.

## Integration sequence (batch per layer, not one commit per bug)
1. Baseline #53 booking controller/service/routes/tests remain authoritative.
2. Bring #49 membership schema and verification changes, resolve migrations and test against #53.
3. Bring #47 durable intent and notification/sync jobs, preserve booking contract, test replay, FIFO and cancellation.
4. Bring #46 audiovisual catalog/access rules, without overwriting #53 booking.
5. Bring #41 attachment entitlement, without overwriting booking.
6. Validate Controller -> Service -> Model -> Database, all migrations and feature tests.
7. Run local syntax/unit/feature tests first, then GitHub CI on a conflict-free PR.

## Why the old PRs have no current PR CI
GitHub documents that pull_request workflows do not run while a PR has merge conflicts. Check conflict resolution, workflow filters and event triggers; do not assume an empty run list means passing tests.

## Fast-development guard
Avoid piecemeal cherry-picks of BookingEngine.php from old branches. Carry over domain-specific additions only, with compatibility review. Batch related changes, record root causes and regression tests in Issue #35 and FAST-DEVELOP.md. Do not close issues solely because CI is green.
