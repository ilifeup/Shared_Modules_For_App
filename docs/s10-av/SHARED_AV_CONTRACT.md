# Shared AV contract — S10 / initial MVP

Shared Modules is the source of truth for reusable API contracts, entitlement rules, and client behavior. Each consuming app owns its own backend/database and media assets; no shared production media service or cross-app login.

## Fixed authorization
- Both Music and YouTube require authenticated, active, identity-verified members at **every** endpoint, including series, page placements, article embeds and direct media requests.
- Music feature visibility is NOT a track grant. Per-track entitlement uses active membership level(s) and explicit per-user override. Explicit per-user deny wins over a level allow. No entitlement means no audio, no encryption key and no offline license.
- Client UI exposes play only, never a download button or the word 下載. Authorized first play internally fetches encrypted audio into app-private storage. No plaintext persisted.
- Offline grants last 365 days; expiration requires authenticated online renewal. Ciphertext can remain stored but is unusable without a valid grant. Revocation while offline is not immediately enforceable; refresh on reconnect.
- YouTube videos are protected within the app by server-side per-video member-level checks. Public YouTube URLs cannot be restricted outside the app. Do not download YouTube videos.

## Reusable contract
- GET /av/music/tracks: member-authorized list only; never expose storage paths or cryptographic secrets.
- POST /av/music/tracks/{id}/play: authenticated/verified, per-track entitlement; return a short-lived encrypted-asset authorization and offline grant metadata only when allowed. No user_id supplied by client.
- GET /av/videos: only videos permitted for this member; filter before pagination.
- GET /av/videos/{id}: direct access rechecks entitlement.
- GET /av/video-series: series filtered to permitted videos; do not leak denied titles/IDs through counts.
- GET /av/placements/{page_code}: placement results filtered by member verification and per-video entitlement; embeds use the same check.

## Backend test requirements
1. Anonymous 401; authenticated but unverified 403 for all AV endpoints.
2. Level allow, individual allow, individual deny override and expired/revoked level assignment.
3. Direct video endpoint, series, article embeds and page placements cannot bypass entitlement.
4. Audio authorization cannot return path, key, license or ciphertext when unauthorized.
5. Offline grant expires at 365 days and requires reauthorization; revocation on reconnect.
6. Idempotent concurrent play authorization; no plaintext file left after failed acquisition.
7. Existing S0/S1/S9 CI stays green. CI success does not automatically close a PR.

## Implementation ownership
Shared first: API contract + entitlement decision logic + tests. Tiandi second: its Filament administration, encrypted file storage, playback, YouTube import, page composition and device integration. Avoid duplicating business rules.
