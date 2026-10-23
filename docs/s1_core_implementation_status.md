# S1 First Code Slice — Domain Core

Status: IN PROGRESS. This first code PR is **not** the full Flutter + Laravel + Filament PoC.

Implemented: framework-independent PHP domain rules for notice visibility and display-name validation, and equivalent pure Dart models/validation, plus smoke tests. These packages intentionally do not touch users, tokens, persistence, migrations, admin permissions or production deployments.

Not implemented: Laravel Sanctum login/logout, member creation, profile persistence, Filament notice CRUD, real REST API, Flutter UI, device integration, Android APK and actual end-to-end tests. All remain NOT TESTED.

Why this slice: establish portable, non-App-specific logic with tests before selecting sensitive schema and auth rules. Do not claim this meets S1 acceptance gates.

Run when available:
- `php packages/laravel/shared_core/tests/smoke.php`
- `cd packages/flutter/shared_core && dart pub get && dart run test/smoke.dart`

Security note: These rules are supplemental. Server-side Laravel Policies and query-level publication filters are required before exposing any endpoint.
