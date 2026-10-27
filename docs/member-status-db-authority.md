# 會員資格：資料庫唯一權威（2026-10-27 最新決策）

## 已核准的唯一契約

- `guest`：未登入／沒有可供授權的 `users.id`，不新增 Guest 帳號。
- `users.verified_status = 1`：已註冊、未驗證會員（預設）。
- `users.verified_status = 2`：已註冊、已驗證會員。
- `users.id` 是唯一會員識別鍵。會員資格以 `verified_status` 判斷，**不得再使用 `membership_verified_at` 作為資格權威**。
- `email_verified_at`、`phone_verified_at` 是聯絡方式驗證成功的歷史時間，僅供完成驗證流程；不得從時間戳推算會員資格。
- 政策由 Email 改成手機或反向切換，不得自動撤銷已授予的 `verified_status = 2`。停用帳號、角色、VIP、影音個別授權仍須分別檢查。

## 現有 PR 分支依賴與安全阻礙

- #53 已包含 `2026_11_04_000100_add_verified_status_to_users.php`（`unsignedTinyInteger`，預設 1），不以聯絡時間回填舊會員；此 Migration **目前不在 #49 分支**，不可將 #49 單獨 CI 視為可驗收。
- #49 的 `User::isVerifiedMember()`、`VerificationPolicy`、Email／OTP 成功流程與影音授權已改讀／寫 `verified_status`。#49 測試資料必須使用 1／2，不得使用舊的 `membership_verified_at` 或 0。
- #49 仍有舊的 `verification_grandfathered_at` Migration／Model cast；此歷史補救欄位不應作為授權依據，是否移除須先檢查既有資料與 Migration 相依，禁止在正式環境直接刪欄。
- 正式資料升級時，舊會員資格不得憑 `email_verified_at` 或 `phone_verified_at` 自動推算。需有明確的舊資料盤點、經核准回填規則、隔離 MySQL 8.4 升級／回退與備份還原驗證。
- #49 與 #53 尚未在同一測試樹整合；不得重複建立兩套資格欄位、直接合併 main 或執行正式 Migration。

## 最小驗收 Gate

在包含 `verified_status` Migration 的**同一**隔離 MySQL 8.4 測試樹，依 DB → Model → Service/Policy → API → 影音／預約逐層測試：Guest、狀態 1、狀態 2、停用、Email／手機成功、政策切換、撤銷／重驗、影音等級到期／撤銷、單人／授權家庭預約、併發／冪等／回退。每項保存 exact HEAD 與 CI run；CI PASS 不代表正式驗收。
