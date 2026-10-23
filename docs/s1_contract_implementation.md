# S1 Contract Code — Non-Persistent Layer

Status: IMPLEMENTED IN PR / CI PENDING.

This change adds pure Dart member profile and notice DTO parsing plus executable contract smoke checks. The profile PATCH projection contains only `display_name` to avoid accidental role, VIP, email or verification changes. The notice DTO parses publication time but never substitutes for server-side visibility checks.

This does **not** implement login, token persistence, HTTP requests, Laravel controllers, Filament screens, MySQL migrations or an Android application. No user database or security model is changed. S1 acceptance matrix remains NOT TESTED for backend/UI/E2E/device.

Run `cd packages/flutter/shared_core && dart pub get && dart run test/contracts_smoke.dart`. CI should run the command and fail on invalid contract behavior.

Exact API field types and token lifetime remain proposals pending schema/security review.
