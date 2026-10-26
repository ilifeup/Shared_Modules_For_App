# Booking notification durability: verified constraints and pending decisions

Status: **DESIGN / NOT ACCEPTED**. Draft PR #42; do not merge or deploy on the basis of this document.

## Implementation evidence (2026-10-26; PARTIALLY VERIFIED)

At commit `2aa50e46fe0c35a8d234b4188711be84f9debc34`, Shared PR #42 has green Backend, Mobile, and documentation workflows (Backend run 40294091714; Mobile 40294091684; docs 40294091725). These verify the CI environment only, not production acceptance. `BookingNotificationHandoffTest.php` exercises a real `queue:work --once` and notification persistence. The same-job retry and distinct-job tests exercise stable delivery IDs. **Known limitations:** concurrent independent worker processes, crash/restart, recipient changes, guest claim, and production queue service/configuration are not yet verified. The non-database queue fallback remains non-durable. Keep this document at DESIGN / NOT ACCEPTED.

## Historical baseline before PR #42 (superseded where noted)

- `BookingEngine::notice()` accumulates `pendingNotices` in PHP memory. `flush()` invokes `BookingSideEffects::notify()` after the engine transaction. A process crash between commit and flush can lose notices.
- `BookingSideEffects::notify()` registers `DB::afterCommit` and catches/logs exceptions. This prevents booking rollback but does **not** provide durable delivery or worker retries.
- **Updated:** `DispatchBookingNotification` is now used by `BookingEngine::notice()` inside a booking DB transaction when the database queue shares the same connection. It preserves member/guest database notifications and bounded retry settings. Other queue configurations still fall back to `pendingNotices`.
- `MemberNotifier` is `final`; tests must use its real implementation, not a Mockery class mock.
- **Updated:** `notifyChange()` now wraps its notification/sync scheduling in `BookingEngine::transaction()`; direct calls no longer require a caller-provided transaction.
- The database queue configuration currently has `after_commit=false`. Transactional queue insertion requires the queue's database connection to match the booking DB connection, and a running worker.

## Recipient semantics (observed; acceptance pending)

- **CURRENT IMPLEMENTATION:** `DispatchBookingNotification` persists the booking group ID and resolves the booker from `EventBookingGroup::booker()` at worker execution time, not at enqueue time. Test commit `ea8aa0c0f3769f649af7561669d684e486306968` verifies that changing ownership from A to B before execution delivers to B, not A (Backend CI run 40298888018: success).
- This behavior is **NOT an approved business decision** for every notification type. Cancellation, transfer, privacy-sensitive or historical audit notices may require delivery to the original recipient. Require an explicit decision and new tests before changing this behavior.
- The worker locks the booking group but does not snapshot a recipient ID at enqueue time. Deletion or role changes before execution may cause skipped delivery. Production multi-worker/recovery validation remains outstanding.

## Important risks / not yet verified

1. **Idempotency**: each notification job now has a stable UUID used as the database notification ID, with an existence check and a group row lock in the worker transaction. Sequential replay tests pass; concurrent multi-worker/crash-recovery behavior is still unverified. Do not claim exactly-once delivery.
2. **Lost delivery**: same-database queue insertion is transactional in the tested paths. The other queue-driver fallback still uses post-commit memory and is a KNOWN ISSUE requiring an explicit deployment policy.
3. **External call paths**: `notifyChange()` now owns a DB transaction and has an integration test. Deployment and cross-process recovery remain unverified.
4. **Guest claim**: guest notifications are stored on `EventBookingGroup` and later claimed. Preserve this behavior and test it.
5. **Worker restart / failed jobs**: current unit/integration tests are not an independent worker crash/recovery acceptance test.
6. **Runtime configuration**: confirm `QUEUE_CONNECTION`, `DB_QUEUE_CONNECTION`, database queue worker service, retry/timeout settings in the deployment environment.
7. **Notification semantics**: current notifications use only the database channel; no new SMS/email must be introduced.

## Existing tests

- `BookingNotificationHandoffTest.php`: missing group, real notifier exception propagation, member/guest delivery, sequential replay deduplication, distinct notification identities, booking change transactional queue rollback, and actual `queue:work --once` processing.
- `BookingDatabaseQueueAtomicityTest.php`: Google handoff / booking rollback atomicity.
- `BookingGoogleSyncHandoffTest.php`: Google handoff worker behavior.

These tests do **not** prove exactly-once notification delivery under concurrent workers, production worker recovery, or all BookingEngine entry-point behavior.

## Gate before enabling the notification handoff

- Choose a minimal stable notification identity + DB uniqueness enforcement or other tested deduplication mechanism.
- Integrate dispatch in booking transaction, preserve nontransactional `notifyChange` path.
- Add tests for rollback, repeated worker delivery, member/guest claim, retry after partial completion, and end-to-end queue processing.
- Run full Backend CI and independently validate worker restart/retry and deployment queue configuration.
- Update Shared #25/#35 and Tiandi #34/#38; only after acceptance publish **「已驗證機制基線｜避免 AI 重複調查與開發」** with precise commit/test/environment evidence.

**Policy**: CI green alone never closes this work. No automatic PR merge, deployment, or issue closure.
