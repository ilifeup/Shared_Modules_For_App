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
