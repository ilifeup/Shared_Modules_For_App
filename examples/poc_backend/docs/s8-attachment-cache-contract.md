# S8 attachment reader and cache contract (Draft)

Status: design + initial database models only. **Not production ready.**

## Entitlement and separation

- Uploading is manually enabled/disabled by an administrator on the member record, including when payment is collected outside the App. Membership level and payment transaction records do not grant it automatically.
- The sole membership qualification authority is `users.verified_status = 2` (and `users.is_active = true`). Email/phone verification timestamps and policy are workflow inputs, never independent membership grants. A policy change must not revoke existing verified membership.
- Upload entitlement and article/attachment read permission are independent. Disabling uploads does not revoke authorized reading.
- Defaults for an enabled uploader: one file and 5 MiB total per Asia/Taipei calendar week (Monday 00:00); backend atomic enforcement is still TODO.

## Reader and expiry

- New attachments default to `view_expires_at = created_at + 3 calendar months` (calendar month arithmetic, not 90 days); actual creation logic is TODO.
- The API MUST check article visibility, comment visibility, attachment `removed_at`, and `view_expires_at > now()` for **every** reader authorization request. Never trust a Client-side expiry check alone.
- Expired attachments remain stored on the private server disk. Expiry disables reading immediately, independently of the scheduled maintenance job.
- Never return the private `storage_path` or a public, permanent file URL to the Client.
- Admin may change default view duration; configuration UI and service are TODO.

## Flutter client

1. On every open, call an authenticated lightweight attachment-access endpoint. No offline opening in MVP.
2. On denial, prevent reading and evict the encrypted cached file.
3. On approval, reuse matching cached content without downloading again; otherwise fetch through an authenticated private content endpoint.
4. Store ciphertext in app-private storage, not public Downloads, gallery, system share, logs, or unencrypted backups. Use authenticated encryption (AES-256-GCM) and OS-protected keys (Android Keystore / iOS Keychain).
5. Render PDF/images inside the App. No download/export/share-original-file UI. This does **not** promise screenshot, rooted-device, memory-capture, or reverse-engineering protection.
6. Cache identity must include member ID, attachment ID and content version/hash; logout/account switch evicts access to previous account's files. Keep a bounded cache size.
7. A server denial or expiry blocks viewing even when ciphertext still exists locally.

## Comment list minimal attachment presentation

- Other members see only one attachment/paperclip icon and an aggregate count, e.g. `3 個附件`, on each comment.
- Public comment JSON exposes only `attachment_count` and a minimal access/expiry state where necessary; it MUST NOT expose attachment names, extensions, MIME types, file sizes, private paths, or file URLs.
- Attachment identifiers needed for viewing are resolved only after an authenticated, article-authorized reader request, never as an unauthenticated bulk comment payload.
- The uploader and authorized administrators may see original filenames in their own management flows only.
- A count does not grant read access; expiry, removed status and comment/article permissions remain server-enforced.

## Mandatory viewer watermark

- Every PDF page and every image viewed in the Flutter App MUST show a watermark overlay; members have no disable toggle.
- Authorized administrators can change watermark text and/or graphic and its opacity, size and placement in backend settings. A safe, readable default is required.
- The authenticated reader authorization/config response MUST provide the current watermark configuration version. Each online open revalidates access and refreshes watermark settings; a changed watermark does not require re-downloading the encrypted original attachment.
- Keep watermark presentation separate from the encrypted original-file cache; render the overlay consistently while paging, zooming or panning.
- Adding member nickname and viewing timestamp is a **proposal only**, pending a privacy/format decision; do not treat it as approved.
- Overlay watermarks discourage casual redistribution but are not DRM: rooted devices, screenshots, recording, reverse-engineered clients and network extraction cannot be ruled out.
- Required tests: PDF every-page overlay, image overlay, default config, admin config version changes, expiry/access denial and cached-file reuse.

## Bookmark

- A member may bookmark/unbookmark an attachment; `(user_id, attachment_id)` is unique.
- A bookmark never grants read permission. Expired/removed/inaccessible attachments can remain in a user's bookmark list as unavailable, but cannot be opened.
- The list must be scoped to the authenticated user.

## Scheduled server maintenance (separate module)

- Run low-priority, bounded batches during configured off-peak hours, with resumable cursor and error logs.
- Expiry and client denial are **not** dependent on scheduler success.
- No automatic deletion of original server files until a separate retention policy is explicitly approved.

## Future Windows desktop support (deferred)

- **Current delivery priority: Android and iOS only.** No Windows implementation, packaging or Windows CI is required for this MVP.
- Future Windows 10/11 desktop client should reuse Flutter/Dart shared business logic and the same authenticated attachment API, expiration, bookmark, and mandatory watermark contracts.
- Keep platform-specific encrypted-cache key storage behind an interface (Android Keystore, iOS Keychain, future Windows DPAPI or equivalent). Do not assume a mobile-only PDF renderer or filesystem path in shared domain logic.
- Future desktop work includes mouse/keyboard/responsive layout, PDF/image viewer compatibility, watermark coverage, encrypted cache, Windows runner/toolchain, packaging, signing, and platform acceptance tests.
- A Windows-specific implementation must be explicitly approved later. Do not mark Windows as implemented or block mobile MVP on Windows tasks.

## Required before PR review/merge

Private file upload + MIME validation; authorization and atomic quota accounting; expiry and read API; bookmark API; administrator audit; policy and concurrency tests; Flutter cache and reader integration; passing CI and manual acceptance. CI green alone is not acceptance.
