# S1 Laravel + Filament Test Harness (non-deployable skeleton)

**Status: dependency declaration and preflight only. This directory is NOT a bootable Laravel application yet.**

Goal: validate a disposable Laravel + Sanctum + Filament + MySQL stack with synthetic test accounts. Do not connect production data or deploy this directory.

The current `composer.json` lists Laravel, Sanctum, Filament and Spatie for dependency compatibility exploration. It intentionally has no migrations, controllers, account provisioning, authorization policies, token lifetime or Filament admin user model because these remain subject to explicit schema/security review. The PHP package path repository is defined for later use; it is not yet required by the harness.

To make this executable, the next approved change must add a real Laravel application skeleton, bootstrap, MySQL test schema, migrations, policies, Filament panel, Sanctum routes and feature tests. CI here checks the dependency manifest structure only, not a working backend.

No production deployment, iStudy access, SSO or fourth production backend.
