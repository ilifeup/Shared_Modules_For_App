# 會員資格資料庫權威：現況稽核與最小修正方案

## 已查證現況

- `examples/poc_backend/database/migrations/0001_01_01_000000_create_users_table.php` 建立 `users`，含 `email_verified_at`、`phone_verified_at`、`is_active`，**沒有獨立的已驗證會員資格欄位**。
- #49 `2026_10_27_000001_add_verification_grandfathered_at_to_users.php` 另加 `verification_grandfathered_at`；此欄位目前是 Email→手機政策切換的補救標記，不是正式會員資格。
- `VerificationPolicy::missing()` 依當前 `verification_policy` 與驗證途徑 timestamp 推算完整會員權限；`EnsureMemberVerified` 再據此阻擋 API。
- #46 `AvEntitlement` 直接依賴此政策，會將「驗證方式」誤用為「會員資格」。

## 已核准的不可變業務規則

會員資格只有三種：guest／註冊未驗證會員／註冊且已驗證會員。Guest 沒有會員 `users` 紀錄；註冊後的會員資格以 DB 明確狀態為唯一權威。Email 與手機只是取得已驗證資格的操作途徑。政策變更不得自動剝奪既有已驗證會員資格。停用、會員等級與各模組授權另外檢查，不混入三種資格。

## 最小 Schema 修正提案（尚待核准，不得視為已實作）

1. 在現有 `users` 表新增單一持久化 `membership_verified_at TIMESTAMP NULL`（或經核准的同義狀態欄位）；`NULL` 表示註冊未驗證，非 `NULL` 表示註冊且已驗證。Guest 由不存在登入會員記錄代表，不新增 Guest user。
2. 驗證成功的交易在後端原子寫入 `email_verified_at` 或 `phone_verified_at` 與 `membership_verified_at`；已驗證資格不得因政策切換清除。
3. `VerificationPolicy` 僅負責尚未驗證會員的驗證途徑／提示；Middleware、影音及其他模組讀取持久化會員資格，另檢查 `is_active`、權限、有效期、撤銷。
4. 舊資料轉換需先查驗真實資料與既有 grandfather 記錄；不可僅憑當前政策推算、不可直接執行破壞性清理；建立備份、遷移及 rollback 驗證。
5. #46 整合進 #49 後，在同一 DB／測試樹驗證：舊已驗證、政策切換、新會員未驗證、驗證成功、停用、等級到期／撤銷、未登入、AV 不可越權。完成才關閉 #46，後續再處理 #41。

**狀態：SCHEMA CHANGE BLOCKED PENDING EXPLICIT APPROVAL；不新增 PR／branch，不合併 main。**
