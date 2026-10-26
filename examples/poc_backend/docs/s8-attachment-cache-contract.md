# S8 attachment reader and cache contract (Draft)

Status: design + initial database models only. **Not production ready.**

## Entitlement and separation

- Uploading is manually enabled/disabled by an administrator on the member record, including when payment is collected outside the App. Membership level and payment transaction records do not grant it automatically.
- The existing administrator-configured member verification policy must also be satisfied.
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

## Bookmark

- A member may bookmark/unbookmark an attachment; `(user_id, attachment_id)` is unique.
- A bookmark never grants read permission. Expired/removed/inaccessible attachments can remain in a user's bookmark list as unavailable, but cannot be opened.
- The list must be scoped to the authenticated user.

## Scheduled server maintenance (separate module)

- Run low-priority, bounded batches during configured off-peak hours, with resumable cursor and error logs.
- Expiry and client denial are **not** dependent on scheduler success.
- No automatic deletion of original server files until a separate retention policy is explicitly approved.

## Required before PR review/merge

Private file upload + MIME validation; authorization and atomic quota accounting; expiry and read API; bookmark API; administrator audit; policy and concurrency tests; Flutter cache and reader integration; passing CI and manual acceptance. CI green alone is not acceptance.
