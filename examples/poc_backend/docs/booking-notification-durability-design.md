# Booking notification durability: verified constraints and pending decisions

Status: **DESIGN / NOT ACCEPTED**. Draft PR #42; do not merge or deploy on the basis of this document.

## Confirmed current behavior

- `BookingEngine::notice()` accumulates `pendingNotices` in PHP memory. `flush()` invokes `BookingSideEffects::notify()` after the engine transaction. A process crash between commit and flush can lose notices.
- `BookingSideEffects::notify()` registers `DB::afterCommit` and catches/logs exceptions. This prevents booking rollback but does **not** provide durable delivery or worker retries.
- `DispatchBookingNotification` is a new standalone queued worker, not yet used by BookingEngine. It preserves the existing member/guest notification channels and has bounded retry settings.
- `MemberNotifier` is `final`; tests must use its real implementation, not a Mockery class mock.
- `notifyChange()` iterates active booking groups and calls `notice()` and `queueSync()` **outside its own BookingEngine transaction**. A transaction-only replacement must explicitly handle this path.
- The database queue configuration currently has `after_commit=false`. Transactional queue insertion requires the queue's database connection to match the booking DB connection, and a running worker.

## Important risks / not yet verified

1. **Idempotency**: retrying the notification job can create duplicate database notifications if a worker crashes after writing the notification but before acknowledging the queue job. The current job has no stable delivery ID / unique constraint. Do not enable automatic retry delivery without a tested deduplication strategy.
2. **Lost delivery**: replacing `pendingNotices` with `dispatch()` must occur within the booking transaction, and must not silently fall back to post-commit memory when durable delivery is required.
3. **External call paths**: `notifyChange()` is outside the booking transaction; its queue insertion and Google sync must be explicitly designed/tested.
4. **Guest claim**: guest notifications are stored on `EventBookingGroup` and later claimed. Preserve this behavior and test it.
5. **Worker restart / failed jobs**: current unit/integration tests are not an independent worker crash/recovery acceptance test.
6. **Runtime configuration**: confirm `QUEUE_CONNECTION`, `DB_QUEUE_CONNECTION`, database queue worker service, retry/timeout settings in the deployment environment.
7. **Notification semantics**: current notifications use only the database channel; no new SMS/email must be introduced.

## Existing tests

- `BookingNotificationHandoffTest.php`: missing group, real notifier exception propagation, member delivery, guest delivery, retry policy, database queue rollback.
- `BookingDatabaseQueueAtomicityTest.php`: Google handoff / booking rollback atomicity.
- `BookingGoogleSyncHandoffTest.php`: Google handoff worker behavior.

These tests do **not** prove exactly-once notification delivery, production worker recovery, or complete BookingEngine integration.

## Gate before enabling the notification handoff

- Choose a minimal stable notification identity + DB uniqueness enforcement or other tested deduplication mechanism.
- Integrate dispatch in booking transaction, preserve nontransactional `notifyChange` path.
- Add tests for rollback, repeated worker delivery, member/guest claim, retry after partial completion, and end-to-end queue processing.
- Run full Backend CI and independently validate worker restart/retry and deployment queue configuration.
- Update Shared #25/#35 and Tiandi #34/#38; only after acceptance publish **「已驗證機制基線｜避免 AI 重複調查與開發」** with precise commit/test/environment evidence.

**Policy**: CI green alone never closes this work. No automatic PR merge, deployment, or issue closure.
