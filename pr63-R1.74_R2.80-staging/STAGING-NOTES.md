# PR #63 local staging — not committed
Base HEAD: 9db90c9c5c33f5517ed78824ce58c646cc21987d

This is an isolated **partial code draft**, not deployable and not a completed refactor.

Changes drafted:
- CourseMakeup::booking() now targets EventBooking via event_booking_id.
- CourseMakeupAssignmentService assigns one canonical EventBooking instead of Group + Attendee.
- Capacity, duplicate membership and makeup quota checks use canonical bookings.
- Existing course term and event locking are retained.

BLOCKERS before integration:
1. Change course_makeups migration FK to event_booking_id after historical data reconciliation.
2. Refactor BookingEngine and all other callers; do not mix canonical and legacy seat counts.
3. Verify exact Laravel model casts and schema columns, enrollment semantics, and family permissions.
4. Add Pest MySQL concurrency, duplicate, idempotency and rollback tests.
5. Run Pint and full tests; no tests were executed for this draft.
6. Integrate all other modules, then create one commit on PR #63.

Continuation: staged the complete original course MVP and course group migrations with only canonical FK changes; preserved the actual course_groups business model. Staged EventWaitlistEntry canonical booking relation. `php -l` passed on all five staged PHP files. This is a **fresh-schema draft only**: for an existing database, a separate verified data migration is required. Backend integration tests and CI have not been run. The container cannot resolve github.com; GitHub connector reads remain available. Do not push this partial draft.

R1.75: Added read-only CanonicalDependencyPreflight.php for group/attendee, canonical booking, attendance, waitlist, course makeup, invoice item blockers. It is a draft, not yet integrated into an Artisan command or tested against a database. `ready` is only a preflight signal, never permission to drop legacy tables. Review migration assumptions and ensure historical reconciliation beyond simple FK matching.

R1.76: Hardened CanonicalDependencyPreflight: missing required tables are blocking rather than interpreted as empty. Preflight remains read-only, has no production DB connection, and is not a replacement for full financial/attendance reconciliation. No migration or writes executed.

R1.77: Preflight now blocks multi-attendee legacy groups (cannot map 1 group to 1 booking), empty groups, attendee-vs-booking event mismatch, and active-state inconsistencies. No writes and no DB run. Still requires migration, tests, and full integration before any commit.

R1.78: Added CourseMakeupCanonicalBookingTest.php (Pest-compatible PHPUnit TestCase) covering canonical booking creation/idempotency, zero-capacity rollback, full event rejection. PHP lint passes; actual test run blocked until complete repo and migrations integrated. Existing legacy CourseMakeupAssignmentTest remains and must be updated/replaced in the one-commit integration.

R1.79: Removed two unsafe historical CREATE TABLE migration edits from staging. Added new additive migration (2026_12_02) for nullable canonical FK bridges for waitlist, makeup, invoice items while retaining legacy columns. Added fail-closed preflight test. NOT cutover-ready: consumers need coordinated transition and DB tests. No DB migrations executed.

R1.80: Added CanonicalDependencySchemaTest verifying additive FK columns and preserved legacy links. Hardened preflight to cache missing-table checks and flag cancelled rows retaining active. Test files lint only; not executed. No DB changes or commits.

R1.81: Reviewed BookingEngine sections (booking/cancellation/waitlist): engine still writes legacy group+attendee and invoices by group. Hardened additive migration down() to reject rollback of populated canonical links; removed misleading nullable schema test (previous test did not verify nullability). No engine switch yet, no DB tests.

R1.82: Added read-only DB-backed preflight smoke test and fixed migration import warning; no tests executed.

R1.83: Preflight now checks required canonical and legacy columns before querying; a partially applied schema fails closed rather than raising SQL unknown-column errors. Added missing-column regression test. All files linted, no database integration test run.

R1.84: Added isolated CanonicalBookingWriter with event row lock, member verification, replay identity check, active member uniqueness, window, series ownership, canonical capacity/waitlist and sequence. Not wired to BookingEngine: must add family delegation, eligibility, notifications, invoice, side effects, retry and tests before integration. No database test executed.

R1.85: CanonicalBookingWriter now checks current family delegation/admin authority even on replay, and category eligibility against actual attendee. Still isolated and NOT wired to BookingEngine; integration tests and payment/notification flow pending.

R1.86: Added CanonicalBookingWriterTest for replay, duplicate member, FIFO waitlist and absence of legacy group writes. Not run against DB; fixture and business integration need validation. No GitHub changes.

R1.87: Added isolated CanonicalBookingCancellation transaction with event-first locking, series ownership guard, actor authorization, deadline, canonical status transition and fail-closed legacy group check. NOT WIRED; does not implement invoice/refund/attendance/notification/FIFO. Must not deploy or expose as endpoint before coordinated integration. PHP lint only.

R1.88: CanonicalBookingCancellation now fails closed if a confirmed cancellation would strand FIFO waitlist, or if canonical waitlist/makeup/invoice/attendance dependencies exist, or migration columns missing. This is a temporary safety gate, not complete cancellation workflow.

R1.89: CanonicalBookingCancellation now rechecks live family delegation for original booker cancelling another member, and fails closed for absent legacy/dependency tables before querying. Not wired, no DB tests.
R1.90: Added isolated CanonicalWaitlistPromotion (transaction guard, event lock, legacy queue blocker, strict FIFO, revalidation, no independent commit). NOT connected to BookingEngine; no invoice/notification/sync yet. Do not enable until integrated. No MySQL runtime tests.

R1.91: Added isolated FIFO promotion test and fail-closed guard for missing/invalid waitlist sequence. No integration or database tests run.

R1.92: Added fail-closed guards to CanonicalBookingWriter: refuse events with legacy groups; refuse paid events until canonical invoice contract is implemented. Added paid-event regression test. Not connected to API/BookingEngine; tests not executed.

R1.93: CanonicalWaitlistPromotion now fails closed on missing member/booker and S6 user_id vs member_user_id mismatch before granting a seat. No runtime DB test, no GitHub commit.

R1.94: CanonicalBookingWriter now catches concurrent unique-key SQLSTATE conflicts, distinguishes idempotent same-identity replay from other-key collision and member double-booking, preserves unrelated SQL errors. Still not wired, not database tested.

R1.95: Moved legacy-group and paid-billing cutover guards before idempotent replay; added replay-after-price-change regression test. Syntax checked only. No runtime tests, commit or deployment.

R1.96: Canonical FIFO promotion now refuses paid events and legacy group ledgers, and rechecks attendee event eligibility immediately before promotion. Added rollback regression test for paid-event transition. PHP syntax only; database tests not run. No GitHub commit.

## R1.97
- Free-event confirmed cancellation now calls canonical FIFO promotion in the SAME event-locked DB transaction; a blocked queue head rolls back the cancellation.
- Paid events with waiting members remain fail-closed until invoice integration.
- Not routed to production; notifications, audit, attendance and invoice cutover remain outstanding.
- PHP syntax only; no database integration tests executed.

R1.98: cancelled idempotency replay fails closed; regression test added. PHP syntax lint only; DB tests not executed. No commit or production DB access.

R1.99: Paid canonical cancellation now fails closed even with no waiting members, preventing untracked invoice/refund state. PHP syntax checked only; no DB test, commit, or deployment.

R2.00: Drafted CanonicalEventInvoiceService adapter for direct EventBooking invoice ownership, same-transaction enforcement, confirmed-booking identity, due-date and invoice replay consistency. Discriminator '03' is PROVISIONAL and must be checked against every existing payment/refund/export consumer before use. Not wired to BookingEngine or writer; paid booking remains blocked. No DB tests or GitHub commit.

## R2.01
Canonical invoice: reload booking under row lock inside transaction; validate existing invoice before checking new invoice deadline, so legitimate idempotent retries after event start do not fail. Billing discriminator 03 remains unapproved and writer paid cutover blocked. No database integration tests executed.

R2.02: canonical writer now creates confirmed paid invoice in same transaction; default-off paid cutover flag; paid waitlist remains blocked; no GitHub commit.

R2.03: Added paid canonical booking DB regression cases for same-transaction invoice creation, idempotent replay, and rollback on invoice deadline failure. Tests linted but NOT executed. Paid cutover remains default-off; booking_type 03 consumers and BookingEngine still unintegrated. No commit.

R2.04: Added read-only CanonicalInvoiceAudit for active confirmed paid EventBooking rows: checks missing/duplicate invoice and identity/amount mismatches. No automatic fixes. Requires isolated migrated test DB; not executed. Paid cutover stays disabled.

R2.05: Canonical invoice audit now detects legacy booking groups before interpreting missing canonical invoices; mixed ledgers are explicitly reported as LEGACY_LEDGER_PRESENT instead of false MISSING_INVOICE. PHP lint only, no DB test or commit.

## R2.06
- CanonicalInvoiceAudit: removed unbounded pluck of all legacy event IDs. Legacy coexistence is now checked by indexed event ID for each bounded audited booking.
- This is staging only; no GitHub commit or database execution.

## R2.07
- CanonicalInvoiceAudit now batches legacy event and canonical invoice reads per bounded booking page (<=200 rows), replacing two database queries per booking with two per page.
- Preserves strict checked limit, duplicate/missing/mismatch checks, and read-only behavior.
- Syntax checked only; no database tests, commit, or deployment.

### R2.08 attendance schema bridge
- Fixed a real cutover blocker: CanonicalBookingCancellation requires event_attendances.event_booking_id, while the additive bridge did not provision it.
- Bridge now conditionally creates the nullable FK when missing; existing M11 installations are not duplicated.
- Rollback deliberately retains the attendance link because earlier migrations may own it; manual schema provenance review is required before any removal.
- No production migrations or database tests executed.

### R2.09 — Attendance rollback ownership
The bridge may conditionally add `event_attendances.event_booking_id`, but an older M11 migration may already own it. `down()` therefore deliberately retains that attendance column; deleting it could destroy a pre-existing M11 schema. Rollback now refuses to proceed if attendance canonical mappings exist. This is a safety correction, **not** a completed migration/backfill or an executed database test.


## R2.10
- Attendance bridge migration now checks table existence before adding an optional canonical FK; missing attendance table is handled without a Schema::table failure.
- This is a staging-only compatibility correction; migration ordering and MySQL integration remain unverified.

### R2.11
- Corrected invoice_booking_items canonical FK lookup from UNIQUE to non-unique INDEX; multiple line items per booking are permitted.
- Updated schema regression assertion. No DB migrations run, no GitHub commit.

### R2.12 — cancellation transaction integration tests
- Added `CanonicalBookingCancellationTest.php`: confirmed cancellation promotes FIFO head and preserves one occupied seat; invalid head rolls back cancellation and retains queue order.
- Tests are staged only; no MySQL/PHPUnit execution or BookingEngine routing cutover has occurred.

## R2.14
- Canonical promotion now tolerates optional legacy waitlist/group tables not installed, while still rejecting actual legacy rows.
- Canonical cancellation no longer requires an absent legacy group table; when present, its mapping column remains mandatory.
- No production cutover; no MySQL/PHPUnit execution.

## R2.15 — shared course ownership preflight
- Added CanonicalSeriesOwnership, shared by canonical booking and cancellation. Optional course tables can be absent; present but malformed course tables fail closed.
- No BookingEngine routing cutover, MySQL/PHPUnit execution, GitHub commit, or production DB access.

## R2.17
- CanonicalBookingWriter: waitlist_capacity NULL is treated as unbounded (matching booking_capacity NULL semantics); explicit zero remains closed.
- Added regression test for multiple FIFO waitlist entries with NULL capacity.
- Still staging only; MySQL/PHPUnit not executed; no GitHub commit.

## R2.18
- Preserve promoted bookings' original FIFO sequence for historical audit and monotonic sequence allocation.
- Block duplicate active waitlist sequence numbers rather than arbitrarily ordering by ID.
- Added rollback regression test; tests not executed against MySQL.

## R2.19 — FIFO cancellation history
- Preserve waitlist_sequence on cancellation, including cancelled waiting entries; future allocations remain monotonic and historical order auditable.
- Added regression test for cancelled waiting entry followed by new waiting entry.
- PHP lint only; MySQL/PHPUnit not executed; no commit.

### R2.20
- CanonicalBookingCancellation re-reads and locks the cancellation actor, rejecting revoked/unverified identities instead of trusting a stale controller User model.
- Added stale-actor revocation regression test (not executed; requires MySQL/PHPUnit).
- No GitHub commit, merge or production DB changes.

R2.21: CanonicalBookingWriter administrative override now reloads the booker under shared lock and verifies current active/verified/admin authority; revoked admin cannot use stale HTTP User object to bypass family delegation. PHP syntax lint only; no database test or GitHub commit.

R2.22: Canonical invoice replay now detects duplicate invoice rows and verifies transaction type and currency as well as owner, event and amount. Staging only; no MySQL/PHPUnit execution or GitHub commit.

## R2.23
- CanonicalBookingCancellation: legacy group gate is scoped to calendar_event_id; no longer requires an unproven legacy_booking_id column when the legacy table exists but the occurrence has no groups. Still fail-closed when any legacy group belongs to the event.
- Pending: BookingEngine/API cutover, invoice refund/reconciliation, attendance, backfill, MySQL/PHPUnit. No commit/push.

## R2.24 — prevent course seat bypass
- CanonicalWaitlistPromotion rejects series/course-owned occurrences before promoting any waitlisted seat, matching CanonicalBookingWriter and Cancellation ownership checks.
- Added transaction-boundary regression test.
- BookingEngine cutover, payment/refund, attendance, backfill, MySQL/PHPUnit remain pending; no commit.

## R2.25 — admin-created FIFO waitlist
- CanonicalWaitlistPromotion now revalidates active/verified administrator authority when an administrator originally booked a member's waitlist seat. Previously the family-only check blocked all such promotions.
- Ordinary delegated bookers still require current family authorization; unverified or inactive bookers remain blocked.
- PHP syntax validated; PHPUnit/MySQL not executed. BookingEngine cutover, invoice lifecycle and notifications remain pending. No commit or production DB changes.

## R2.26
- CanonicalBookingWriter now allocates FIFO sequence from all historical rows, guards unsigned INT overflow/corrupt sequence, and tests monotonic allocation after cancellation.
- Not yet integrated into BookingEngine; PHPUnit/MySQL not executed. No commit/push.

### R2.27 — concurrent verification revocation
- CanonicalBookingWriter::verified now uses an InnoDB shared locking read inside the event transaction, preventing a stale consistent snapshot from accepting a revoked account.
- Added stale caller model regression coverage in CanonicalBookingWriterTest.
- PHP syntax validated; PHPUnit/MySQL not executed. No GitHub commit, no production DB access.

## R2.28
- CanonicalBookingWriter: prevent idempotent replay of an apparently active booking after the event is cancelled.
- Added regression test for cancelled event replay; MySQL/PHPUnit not executed.

## R2.29 — reject corrupted active FIFO queue on new waitlist admission
- Writer now refuses a new waitlist entry if active waiting rows contain duplicate, missing, or zero FIFO positions. The event row remains locked during the check and allocation.
- Added regression test for duplicate active positions. MySQL/PHPUnit not executed; BookingEngine and downstream integrations remain pending. No commit/push.

## R2.30 — legacy FIFO cutover boundary
- CanonicalBookingWriter now rejects active `event_waitlist_entries` rows for the same event even when no legacy `event_booking_groups` rows remain. This closes the queue-jump gap after partial migration.
- This is an isolated staging guard, **not** a BookingEngine/API cutover. MySQL/PHPUnit unexecuted. No GitHub commit or production DB changes.

## R2.31 — canonical-linked waiting entries are not legacy blockers
- Added CanonicalLegacyWaitlistBoundary shared by writer and FIFO promotion. Only unmapped, orphaned, wrong-event or non-active-waitlisted waiting entries block cutover. Valid canonical-linked waiting rows do not block their own promotion.
- Missing mapping column with pending waiting rows fails closed. PHP syntax checked only; MySQL/PHPUnit pending. No commit/push.

## R2.32 — FIFO projection atomicity
- Promotion now atomically resolves an existing canonical `event_waitlist_entries` projection to `promoted` alongside the `EventBooking` status update.
- Fail closed for duplicate, mismatched event/status/sequence projections. Absence of an optional projection remains supported.
- PHP syntax checked only; database/feature tests pending. No commit or deployment.

R2.33: Cancel waitlisted canonical booking and linked waiting projection in the same transaction; reject inconsistent projection. PHP lint only, no DB test.

R2.34: Removed event_waitlist_entries from external dependency rejection in canonical cancellation; the linked waiting projection is now validated and cancelled atomically as intended. Added regression test. PHP syntax only; DB tests pending.

R2.35: Only MySQL duplicate-key 1062 / PostgreSQL unique violation 23505 enters booking conflict recovery; FK/check failures now propagate rather than masquerading as ALREADY_BOOKED. PHP lint only; MySQL/PHPUnit not executed. No GitHub commit.

R2.36: Added read-only per-event CanonicalCutoverAudit. Reports legacy groups/attendees, unmigrated waitlist, series ownership, paid lifecycle blocker, active FIFO corruption and canonical counts. This is a diagnostic building block for bulk migration, NOT BookingEngine cutover or authorization to deploy. No DB mutation; PHPUnit/MySQL not run; no GitHub commit.

R2.37: Cutover audit now counts legacy attendees through their booking group when the attendee table lacks calendar_event_id; orphan attendees are reported. Missing legacy tables/keys fail closed rather than being mistaken for zero records. PHP lint only; database tests pending. No GitHub commit.

R2.38: CanonicalLegacyWaitlistBoundary now treats inactive canonical booking projections as unmigrated blockers. CanonicalCutoverAudit reports duplicate waiting projections and FIFO sequence mismatches (when the optional sequence column exists). Static PHP syntax checked; MySQL/PHPUnit not executed. No GitHub commit.

R2.39: CanonicalCutoverAudit now checks canonical-ledger duplicate active members, confirmed seats exceeding finite event capacity, active bookings with terminal statuses, and inactive bookings retaining live statuses. This is a read-only audit extension; not wired to BookingEngine and not database-tested. No commit or production DB access.

R2.40: Added read-only `booking:audit-canonical-cutover` Artisan command. Supports `--event` and bounded `--limit`; emits one JSON line per event and summary, exits nonzero on blockers/audit errors, and does not mutate records. Must verify command discovery in actual Laravel bootstrap and run DB-backed tests before use. This is NOT a BookingEngine cutover. No GitHub commit.

## R2.41 — cutover audit pagination and completeness
- `booking:audit-canonical-cutover` now detects remaining events by reading one extra ID, rather than equating exactly `--limit` inspected events with truncation.
- Adds `--after-id` cursor and reports `last_event_id` / `next_after_id` for repeatable batched inventory.
- A truncated scan returns nonzero exit status; it can no longer be mistaken for a full clean audit.
- `--event` and nonzero `--after-id` are mutually exclusive.
- PHP syntax checked; Laravel command registration and DB integration not yet tested. No production DB accessed, no GitHub commit.


## R2.42 — waiting projection audit correctness
- Fixed audit to compare `event_waitlist_entries.sequence` to `event_bookings.waitlist_sequence` (previously checked a non-existent projection column and silently skipped the mismatch).
- Audit now explicitly blocks waiting projections with missing canonical booking, wrong event, inactive or non-waitlisted booking.
- Added two regression tests. Tests require project Laravel/MySQL harness; not executed in this environment.
- Local staging only. No GitHub commit, production DB access, or deployment.

R2.43: Cutover audit now detects live waitlisted canonical bookings linked to non-waiting projection statuses and duplicate canonical projection links across *all* statuses (not only waiting). Read-only checks. PHP syntax checked only; MySQL/PHPUnit not executed. No GitHub commit.

### R2.44 — Canonical waiting projection write integration
- `CanonicalBookingWriter::reserve()` now inserts a matching `event_waitlist_entries` waiting projection in the same DB transaction as a canonical waitlisted booking; absence of required projection schema aborts the transaction.
- Added a regression assertion for booking-to-projection sequence and status.
- PHP syntax and ZIP integrity only; no database integration execution. Existing audit tests that manually insert a second projection may require adjustment to update the writer-created projection instead.

## R2.45 — Canonical FIFO projection fail-closed
- `CanonicalWaitlistPromotion` now requires the projection table/columns and exactly one matching `waiting` entry for each promoted booking. Missing entries no longer silently pass.
- Added rollback regression for a deleted projection.
- This remains staging only; no GitHub commit, MySQL/PHPUnit or production migration performed.

## R2.46 — repair tests after atomic waitlist projection integration
- Fixed audit regression to mutate the writer-created projection rather than insert a second row that violates `event_waitlist_booking_unique`.
- Fixed cancellation regression to assert the writer-created projection before cancellation, rather than inserting a duplicate.
- No production BookingEngine cutover or GitHub commit. PHP syntax validation only; PHPUnit/MySQL not executed.

## R2.47 — S6 API service integration scaffold (disabled)
- Staged the repository's `BookingService.php` with a guarded canonical writer/cancellation route for the existing S6 BookingController.
- Explicit `booking.canonical_s6_cutover_ready` config defaults false, so current BookingEngine behavior remains unchanged. No switch may be enabled until notification/calendar side effects, paid/refund contracts, historical migration and DB tests are complete.
- Canonical S6 route only allows self booking, no waiting-list enrollment; canonical `activeBookingOf` includes waiting status to avoid misleading UI.
- IMPORTANT: This is not a production cutover. BookingGroupController and BookingEngine's other paths still use legacy group writes; do not enable flag on any shared environment.

## R2.48 — S6 canonical availability read contract
- `BookingService::unavailableReason()` in canonical mode now evaluates the event window, FIFO waiting queue and nullable unlimited capacity consistently with canonical writer semantics.
- Legacy mode remains delegated to BookingEngine unchanged. This fixes false `full` on nullable capacities in the S6 read endpoint and prevents displaying available while an active FIFO queue exists.
- Still gated by `booking.canonical_s6_cutover_ready=false`; not a production cutover.

### R2.49 — Canonical group API adapter (staging only)
- Added `CanonicalBookingPresenter` and `CanonicalGroupApiAdapter` for the existing group endpoint response shape using real `EventBooking` IDs, not fake legacy group IDs.
- Adapter provides canonical reserve, cancel and paginated mine; cancellation scopes to original booker and delegates revalidation to canonical cancellation.
- **Not wired into BookingGroupController yet.** Group route semantics and notification/financial integrations must be reviewed before activation. No tests executed beyond PHP syntax.

### R2.50 — BookingGroupController canonical routing (disabled)
- Staged BookingGroupController with canonical reserve/mine/cancel routes behind `booking.canonical_group_cutover_ready` (default false); original legacy routes retained.
- Canonical cancellation is scoped by booker and then revalidated transactionally; request rejects guest/group payloads.
- Canonical POST temporarily returns HTTP 200 for both created and replayed requests; restoring 201/200 distinction requires an atomic writer result contract before cutover.
- NOT ready for enablement: legacy/canonical IDs differ, group history cutoff and side effects need reconciliation; no Laravel/MySQL tests run.

### R2.51 — Canonical group history visibility parity
- Corrected `CanonicalGroupApiAdapter::mine()` to preserve the existing 2-month limit for family-member booking history while keeping own-member history without cutoff.
- `status=active` now explicitly requires confirmed/waitlisted status as well as active=1, preventing contradictory rows from leaking into active listings.
- Controller remains behind disabled cutover flag; full DB tests and side effects remain blockers.

### R2.52 — Canonical API idempotent HTTP status parity
- CanonicalBookingWriter now reports the created/replayed result from the same event-locked transaction via an optional by-reference result flag. Existing callers remain compatible.
- CanonicalGroupApiAdapter passes this result to BookingGroupController: new reservation HTTP 201; matching idempotent replay HTTP 200.
- Cutover stays disabled. Laravel/MySQL/PHPUnit and notifications/payment/history integration remain outstanding.

## R2.53 — Canonical API side-effects bridge (staging only)
- Added `DispatchCanonicalBookingNotification` to use `EventBooking` rather than the legacy `EventBookingGroup` notification job. A queued transition checks the current booking status and retains a stable delivery UUID for retry deduplication.
- `CanonicalGroupApiAdapter` dispatches booking notification and confirmed-booking Google sync only for newly created bookings, not idempotent replays; cancellation dispatches cancellation notification and Google sync.
- Entire bridge is behind `booking.canonical_side_effects_ready` (defaults false). `afterCommit()` prevents side effects before commit, **but is not a durable outbox**; crash between commit and enqueue can lose notifications. The final integration must implement/test durable handoff, FIFO-promotion notices, and verify MemberNotifier schema/queue connection before enabling.
- No production DB changes or GitHub commit. PHP syntax only; integration tests pending.

## R2.54 — Waitlist withdrawal and projection integrity
- Waitlisted bookings may withdraw after the confirmed-booking cancellation deadline; confirmed bookings retain the deadline.
- Fail closed if waitlist projection table/link or exactly one matching row is missing; test added.
- Not tested with MySQL/PHPUnit; no GitHub commit or production changes.

## R2.55 — Deadline cancellation regression tests
- Added two integration regression cases: waitlisted withdrawal after confirmed-seat deadline synchronizes projection; confirmed cancellation after deadline stays blocked.
- MySQL/PHPUnit not run; GitHub untouched; cutover remains disabled.


## R2.56 — Presenter cancellation eligibility parity
- Canonical booking DTO no longer advertises can_cancel=true for paid bookings, occurrence-level legacy groups, missing canonical dependency columns, or existing invoice/attendance/makeup dependencies; the cancellation service rejects these cases.
- This is presentation parity only; no paid refund, attendance cancellation, or production cutover implemented.


## R2.57 — Canonical cancellation presenter parity
- Allow current member to see self-cancellation even when family booker differs; former delegate remains unauthorized.
- Require exactly one matching FIFO projection before advertising waitlist cancellation.
- Still pending: PHPUnit/MySQL, full BookingEngine cutover, payments, notifications and migration; no GitHub commit.

## R2.58 — Presenter self-cancellation authority alignment
- Corrected canonical `can_cancel` to permit verified member self-cancellation without requiring family-manager status.
- A former booker still needs live family delegation; actor must be active and verified.
- Static syntax/ZIP verification only; DB/PHPUnit pending. No GitHub commit or production DB operations.


## R2.59 — Canonical self-cancellation API authorization
- Fix canonical booking-groups cancellation endpoint: a verified member can cancel their own seat even when booked by another family member.
- Controller scopes booking by original booker OR seat member; adapter permits either identity; cancellation service remains authoritative and rechecks current permissions in transaction.
- Former family booker still cannot cancel after delegation revocation. No GitHub commit or production database access.


## R2.60 — Canonical family notification recipients
- Canonical notification now targets both the booking creator and the participating member when different; self bookings notify only once.
- Stable per-recipient UUIDv5 delivery identifiers prevent retry duplicates and prevent the first recipient's notification from suppressing the second.
- Existing stale-transition guard remains. Still requires durable outbox, integration tests, and full cutover; no GitHub commit or production changes.


## R2.62 — FIFO promotion side-effect handoff
- Cancellation now exposes IDs of bookings promoted inside its transaction via optional output parameter; existing callers remain compatible.
- Group API adapter schedules notification and Google sync for each promoted booking after successful cancellation, behind the disabled side-effects flag.
- Known gap: afterCommit queue dispatch is not a durable transactional outbox; full Laravel/MySQL tests and cutover are still pending.


## R2.63 — Transactional outbox foundation (NOT production enabled)
- Added canonical_booking_outbox migration with unique event keys and pending delivery index.
- Added CanonicalBookingOutbox::record(), requiring an open DB transaction.
- CanonicalBookingWriter now records newly created booking transitions in the SAME transaction when booking.canonical_outbox_ready is explicitly enabled (default false).
- Remaining blockers: cancellation/promotion outbox, durable dispatcher/consumer and replay, notifications and Google Calendar integration, payment/refund, attendance, backfill, integration tests. Do NOT enable flags or deploy.


## R2.64 — transactional cancellation and promotion outbox
- Cancellation now records `cancelled` and each FIFO `confirmed` promotion inside the same DB transaction, behind the disabled `booking.canonical_outbox_ready` flag.
- Rollback covers booking, projection and outbox on failure; event keys are unique.
- Pending: durable consumer, notification/calendar dispatch, PHPUnit/MySQL tests and full cutover. No GitHub commit.

## R2.65 — Durable in-app notification outbox consumer
- Add `booking:deliver-canonical-outbox` artisan command with disabled-by-default `booking.canonical_outbox_delivery_ready` gate.
- Row-lock and recheck pending state; use deterministic per-transition/per-recipient UUIDs; deliver via MemberNotifier within the same transaction as the delivered marker. Retry failures after five minutes.
- Stale state changes are marked superseded; booking reference and recipient failures are retried rather than silently discarded.
- LIMITATION: Google Calendar sync is NOT yet driven by durable outbox; delivery scheduling, command registration, and database-backed integration tests are still pending. Do not enable in production.

## R2.66
- Canonical mine visibility: member sees own bookings regardless of delegated booker; original booker sees delegated records only within two months.
- No database integration tests or GitHub commit performed.

### R2.67 — Outbox event cancellation handling
- Outbox consumer now recognizes `event_cancelled` transition already accepted by the producer.
- No change to dispatch gate, no production migration, and no runtime integration test performed.

### R2.68
- Outbox consumer: accept event_cancelled against canonical cancelled booking state, rather than incorrectly superseding it.
- Outbox consumer: after ten failed delivery attempts, mark event failed for manual review; retain error and event record.
- PHP syntax and ZIP integrity only; Laravel/MySQL/PHPUnit not run. No GitHub commit.


## R2.69 — Operator-controlled failed outbox retry
- Added `--retry-failed=<id>` to `booking:deliver-canonical-outbox`. Only a row in `failed` state can be reset to `pending`; attempts reset and availability set to now. The command exits without delivering the event, requiring a second explicit invocation.
- Feature gate remains required. No automatic retries of terminal failures. PHP syntax checked only; database integration tests pending.


## R2.70
- Manual failed-Outbox retry now locks and revalidates the booking/event relationship and current transition before resetting; stale or inconsistent failed events cannot be requeued.
- No GitHub commit, migration execution, or production DB access.

R2.71: Add isolated outbox transaction/idempotency/rollback regression tests; syntax checked only, no PHPUnit/MySQL execution.

## R2.72 — Outbox regression test correction
- RefreshDatabase wraps tests in transactions, so testing transactionLevel()==0 there is invalid; replaced with unsupported-transition rejection.
- CanonicalBookingWriter::reserve already creates a confirmed outbox event; rollback test now inserts a distinct cancelled event and verifies rollback preserves baseline.
- No PHPUnit/MySQL execution or GitHub commit.


## R2.73
- Add Outbox regression tests for distinct member-cancelled vs event-cancelled transitions, idempotency, and initial pending/attempt state.
- PHP syntax and archive checks only; PHPUnit/MySQL remain unexecuted.
- No GitHub commit or production database changes.


## R2.74
- Added CanonicalBookingOutboxCommandTest: delivery disabled gate, invalid batch size, invalid failed-event ID.
- Tests authored but not executed; full Laravel repository and database runtime are required.

R2.75: Added regression tests for manual failed-outbox reset (no immediate delivery) and rejection of obsolete confirmed events after cancellation. PHP syntax only; PHPUnit/MySQL not run.


## R2.76 — Outbox booking row lock
- Both failed-event reset and delivery lock the canonical booking row (`lockForUpdate`) while validating the transition, preventing concurrent booking cancellation/promotion from changing its status between validation and notification insertion.
- Lock order: outbox row then booking row. Full deadlock/concurrency verification pending; no cutover flags enabled.

## R2.77 — avoid duplicate dispatch while Outbox delivery enabled
- CanonicalGroupApiAdapter legacy post-commit notification/calendar dispatch is bypassed when canonical_outbox_delivery_ready is true; prevents duplicate notification delivery from both dispatch paths.
- Calendar synchronization remains a blocked cutover dependency: enabling Outbox delivery alone does not provide durable calendar sync. Do not enable flags until calendar outbox consumer and integration tests are completed.
- Syntax-only validation; no Laravel/MySQL/PHPUnit integration run.


## R2.78
- Added `--dry-run` to `booking:deliver-canonical-outbox` to inspect eligible pending event IDs without dispatching or mutating database records.
- Reject `--dry-run` together with `--retry-failed` to avoid ambiguous operator actions.
- PHP syntax only; no Laravel/MySQL/PHPUnit run. Feature flags remain disabled.

R2.79: Added dry-run nonmutation and incompatible-option regression tests; PHPUnit/MySQL not run.

R2.80: Manual retry of failed Outbox events now requires separate canonical_outbox_manual_retry_ready approval (default false). Added regression test; enabled gate explicitly in existing retry tests. PHP syntax only, no PHPUnit/MySQL run.
