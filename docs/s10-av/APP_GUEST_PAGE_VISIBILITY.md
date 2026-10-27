# Shared App guest page visibility — administrator configuration (MVP)

## Goal
A single administrator decides which **App page navigation entries** guests can see. This is shared navigation configuration for consuming apps, not a grant to use protected backend functions. Default AV page is **visible but verification-gated**, unless the administrator explicitly changes its guest visibility.

## Administrator settings
Each registered App page has a stable `page_code` and a guest display mode:
- `public`: guest sees the page entry and may open the public page; only public content/API is accessible.
- `verification_gate`: guest sees the page entry, but tapping it shows a login/verification prompt; protected page content is not loaded.
- `hidden`: guest navigation entry is not shown. Deep links to protected content must still enforce backend authorization.

Settings are managed by the **administrator in the backend**. A future UI should provide a page list, mode selection, save and audit of who changed it. One administrator is sufficient for MVP. Use one shared setting source; do not hardcode separate guest menus per platform.

## Invariants
1. Visibility and authorization are independent. Changing visibility never disables authentication, membership verification, event booking restrictions, or per-item AV entitlements.
2. The backend remains authoritative for protected APIs. Do not rely on hidden tabs, route guards, or cached menu configuration as security controls.
3. `verification_gate` behavior: unauthenticated guest sees「請先登入並完成會員驗證，才能使用此功能。」; authenticated but unverified member sees「請先完成會員驗證，才能使用此功能。」 with a link to the required verification flow. Verification policy and V01-A grandfathering are evaluated by the existing shared verification service.
4. On missing/invalid configuration, use the page's explicit safe default, never grant access. For AV, default is `verification_gate`.
5. An administrator changing a page's guest visibility affects navigation on next config refresh; currently opened protected content must still be checked server-side.
6. Event booking never permits guest reservations, regardless of page visibility. An event listing may be public if explicitly configured, but reservation actions require registered member IDs.
7. Guest configuration must not remove navigation access for signed-in users; member visibility and content entitlement are separate future concerns.

## Acceptance cases
- AV is visible to guests by default, but guest tapping it sees login/verification prompt and no media catalog.
- Admin selects `hidden` for AV: guest menu no longer includes AV; direct protected API remains 401.
- Admin selects `verification_gate`: guest sees entry and blocking prompt; unverified signed-in member sees verification prompt.
- Admin selects `public` for a genuinely public page: public content opens; protected API is still protected.
- Admin cannot use a guest visibility setting to make booking or protected media publicly accessible.
- V01-A grandfathered members continue to satisfy member verification independently of guest display mode.

## Scope / status
**Contract only**. No backend database schema, administrator Filament controls, configuration endpoint, or Flutter navigation integration is claimed as implemented by this document. Implement those with tests in a separate Shared PR or subsequent commit before marking feature complete.

## Extended requirement: three independent authorization layers (2026-10-27)
The administrator must configure **all three levels**, not just guest navigation:
1. **App page / navigation**: who can see the App page entry; optionally show a visible but locked entry.
2. **List page**: who may enter and retrieve each list or category; an App page being visible does not authorize its lists.
3. **Topic / detail**: who may open a specific topic, article, video, track, or event detail; a list being visible does not authorize its items.

Each layer supports explicit **allow** and **deny** against the existing member eligibility dimensions: guest, signed-in member, verification satisfied (including V01-A grandfathering), membership/VIP level, approved badge, and specifically selected member. An explicit deny overrides a matching allow. Missing or unknown eligibility must never grant access. Do not confuse badge eligibility with administrator role.

The administrator UI should use a hierarchy: App page → list → topic, showing effective permission, its source, and the applicable allow/deny rules. Defaults may be inherited for convenience, but **every layer is checked independently**. Topic-level rules may further restrict or explicitly grant access relative to inherited list defaults **only if** the user is authorized to navigate to that topic; a denied parent list must not be bypassed by a deep link. If a list may show locked topic teasers, do not expose protected body, media, private URLs, or sensitive metadata.

### Backend enforcement and operations
- Centralize the effective decision in a reusable authorization service; enforce it on list and detail APIs and direct/deep links, not only Flutter UI.
- Return a consistent blocked response and actionable login/verification/upgrade message without disclosing protected data.
- Separate **viewing** from **actions**: event bookings still require member IDs and never accept guests; media playback still requires its own per-item entitlement; other write actions retain their existing guards.
- Avoid a new parallel membership/role system: reuse the existing verification policy, VIP/member levels, badge approvals, and user identifiers.
- Keep the first implementation minimal: one admin, three scopes, allow/deny, explicit priority, and audit. Do not add a general-purpose policy language or elaborate role builder.

### Acceptance scenarios
1. Guest sees an App entry but cannot open its restricted list; prompt to sign in or verify.
2. Guest may open a public list but cannot open a restricted topic, including by direct URL.
3. A verified member may view a list but cannot view a VIP-only topic.
4. A VIP or badge-qualified member can open an allowed topic unless explicitly denied.
5. Administrator changes a list/topic rule; subsequent API requests use the updated effective policy.
6. A member eligible for a topic cannot bypass a forbidden parent App/list using a deep link.
7. No visibility rule allows guest event bookings or unauthorized media playback.
8. Existing V01-A grandfathered members are treated as verification-satisfied, without forging phone verification.

**Status:** requirements and acceptance contract only. Backend schema, admin UI, authorization service, and Flutter integration still need implementation and CI verification.
