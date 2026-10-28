# Course MVP v2.4 — approved implementation contract (2026-10-28)

Status: APPROVED business requirements; IMPLEMENTATION NOT YET VERIFIED. Single administrator only. Reuse existing single-member Booking and M11 attendance. No automatic merge, production migration, deployment, financial ledger, or second booking engine.

## Core entities and capacity
- Location → floor/landmark → Studio → course term → course sessions.
- A term has one regular enrollment capacity. All term sessions represent the same enrolled roster. Term waitlist is FIFO; waitlisted people do not consume capacity. Pending admin fee confirmation holds a term seat until manually rejected/cancelled.
- Each session has **separate makeup capacity**, default **1**, configurable in admin. Normal term enrollment consumes only regular term capacity. Makeup consumes only the target session makeup capacity. Do not count makeup attendees as regular term enrollees.
- A makeup attendee **MUST be present in the target session's ordinary booking/attendance roster**, labeled “補課”, with their unique verified `users.id`. Do **NOT** create `course_enrollments` for the target term. Make a distinct one-member makeup Booking linked to the original leave and target `course_sessions.id`.
- A roster query for one session must include both regular session Bookings and active makeup Bookings. M11 check-in/checkout must accept both, subject to the existing authorization and QR rules. On cancellation/reassignment, remove the old makeup Booking from the active roster and free its makeup slot; keep historical linkage.
- Family members cannot log in; an authorized guardian operates on their behalf. Membership authority is `users.verified_status=2`. No guest bookings.

## Enrollment and leave
- Whole-term signup first creates a pending enrollment and reserves one regular seat. Single administrator manually checks “已確認收費”; then create exactly one Booking for each session atomically/idempotently. No online money, refund amounts, transactions, or refund-status database.
- Member term cancellation: “申請取消報名”, default cutoff 10 days before the FIRST session. Requires reason and administrator approval; pending request does not free the seat. Hide button after cutoff and enforce on server.
- Member session leave button exactly **“課前取消單堂(請假)”**. Default cutoff 48 hours before THAT session; requires reason. Hide button after cutoff and enforce on server. Keep original booking and display “已請假”, never auto-convert to “缺課”.
- Admin resolves leave by offline “單堂部分退費處理” or manually selecting a session in another term for makeup. The original leave remains “已請假” and displays the handling outcome. No member self-service second makeup; admin may reassign manually.
- After a finished, noncancelled session, an attendee with no check-in and no leave is “缺課”; with check-in “已出席”. Makeup sessions follow the same attendance rules. Checkout is optional, default off.

## Minimal persistence requirements
- `course_categories`, `course_terms`, `course_sessions`, `course_enrollments`, `course_cancel_requests`, `course_session_leaves`, `course_makeups` (or a smaller equivalent using existing tables).
- `course_sessions` references the existing `calendar_events.id` for Booking/M11 integration; has `makeup_capacity` default 1 and regular roster identity through its term.
- `course_enrollments` has member_user_id, booker_user_id, term ID, state, admin-confirmation timestamp; a pending enrollment reserves a seat. `course_makeups` links the original leave to the active makeup booking.
- Existing event_booking tables on PR #53 still contain legacy group/attendee structures. Reconcile and validate the approved single-member refactor before implementing course booking creation; do not assume those tables have already been cleaned.
- Preserve existing data and avoid destructive migrations without backup, rehearsal, inventory, and explicit approval.

## Acceptance
1. Term capacity 20: 20 enrolled/pending seats; 21st is FIFO waitlist.
2. Admin fee confirmation for a 12-session term produces 12 unique member Bookings; retry does not duplicate.
3. Target session regular roster of 20 plus makeup capacity 1: exactly 1 makeup Booking is permitted; it appears in **that session's Booking list and M11 attendance list**, labeled makeup, but never in target term enrollment.
4. Makeup slot full rejects a second makeup. Admin reassignment updates both session rosters and capacities.
5. Member cannot request another makeup; admin may manually reassign.
6. Leave within cutoff persists with reason; after cutoff both UI hides button and API rejects.
7. Original leave stays “已請假” after session end; unexcused absence becomes “缺課”.
8. Member, guardian, admin permissions follow existing single-member Booking contract.
9. All relevant Backend, OpenAPI, Flutter and M11 regression tests pass on exact commit SHA; CI green alone is not acceptance.

## Development sequencing
First inspect PR #53 HEAD and current Booking/M11 schemas, then implement minimal migrations and Backend service, then API/Filament, then Flutter, then tests. Work in coherent batches and keep #53 Draft. Do not claim these features are implemented merely because this contract is committed.
