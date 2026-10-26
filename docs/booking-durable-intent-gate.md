# Booking durable side-effect implementation gate (S9)

Status: **CONFIRMED DESIGN GAP / MYSQL FEASIBILITY TESTS PASS / IMPLEMENTATION NOT STARTED / NOT ACCEPTED**  
Tracked by [Shared #25](https://github.com/ilifeup/Shared_Modules_For_App/issues/25) and [Tiandi #34](https://github.com/ilifeup/tiandi-app/issues/34).

## Existing verified mechanisms — do not rebuild

- `BookingEngine`: `calendar_events` row locking, common S6/S9 seat ledger and FIFO waitlist; `pendingNotices` / `pendingSync` arrays, followed by `flush()` **after** `DB::transaction()`.
- `BookingSideEffects`: `DB::afterCommit` callbacks; failures caught and only logged.
- `config/queue.php`: database driver default, `after_commit=false`; actual deployment and same DB connection not yet established.
- `GoogleCalendarSync`: durable mapping rows, deterministic Google event ID, latest-state synchronization.
- `SyncGoogleCalendarItem`: existing queued job, 5 tries, 60/300/900/3600-second backoff, terminal and revoked-auth handling.
- `MemberNotifier`: in-app notification; duplicate-delivery behavior remains unverified.

## Failure window

```text
BookingEngine transaction commits booking
   |
   | PHP may exit before flush() => no durable side-effect intent
   v
flush() -> BookingSideEffects -> afterCommit -> notification / Google mapping and job
   |
   | callback/dispatch error => log-only, no guaranteed recovery
```

Google mapping persistence is not sufficient if execution never reaches mapping creation. This is a source-confirmed design gap, **not** an observed production incident.

## Minimal approach decision gate

First verify that native Laravel **database queue** job insertion can occur on the **same physical DB connection and transaction** as booking writes, with `after_commit=false`. Verify `DB_QUEUE_CONNECTION`, queue configuration overrides, migrations, worker/scheduler supervision and restart behavior. Do not assume default config equals runtime configuration.

If a same-transaction durable queue job can be proven safe, prototype it without a second workflow engine. Preserve the existing Google mapping and job, and provide a unique notification delivery key. If it cannot meet replay, deduplication or recoverability requirements, implement a minimal transactional outbox with bounded retries and a recovery scanner.

**Never call an external service within the booking transaction.** Persist intent atomically, execute after commit.

## Required tests before implementation acceptance

| Case | Expected outcome |
| --- | --- |
| Rollback booking transaction | Neither booking nor new side-effect intent survives |
| Commit then kill PHP before old `flush()` | Booking and durable intent survive |
| Queue worker unavailable then restarted | Pending intent eventually processed |
| Concurrent duplicate booking request | One business mutation and appropriate single intent |
| Duplicate job execution | No duplicate in-app notification or Google event |
| Google succeeds then worker fails before acknowledgement | Safe retry with deterministic ID |
| Cancel while earlier upsert pending | Google reflects latest authoritative DB state |
| Guest booking and later member claim | Existing notification ownership behavior retained |
| OAuth revoked / 429 / 5xx | Appropriate reconnect or retry/terminal state |
| Flutter request timeout | Query authoritative booking status; do not blindly rebook |
| MySQL concurrency and crash | Repeatable evidence from MySQL, not SQLite alone |

## Workflow and evidence requirements

1. Introduce focused failing tests first and record the exact failure, environment and SHA in #25.
2. Implement smallest proven correction and run targeted Pest/MySQL/concurrency tests.
3. Check shared L2 boundary; keep Tiandi L3 integration separate.
4. Update API/OpenAPI and Flutter states only where required.
5. Attach CI links, commit SHA, root cause, rejected alternatives and deployment prerequisites to #25, #34 and master trackers #35/#38.
6. Keep PR Draft until code review and real integration acceptance; **CI green is not acceptance**. Never auto-merge, deploy or close.

## CI evidence and implementation boundary (2026-10-26)

- Backend run [40284937752](https://github.com/ilifeup/Shared_Modules_For_App/actions/runs/40284937752): **289 pass, 3 intentional RED, 2146 assertions**. The test for mapping and database queue job rollback together passes. The connected-member positive control passes; injected Google queue failure still loses the expected durable job.
- The above Pest tests use `RefreshDatabase` with an outer test transaction. This proves transaction/savepoint feasibility in CI MySQL, **not** process-kill survival or production worker availability.
- `GoogleCalendarSync::queueBooking` intentionally skips users without an ACTIVE connection. Never require a Google job for an unconnected user. A connected user has both a mapping and queued job in the positive control.
- `BookingEngine::notifyChange` queues notifications/sync outside its own transaction and calls `flush()`. Other booking methods call `flush()` after their transaction. Any change must handle both paths; blindly moving `flush()` inside the transaction would call `BookingSideEffects::afterCommit`, **not** make the effect durable.
- **Next minimal implementation:** enqueue a small durable booking-effect command on the same database connection **inside** each booking transaction, with `after_commit=false`; worker performs the existing `BookingSideEffects` operations after commit. Do not enqueue the existing Google job alone for every booking: connection state, guest notices, waitlist promotions, and retry semantics differ. Preserve existing `SyncGoogleCalendarItem` and Google mapping logic.
- **Hard stop:** do not switch production queue driver, remove post-commit notifications, or replace `flush()` until a job/worker contract and tests cover `notifyChange`, cancellation, guest notifications, retry idempotency, and the non-database queue configuration. The first two RED tests currently assume implementation shape and must be revised into behavioral assertions before acceptance.

## Mandatory final deliverable: 已驗證機制基線｜避免 AI 重複調查與開發

**Status: REQUIRED AFTER VALIDATION; NOT YET APPROVED.** This section is a tracking obligation, **not** a claim of completed validation.

After the Booking implementation has passed the full behavioral, transaction, worker-recovery, and integration acceptance gates, create or update a permanent GitHub baseline document with the exact heading **「已驗證機制基線｜避免 AI 重複調查與開發」**. Link it from Shared master #35, Booking #25, Tiandi master #38, and Booking #34. The baseline must be discoverable before future AI agents modify Booking.

The permanent baseline must contain: verified mechanisms and reuse boundaries; Controller → Service → Model → DB → API → Flutter trace; actual file paths and function names; tested environment, commit SHA, CI URLs and test names; runtime prerequisites; accepted behavior and limitations; known defects and rejected alternatives with reasons; related issues/PRs; and a revalidation trigger checklist for changed code, schema, queue drivers, workers or business rules.

Classify every item as **VERIFIED**, **PARTIALLY VERIFIED**, **UNVERIFIED**, or **KNOWN ISSUE**. Do not infer production correctness from CI success, do not treat a Draft PR as merged, and do not automatically close issues after acceptance. Agents must read the baseline and issue history before repeating investigation or building a replacement. Evidence must be revised when behavior changes; a baseline is not a permanent immunity from regression.

Acceptance checklist for this obligation:
- [ ] Full Booking behavioral and recovery verification complete
- [ ] Permanent GitHub baseline document written with the required exact heading
- [ ] Baseline evidence and remaining limitations reviewed
- [ ] Links added to Shared #25/#35 and Tiandi #34/#38
- [ ] PR review completed; **no automatic merge or issue closure**
