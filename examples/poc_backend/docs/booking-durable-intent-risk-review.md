# Booking durable handoff: remaining production risks (provisional)

Status: PARTIALLY VERIFIED. Not a production acceptance document.

## Verified in GitHub CI
- Backend run 40287659923: test job succeeded for commit 9a364ef.
- Database queue atomic rollback tests cover Google handoff and notification handoff components under the test database transaction.
- DispatchBookingGoogleSync delegates to existing GoogleCalendarSync; DispatchBookingNotification delegates to MemberNotifier.

## Unverified / blocking
1. **Notification durability not wired:** BookingEngine::notice stores `pendingNotices` in memory; `flush()` calls BookingSideEffects::notify after booking transaction. BookingSideEffects catches and logs delivery errors, so notifications can be lost on process crash or failed delivery. DispatchBookingNotification exists but BookingEngine does not enqueue it.
2. **Notification retry idempotency:** Worker crash after successful notification DB write but before queue acknowledgement may repeat delivery. Need durable unique delivery key / deduplication before wiring retries.
3. **Google handoff configuration:** BookingEngine::queueSync uses atomic DB queue only if transactionLevel > 0, default queue=database, and DB_QUEUE_CONNECTION matches the booking database. All other configurations fall back to non-durable post-commit memory path. The database queue must have after_commit=false for same-transaction insertion. Verify production config and worker.
4. **Worker restart/recovery:** Tests call handlers or assert rows; they do not prove an independently running queue worker survives process death, retries, and drains the queue.
5. **Outer transaction:** RefreshDatabase wraps tests in a transaction; savepoint rollback is verified, but independent transaction/process crash behavior remains unverified.
6. **Google idempotency:** Check repeated handoff and existing Google mapping logic before claiming exactly-once behavior.

## Gate
Do not merge PR #42, deploy, close #25/#35 or Tiandi #34/#38, or label Booking VERIFIED based on CI alone. After all applicable checks are independently confirmed, publish the permanent document titled exactly **已驗證機制基線｜避免 AI 重複調查與開發**, including Controller→Service→Model→DB→API→Flutter, paths, tests, SHA, runtime requirements, limitations, decisions, and revalidation triggers.
