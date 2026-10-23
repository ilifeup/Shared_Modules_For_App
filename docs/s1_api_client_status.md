# S1 API Client Adapter — Work in Progress

This slice adds a testable **pure Dart** application-independent client for own-profile read/edit and published-notice list. A fake transport validates request paths, HTTP methods and that the PATCH payload contains **only** display_name.

The transport is deliberately injected: production Apps must provide a Dio adapter with bearer token handling, 401/403/422 mapping, secure storage and request-id correlation. Those behaviors are not implemented here.

Not yet delivered: Sanctum auth, Filament admin, Laravel persistence/Policies, Flutter screens, MySQL integration or physical Android validation. No production DB/security schema is modified. Endpoint names are the S1 proposal; final API/schema review remains required.
