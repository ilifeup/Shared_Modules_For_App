# S1 Email / Phone Login — Implementation Gate

Approved user journey: login using **email OR mobile + password**.

Proposed minimal app-local schema:
- `users`: standard Laravel user columns, `display_name` nullable, `phone_e164` nullable and unique **within the App**, plus email unique **within the App**. Do not require both email and phone unless separately approved.
- `notices`: id, title, body, status, published_at, timestamps.
- Local roles: `member`, `admin` managed inside each App; Filament access checked server-side, not by Flutter UI.
- `personal_access_tokens` is App-local Sanctum storage.

**Important:** `phone_e164`, SMS verification, account recovery and phone uniqueness are design proposals, not user-approved implementation details. No real authentication, migration or role seeding is added by this PR.

Acceptance when resolved:
- Same account logs in using either verified eligible identifier and same password.
- Missing/invalid credentials return a generic authentication error.
- One App's credentials/token never authenticate another App.
- Non-admin cannot enter Filament.
- Logout revokes only the current Sanctum token.
