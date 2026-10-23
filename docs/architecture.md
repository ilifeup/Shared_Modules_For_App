# Shared Modules Architecture — S0

Status: DESIGN ONLY; no implementation or integration tested.

## Ownership
Three independent applications: Tiandi, Wuxiang, EachCore. Each has its own Laravel API, Filament admin, database, users, secrets and deployment. The shared repository distributes code and contracts, not a fourth runtime backend, shared identity provider, or central database.

## Layers
- L0: reusable Flutter UI/widgets/design conventions; no brand or App-specific data.
- L1: reusable Dart API clients/models and OpenAPI contracts.
- L2: portable Laravel services, validation, policy patterns and optional Filament resources; installed into each App independently.
- L3: application-owned domain rules, migrations and integrations; never silently standardized across Apps.

## Integration
Shared modules use versioned references; application repositories pin reviewed tags/commits. Flutter calls its own application's Laravel API; Filament manages the same application's data. Laravel authorization and domain services are authoritative. The shared repository cannot reach production data.

## Contract baseline
REST /api/v1, success {data,meta?}, failure {error:{code,message,fields?,request_id}}, X-Request-Id; UTC persisted timestamps. Exact endpoints, database migrations and public package boundaries are design proposals until S1 tests.

## S1 vertical slice
Auth/Sanctum → member profile (M01) → minimal authorization (M02) → notices (M06) → essential audit (M07). Prove Flutter login, authenticated profile read/update, Filament notice publication and Flutter notice retrieval against one disposable PoC database. Denied/expired credentials must fail correctly. Do not implement M03/M08/M11 business rules without approval.

## EachCore boundary
WooCommerce remains source of truth for SKU, pricing, stock, carts, orders, payment and refunds. Shared modules must not create a competing transaction ledger.

## Explicit exclusions
No central SSO, shared production database, deployment, paid cloud provisioning, iStudy server access, or pre-created empty modules M03–M20.
