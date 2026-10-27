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
