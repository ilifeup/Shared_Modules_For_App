# FamilyUser identity and registration policy

## Authoritative database contract

- Every `users.id` identifies exactly one membership record.
- `users.FamilyUser = true`: managed family member; independent login and password recovery are forbidden.
- `users.FamilyUser = false`: independently registered account; ordinary login and verification rules apply.
- A verified manager creates a family member with `FamilyUser = true` and `verified_status = 2` in the same database transaction.
- Family member verification does not grant independent login.
- The family member's historical bookings remain attached to the original `users.id`.

## Independent registration later in life

A person who previously appeared as a managed family member may register independently, for example after obtaining their own phone. This is a **new account** with a **new `users.id`**, `FamilyUser = false`, and the normal unverified registration status. Do not reuse the managed member's ID, convert that record, migrate bookings, or infer identity matches from names, birthdays, contact details or national ID.

An independent registration may **optionally** supply family information. It is optional profile/context data only: it must not automatically find, merge, or claim any existing family member record, and must not grant delegation. Any subsequent relationship creation requires its own authorized workflow.

## Contact and recovery invariants

- Independent accounts must not share login email or phone identifiers.
- Managed family members may share family contact details with their own manager; such contact details are not login or recovery credentials for managed accounts.
- OTP and reset tokens must never select an arbitrary account when a contact matches multiple rows.
- Database constraints and the API must enforce these rules together; do not remove existing unique constraints without a safe replacement for independent login identifiers.
- Google/Apple provider subjects are unique per provider and are never themselves proof of verified membership.

## Explicitly rejected

- Converting `FamilyUser` from true to false to promote a child to an independent account.
- Reusing the managed family member's `users.id` on independent registration.
- Automatically linking, merging or transferring past activity based on optional family details.
- Adding a redundant `users.login_enabled` column.
