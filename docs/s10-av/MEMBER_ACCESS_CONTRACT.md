# Shared member access contract

## Canonical audience values

Guest = `guest` (no authenticated request identity). register member = `register_member` (authenticated active registered account). Verified Member = `verified_member` (authenticated active account satisfying existing VerificationPolicy). Verification is a qualification of registration, not an independent role.

Membership and verification records persist when a user logs out. The server must use the authenticated request user, never a client-supplied user ID, to evaluate access. A request without valid authentication always evaluates as Guest, even if a registered account exists. Inactive authenticated accounts are denied, not silently converted to Guest.

VIP membership levels, badges, and specific-user grants are additional qualifications, not administrator roles. Revoked or expired levels do not grant access. Explicit deny overrides allow. All configured ancestor layers must pass. Content browsing never overrides AV entitlements, verification middleware, or the permanent ban on guest event bookings.

All Shared Modules must reuse these audience values and the existing member/verification data; do not invent module-specific member-status enums. The existing Filament administrator and settings.manage permission remain the only configuration boundary.

## Required regression coverage

Anonymous versus authenticated registered versus verified; inactive account; verification-policy changes; expired/revoked level; specific-user deny; page/list/topic inheritance; direct API access; logout with retained membership; and guest booking denial.

## Cross-PR review gate

This contract applies to every open Shared Modules PR, not only the AV implementation. Review PR #39/#40/#45 for check-in attendee ownership, PR #41 for article attachment reads, PR #42/#43/#47 for member-only bookings and notification recipients, PR #49 for grandfathered verification, and PR #46 for navigation and AV. A passing CI result is necessary but not sufficient; verify request authentication, database ownership, active membership, verification policy and direct API access before acceptance. Do not merge a conflicting PR until it is fixed and regression-tested.

## Verification policy integration: PR #49

The persisted `verification_grandfathered_at` eligibility from PR #49 must be evaluated exclusively by the shared `VerificationPolicy`. Never interpret the field as phone verification, login status, an admin role, or a new membership level. Existing grandfathered eligibility survives logout; requests without an authenticated user remain Guest. Before merging PR #46 or #49, test both changes together on the same branch to prevent a stale verification-policy implementation from overriding the other PR.

## Member-only booking integration: PR #43

For group bookings, `member_user_id` is an attendee reference, not an authentication token. The booking actor must come from the authenticated server request. Each attendee must be an existing active member and must be either the actor or explicitly authorized through the existing family delegation relationship. Do not infer authorization from the supplied attendee ID. Guest booking is prohibited, including admin-created bookings without a registered member. Validate authorization again for cancellation and changes, not only on initial booking.

## Open PR #42 / #47 conflict gate (2026-10-27)

Patch review identified guest-booking language in PR #42's `docs/booking-durable-intent-gate.md` and guest notification handling in `app/Jobs/DispatchBookingNotification.php`. PR #47 instead documents member-only schema constraints. Treat this as an unresolved integration conflict, not an approved guest-booking exception. Before either PR is accepted, inspect the merged booking schema and runtime job paths together: no guest booking creation, no guest recipient delivery, no client-supplied identity as an actor, and durable notifications / Google sync only for valid authenticated member bookings. Add behavioral regression tests for enqueue, retry, cancellation and waitlist promotion. Do not remove durable-intent guarantees merely to eliminate legacy guest code.

## Parallel delivery: member qualification and AV center (2026-10-27)

Both workstreams remain active in parallel. PR #49 owns the persisted verification-policy grandfathering behavior; PR #46 owns AV catalog/content-access authorization and reuses the shared `VerificationPolicy`. Do not reimplement or fork verification policy inside AV. A member grandfathered under the email-to-phone policy change remains eligible for `verified_member` only when an **authenticated, active** account satisfies `VerificationPolicy`; an anonymous request stays `guest` and receives no member-only content. Grandfathering must not set `phone_verified_at` or grant a role/VIP level.

Before either PR is accepted, execute integration tests on a combined tree for: email-verified existing member grandfathered after policy switch; new email-only member denied under phone policy; logout/anonymous guest visibility; inactive account denied; AV page/list/topic inheritance and direct API; and independent AV entitlement checks. Track PR #49 and #46 separately, keep both Draft and do not merge merely because their individual CI checks pass.
