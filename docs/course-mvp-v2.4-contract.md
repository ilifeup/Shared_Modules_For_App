# Course MVP v2.4 — approved implementation contract (2026-10-28)

Status: APPROVED business requirements; IMPLEMENTATION NOT YET VERIFIED. Single administrator only. Reuse existing single-member Booking and M11 attendance. No automatic merge, production migration, deployment, financial ledger, or second booking engine.

## Core entities and capacity
- Location → floor/landmark → Studio → course term → course sessions.
- A term has one regular enrollment capacity. All term sessions represent the same enrolled roster. Term waitlist is FIFO; waitlisted people do not consume capacity. Pending admin fee confirmation holds a term seat until manually rejected/cancelled.
- Each session has **separate makeup capacity**, default **1**, configurable in admin. Normal term enrollment consumes only regular term capacity. Makeup consumes only the target session makeup capacity. Do not count makeup attendees as regular term enrollees.
- A makeup attendee **MUST be present in the target session's ordinary booking/attendance roster**, labeled “補課”, with their unique verified `users.id`. Do **NOT** create `course_enrollments` for the target term. Make a distinct one-member makeup Booking linked to the original leave and target `course_sessions.id`.
- A roster query for one session must include both regular session Bookings and active makeup Bookings. M11 check-in/checkout must accept both, subject to the existing authorization and QR rules. On cancellation/reassignment, remove the old makeup Booking from the active roster and free its makeup slot; keep historical linkage.
- Family members cannot log in; an authorized guardian operates on their behalf. Membership authority is `users.verified_status=2`. No guest bookings.

## Waitlist capacity (activities and courses)
- **Every activity independently configures its waitlist capacity**, default **5**, in the administrator backend. This is separate from its regular booking capacity. The activity waitlist cannot exceed its configured limit; a full waitlist rejects further entries. FIFO promotion remains.
- **Every course term independently configures its whole-term waitlist capacity**, default **5**, separate from regular term enrollment capacity and each session's makeup capacity. Term waitlist entries are for the **whole term**, not per-session; a full waitlist rejects further entries. FIFO promotion remains.
- **Every standalone course (not part of a term)** also independently configures its own waitlist capacity, default **5**, just like a standalone activity. Its waitlist belongs to that one course event, not to any term. A standalone course has its own regular capacity and FIFO waitlist; do not require creation of a course term to use it.
- Waitlisted members do not occupy regular booking/enrollment seats or makeup seats. Only verified members with unique users.id can join; no guest waitlist. Admin can configure a nonnegative waitlist capacity per activity/term; lowering capacity must not silently delete existing waitlist entries.
- Minimal implementation: reuse the existing M08 waitlist for standalone activities **and standalone courses**, with an event-level waitlist capacity default 5; use a term-level waitlist capacity default 5 for whole-term courses. Do not create a second waitlist engine.

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

## Additional waitlist acceptance
10. Two distinct activities default to waitlist capacity 5 independently; sixth waitlist request for either activity is rejected when its own list is full.
11. Two distinct course terms default to whole-term waitlist capacity 5 independently; sixth request is rejected when full. No separate waitlist for each course session.
12. Waitlist entries do not consume regular or makeup seats; FIFO promotion works when regular seats are released.
13. Admin can change each activity/standalone-course/term waitlist capacity; reducing the limit does not delete existing waiting members, but prevents further admission while at/above limit.
14. A standalone course without any course term defaults to 5 waitlist places; its sixth waitlist request is rejected when full, independent of other standalone courses, activities, and terms.

## Development sequencing
First inspect PR #53 HEAD and current Booking/M11 schemas, then implement minimal migrations and Backend service, then API/Filament, then Flutter, then tests. Work in coherent batches and keep #53 Draft. Do not claim these features are implemented merely because this contract is committed.
