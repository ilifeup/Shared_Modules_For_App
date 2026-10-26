# Booking durable side-effect implementation gate (S9)

Status: **CONFIRMED DESIGN GAP / IMPLEMENTATION NOT STARTED / NOT ACCEPTED**  
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
