# AV × Member verification integration gate (2026-10-27)

## Immutable source refs
- AV PR #46: `b71a78a0cc08dcb15245efc58e84cdda18ff0d78`
- Membership PR #49: `68295bbde8decd14d19d984d0ebc4d53bf41790f`
- This integration branch is based on AV #46. The membership patch is **not yet applied**.
- PR #49 itself is based on `fix/booking-member-id-required-20261026`, not `main`. Never blindly replace whole files from that branch.

## Required integration
1. Apply the seven file changes from PR #49 to this branch while preserving AV #46's changes, especially `User`, `Settings`, `VerificationPolicy`, and existing S3 tests. Resolve overlapping hunks explicitly.
2. Apply `2026_10_27_000001_add_verification_grandfathered_at_to_users.php` and verify migration ordering, rollback and MySQL 8.4 behavior.
3. Replace the old phone-switch assertion in `AvEntitlementTest::test_video_access_rejects_expired_and_revoked_levels` with a test of an **existing persisted email-verified member** being grandfathered; ensure the test refreshes the model after the policy switch. Also test a newly email-only member is denied under phone policy.
4. Preserve expiry, revocation, inactive-member, unpublished-media, direct endpoint, and per-item access denial tests. Never conflate verification eligibility with AV entitlement.
5. Confirm whether per-member video overrides are approved; track overrides exist, video overrides do not. Do not silently add schema or grants.
6. Run backend feature tests and MySQL migration/rollback checks at this branch's exact HEAD, then run contract/mobile checks. CI green on separate PRs is not an integration result.

## Acceptance
Status: **NOT TESTED / BLOCKED** until the actual seven-file patch is integrated and all checks pass. No merge or deployment. Record exact SHA and failures in Shared issue #35 and existing PRs.
