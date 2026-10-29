# 三大 App 共用模組完整功能與實作規格（Shared Modules List）

> 文件版本：v2.1-progress | 2026-10-29 | 原始 M01–M20 規劃表為 2026-10-23 歷史快照，**不得視為最新實作狀態**；最新證據見下方進度紀錄。  
> Repository：`ilifeup/Shared_Modules_For_App`（**實際名稱**，不可沿用舊計畫的 `app-shared-modules`）  
> 建議位置：`docs/shared_modules_list.md`  
> 原始依據：`three_apps_shared_modules_spec_v1.md`、`three_apps_poc_acceptance_plan_v1.md`、`flutter_laravel_3app_share_modules_full_plan_v8.html`（v8 歷史完整計畫）。  
> 來源優先序：最新使用者決策 > SPEC > POC > V8；原件不修改。本文為詳細工作規格，**不得把「建議」當作已核准或已完成**。

## 00｜進度更新規則與最新查核紀錄

### 2026-10-29｜更正天帝教 Android 程式基準線（最高優先）
- 使用者明確指出舊 `ilifeup/tiandi-app` 的 Flutter／Laravel 是 Shared Modules 的更舊且錯誤較多版本；**禁止使用舊 Tiandi 程式作為正式 App 基礎**，不合併、不複製、不回填。
- Ubuntu 本機只需先 clone 最新 `Shared_Modules_For_App`；先驗證其 PHP/Flutter/MySQL PoC，再從已驗證共用模組建立新的天帝教 Android 正式 App。
- 舊 Tiandi `scripts/local_baseline_check.sh` 及雙 repo shared/ 路徑映射方案**取消**；舊 repo 保留歷史參考。
- 本次為規則更正，Ubuntu Build／MySQL／Android 實測仍 **NOT TESTED**。



### 2026-10-29｜第 2 輪正式 App 與平台確認
- 已核准：天帝教親和網 tiandi-app 首發 Android；iOS 後續。Tiandi mobile/ 與 backend/ 已存在，Shared packages/ 為依賴，examples/ 僅 PoC。
- Tiandi main 已新增 scripts/local_baseline_check.sh，更新 AGENTS.md 與現行主計畫；Ubuntu 實測 NOT TESTED。
- 第 3 輪待辦：clone 兩 repo、映射 shared/、確認 PHP 8.4、Flutter、MySQL 8.4、migration、Android Build、Secrets 掃描、ZIP 快照。

### 2026-10-29｜轉移 Ubuntu 本機快速開發基準線（規則已更新，實機尚未驗證）
- 已核准：原 iStudy Ubuntu 主機可用於**隔離的** Shared Modules 開發，不得干擾既有 iStudy 服務、資料、ZFS、PostgreSQL、Docker；獨立 MySQL 8.4 InnoDB 開發環境。
- 本輪交接只驗證會員 M01/M02/M03、預約 M08、現金收款、簽到 M11、必要公告通知 M04/M06；其他 M01–M20 **OUT OF HANDOFF SCOPE**，保留原規格與程式。
- 本機 Claude Code 單一寫入者，ChatGPT／Codex 審查；本機先 Pint／Pest／Flutter／MySQL，GitHub CI 作階段確認。45 秒 Push 間隔及每次小修更新 Issue #35 暫停；每批實質進度仍須更新本文件。
- 待實機：Ubuntu clone、Composer install、Flutter pub get/analyze、MySQL 8.4 migration、Pest／Flutter tests、ZIP 快照與 Secrets 歷史掃描；目前全部 **NOT TESTED**。
- 已知 PoC：`examples/poc_backend` 要求 PHP ^8.4、Laravel ^13.17；`examples/poc_flutter` 為 Flutter PoC，**不是正式可上架 App**。上架目標 App、首發 Android/iOS 待確認。



**使用者核准規則（2026-10-29）**：每次開發、修復、合併、測試或驗收有實質進度，必須同步更新本文件對應模組與進度紀錄，附 SHA／PR／CI 證據、PASS／FAIL／BLOCKED／NOT TESTED 及未完成項目；未測試不得標示 PASS。下方 2026-10-23 模組索引為原始規劃快照，後續應依實際驗證逐項更新，不得以歷史 PLANNED 否定已存在的程式碼。

### 2026-10-29｜main 整合及唯讀檢查
- main 合併 SHA：`025ce3e12ccb439081cc1b2c096de207735a5f02`，PR [#62](https://github.com/ilifeup/Shared_Modules_For_App/pull/62) 已合併；M08 修復分支內容已進 main。
- M08：後端 `BookingEngine.php`、`SingleMemberBookingContractTest.php`、Flutter 預約畫面存在；會員專屬 pending booking 清理呼叫已對齊；**NOT TESTED**（尚無本 SHA 乾淨 Build、完整 CI、MySQL 並發實測證據）。
- M04／M06：Dart `notification_api.dart`／`notice_api.dart` 存在；**NOT TESTED**（未完成跨層驗收）。
- M11：Flutter `checkin_screens.dart` 存在；**NOT TESTED**（未完成後端／實機驗收）。
- 現金收款：後端 `CashInvoiceService.php` 存在；管理員 API 授權修復 PR [#59](https://github.com/ilifeup/Shared_Modules_For_App/pull/59) 尚未合併；**NOT TESTED**。
- PR [#60](https://github.com/ilifeup/Shared_Modules_For_App/pull/60) 因與 #59 重複已關閉；未刪除分支／歷史。
- 其他 M01–M20：尚未逐一完成程式／API／DB／CI 查核，**UNKNOWN / NOT TESTED**，不得宣稱已完成。

## 01｜專案使命、成功定義與禁止偏離的主軸

此 Repository 是三個獨立 Flutter App + Laravel API + Filament Web 管理後台的**共用功能研發來源**，不是第四個營運 App，不是天帝教專屬程式庫，也不是共享正式資料庫。三個整合對象：① 天帝教親和網 `ilifeup/tiandi-app`（第一個整合）② 無相氣學 App ③ EachCore App。三 App 各自有帳號、資料、MySQL、後端、管理後台、憑證、網域、部署與版本。不得假設跨 App SSO、跨庫 JOIN、共同使用者主鍵或集中資料分析。

交付成功的判準不是「寫出 20 個資料夾」，而是：共用模組能在獨立環境被安裝或整合、具有可追溯版本、同一份 API 契約、Flutter／Laravel／Filament 實際互通、權限拒絕測試，以及在第二個 App 導入時證明不依賴天帝教特定資料。每個 Gate 必須提供真實執行證據。

## 02｜共用層級與責任邊界

| 層 | 內容 | 共用方式 | 不應包含 |
|---|---|---|---|
| L0 | Flutter 設計規範、元件、表單狀態、可近用性 | Dart/Flutter package | 天帝教品牌、宗教稱謂、硬編碼網址 |
| L1 | Dart API Client、DTO、驗證、錯誤、OpenAPI | 版本化 API contract + package | 私有 Token、商業決策 |
| L2 | Laravel Service、Policy、FormRequest、Migration、Filament 可重用資源 | 可移植模組，成熟後 Composer package | 全域會員庫、跨 App 關聯 |
| L3 | 各 App 的分院、學員、商品、資格與付款規則 | App 端 Adapter/配置/專屬程式 | 強行納入共用 Core |

**重要技術事實**：Flutter Widget 不能直接在 PHP Filament 介面重用；兩者共享的是功能規格、資料語意、API 契約與 Laravel 業務服務。Filament 走同一 Service／Policy，不能另建一套授權邏輯。

## 03｜不可違反的基礎原則（治理、安全、開發）

1. **最小可行產品**：一次只做一條真實可驗證流程；不得先搭大型插件市場、微服務、通用規則引擎或 20 個空殼套件。
2. **程式共用、資料隔離**：共用 migrations 模板但三 App 各自部署並管理資料；禁止中央會員庫。
3. **身份、待遇、權限、資格分離**：`users`、`member_profiles`、`membership_levels`、Spatie roles/permissions、`verification_requests`、`verified_badges` 分別建模；VIP、職稱、專業徽章不自動授予管理權。
4. **服務端是權威**：所有資料讀寫、受眾、角色、審核、併發由 Laravel Service/Policy/DB constraint 執行；Flutter 隱藏按鈕不構成授權。
5. **API 契約**：`/api/v1`；成功 `{data,meta?}`；失敗 `{error:{code,message,fields?,request_id}}`；必要時 `X-Request-Id`；ISO-8601 時間、UTC 持久化；OpenAPI 版本與 DTO 同步。
6. **登入與 Token**：Sanctum；Flutter 只保存適當的登入憑證；登出、撤銷、逾期與重新登入必須測試。不得在日誌記錄密碼或 Token。
7. **資料隱私**：個人宗教、健康、聯絡、認證證據採最小化與權限隔離；不得在一般 Analytics 中寫入敏感資料。
8. **資料生命週期**：必要唯一索引、外鍵、交易、冪等、撤銷、保留與刪除規則；不為方便直接硬刪稽核紀錄。
9. **狀態一致**：Flutter loading／empty／error／offline／403／逾時／重試／提交中；Filament 顯示相同業務狀態。
10. **預約不等於出席**：Booking → Calendar projection → Check-in → Attendance → History；Survey 選填不阻斷已發生出席。
11. **EachCore 交易權威**：商品、庫存、訂單、付款、退款由 WooCommerce 管理；Laravel 不複製可修改的平行訂單。
12. **團體 App 金流邊界**：天帝教、無相不實作 App 內贊助付款／付款導流；未確認的「認捐」功能 BLOCKED。
13. **AI 共用治理**：根目錄 `AGENTS.md` 為所有 AI 的共通規範；`CLAUDE.md` 只作 Claude 入口；人類核准重大架構、付款、正式部署與敏感 Schema 變更。
14. **PR 與驗收**：每次功能改動以獨立分支／PR、列出風險、測試命令與結果；CI PASS 不等於 Android 實機 PASS。
15. **Repository 分離**：Shared Modules 與 `tiandi-app` 各有 CI；不得將完整 tiandi Flutter/Laravel 專案整包搬入共用 Repo。
16. **版本管理**：整合依明確 Tag 或 Commit SHA，紀錄相容性、遷移方式與回退方案；不追蹤未經測試的浮動 main。
17. **GitHub Free Private 限制**：不可把未設定的分支保護或 Auto-merge 當作安全控制；人工／AI 審核必須比對測試 SHA 與 PR HEAD。
18. **測試真實性**：PASS/FAIL/BLOCKED/NOT TESTED 四態；不得偽造測試、截圖、部署或裝置測試。
19. **部署保護**：未核准不得付費開通 Laravel Cloud、DigitalOcean 或其他服務；不得操作 iStudy Ubuntu/ZFS/PostgreSQL 正式環境。
20. **需求追溯**：任何刪改 M01–M20 必須保留原始編號與來源，寫 ADR 說明；新模組用 M21 起，不擅自覆蓋原件。

## 04｜模組索引、階段、依賴與狀態

| 模組 | 名稱 | 建議優先級 | S1 | 關鍵依賴 | 當前狀態 |
|---|---|---|---|---|---|
| M01 | Member Profile | P0 | 核心 | Auth | PLANNED／NOT TESTED |
| M02 | Membership Level & Permissions | P0 | 核心 | Auth/M01 | PLANNED／NOT TESTED |
| M03 | Identity Verification | 待決 | 不實作 | M01/M02/M07 | BLOCKED：待業務規則 |
| M04 | Notification | P1 | 不實作 | M01/M02 | PLANNED／NOT TESTED |
| M05 | Featured Articles | P1 | 不實作 | M01/M02/M20 | PLANNED／NOT TESTED |
| M06 | Notice & News | P0 | 核心 | M02/M07 | PLANNED／NOT TESTED |
| M07 | Log Management | P0 | 核心 | Auth | PLANNED／NOT TESTED |
| M08 | Booking | 待決 | 不實作 | M01/M02/M07 | BLOCKED：待業務規則 |
| M09 | Calendar | P1 | 不實作 | M08 | PLANNED／NOT TESTED |
| M10 | Survey | P2 | 不實作 | M01/M08/M11 | PLANNED／NOT TESTED |
| M11 | Check-in | 待決 | 不實作 | M01/M08/M09/M07 | BLOCKED：待業務規則 |
| M12 | Personal Payment | EachCore 專用 | 不實作 | WooCommerce | PLANNED／NOT TESTED |
| M13 | Marketing-Referral | P1 | 不實作 | M01/M14/M17 | PLANNED／NOT TESTED |
| M14 | Deep Link | P1 | 不實作 | 路由/域名 | PLANNED／NOT TESTED |
| M15 | Marketing | P2 | 不實作 | M06/M17 | PLANNED／NOT TESTED |
| M16 | Tag Intelligence | P2 | 不實作 | M05/M06 | PLANNED／NOT TESTED |
| M17 | URL Clicks & Analytics | P2 | 不實作 | M07/隱私 | PLANNED／NOT TESTED |
| M18 | Context Score | 後期 | 不實作 | M16/M17 | PLANNED／NOT TESTED |
| M19 | Search & Bookmark | P1/P2 | 不實作 | M05/M06 | PLANNED／NOT TESTED |
| M20 | Files & Media / Rclone/Syncthing | P1/後期 | 不實作 | Storage/M02 | PLANNED／NOT TESTED |

Auth 為跨模組基礎能力，不更改 M01～M20 的編號。表中優先級是本輪**建議實作排序**，不代表原始 SPEC 原有時程。狀態定義：PLANNED（規劃）、BLOCKED（待決）、IN PROGRESS、TESTING、PASS（有證據）、RELEASED（可供 App 導入）；初始全部尚未完成。

## 05｜二十個模組逐項詳細規格

以下逐模組保留 SPEC 的功能、畫面、管理、Schema、權限、API、三 App 差異及驗收內容。原始內容標註為「SPEC 需求基線」，不等於實作承諾；每個模組另加上「共用實作切割與測試要求」。

### M01｜Member Profile（優先詳細規格）

**規劃狀態**：PLANNED（未實作、未驗收）；**優先級**：P0；**依賴**：Auth。

**共用實作切割（新增規劃）**：L0 定義 Flutter 頁面/狀態；L1 以 OpenAPI 契約、Dart Client/DTO 對接；L2 在 Laravel Service／Policy／Migration/Filament Resource 實作可移植邏輯；L3 的宗教、學員、商務規則留在各 App。不得把 Filament Resource 當成 API，也不得讓手機直連 MySQL。

**本輪補充的驗收／限制**：S1 只實作最小可編輯 Profile（如顯示名稱與可選聯絡資料）；其他敏感欄位、可見性與各 App 專屬欄位先記錄為待決。至少測試本人讀寫、跨帳號 403、無效欄位 422、Token 過期 401、Filament 修改後 Flutter 讀回。

**SPEC 需求基線（詳細內容，保留原始語意）**：

### 業務功能與狀態

完整個人資料分群；資料完整度、隱私、可編輯範圍、不同 App 專屬欄位。建議採用「草稿／有效／停用／撤銷」等明確狀態，任何狀態轉換必須在 Laravel 服務層檢查，不能只靠 Flutter 隱藏按鈕。新增與修改需記錄操作者、時間、原因，敏感欄位不得進入一般分析事件。

### Flutter 使用者畫面

個人首頁／基本資料／聯絡方式／地址／偏好／隱私／資料完整度。每頁必備載入、空資料、離線、權限不足、逾時、重試及提交中防連點狀態。表單顯示必填／選填與資料用途；不應一次要求所有個人資料。

### Filament 管理功能

Profiles Resource；欄位可見性、批次匯入審核、變更記錄。所有 Resource 的 List/Detail/Create/Edit/Action 皆需對應 Policy；大量異動提供預覽與操作日誌，審核操作禁止未授權者批次核准。

### 資料表與重要欄位（建議）

`users(id,email,phone,status); member_profiles(user_id,display_name,legal_name,birth_date,avatar_id,locale,timezone); member_contacts(user_id,type,value,verified_at); member_preferences(user_id,key,value); member_addresses(user_id,type,...); profile_field_definitions(app,code,type,sensitivity); profile_field_values(user_id,field_id,value_ciphertext)`。

建議通用欄位：`id`（ULID 或 bigint 擇一）、`created_at`、`updated_at`、必要的 `deleted_at`、`created_by`、`status`、`version`。不可對具有法規/稽核意義的紀錄直接硬刪；需依資料保留政策設定。

### 權限與安全

本人可編輯授權欄位；敏感資料按職責欄位級授權；客服不可任意讀取宗教/健康敏感資訊。服務端使用 Policy、FormRequest、交易與唯一索引；資料表外鍵約束不可由 UI 取代。需定義使用者停權、刪除帳號、隱私要求後的處理。

### API 草案

`GET/PATCH /api/v1/me/profile; GET /profile/schema; POST /me/avatar`。統一採 `/api/v1`、JSON、ISO-8601 時間、分頁 cursor/limit、錯誤格式含 error.code、error.message、error.fields。寫入端支援必要冪等鍵與速率限制，OpenAPI 是 Flutter Client 的契約來源。

### 模組關聯與三 App 差異

關聯：M02/M03/M04/M08/M13。

宗教：分院關聯與教友資料需分表；無相：學員經歷/專長與服務偏好；電商：收件地址與交易帳戶映射，不把 Woo 收件地址混入宗教 Profile。三個 App 共享介面/規則模板，但各自 Migration、資料與權限獨立，不能以跨 App 外鍵互相查詢會員。

### MVP 與後期驗收

能補齊必要資料且無權者無法查閱；後期動態欄位、歷史版次、批次匯入。驗收至少涵蓋正常流程、未授權、資料不存在、重複提交、併發、取消/撤銷、手機離線與資料回復；PASS 必須附 API 回應、畫面與資料庫證據。

### M02｜Membership Level & Permissions（優先詳細規格）

**規劃狀態**：PLANNED（未實作、未驗收）；**優先級**：P0；**依賴**：Auth/M01。

**共用實作切割（新增規劃）**：L0 定義 Flutter 頁面/狀態；L1 以 OpenAPI 契約、Dart Client/DTO 對接；L2 在 Laravel Service／Policy／Migration/Filament Resource 實作可移植邏輯；L3 的宗教、學員、商務規則留在各 App。不得把 Filament Resource 當成 API，也不得讓手機直連 MySQL。

**本輪補充的驗收／限制**：S1 只驗證管理員／一般使用者的必要 Policy；VIP 與多維資格先做規格，不實作支付或認證流程。驗證 VIP 不自動授管理權、直接呼叫 API 無權仍 403、Filament 操作與 API 使用同一 Policy。

**SPEC 需求基線（詳細內容，保留原始語意）**：

### 業務功能與狀態

角色/權限/VIP 三軸；VIP 有效期、升降級與可用待遇。建議採用「草稿／有效／停用／撤銷」等明確狀態，任何狀態轉換必須在 Laravel 服務層檢查，不能只靠 Flutter 隱藏按鈕。新增與修改需記錄操作者、時間、原因，敏感欄位不得進入一般分析事件。

### Flutter 使用者畫面

會員卡、目前等級、待遇說明；管理員功能按權限顯示。每頁必備載入、空資料、離線、權限不足、逾時、重試及提交中防連點狀態。表單顯示必填／選填與資料用途；不應一次要求所有個人資料。

### Filament 管理功能

Role/Permission/VIP Level Resource、會員等級變更稽核。所有 Resource 的 List/Detail/Create/Edit/Action 皆需對應 Policy；大量異動提供預覽與操作日誌，審核操作禁止未授權者批次核准。

### 資料表與重要欄位（建議）

`roles(id,slug); permissions(id,slug); model_has_roles; membership_levels(id,code,rank); member_level_assignments(user_id,level_id,starts_at,ends_at,reason)`。

建議通用欄位：`id`（ULID 或 bigint 擇一）、`created_at`、`updated_at`、必要的 `deleted_at`、`created_by`、`status`、`version`。不可對具有法規/稽核意義的紀錄直接硬刪；需依資料保留政策設定。

### 權限與安全

role 決定管理權；level 決定會員待遇；verification 不授權管理權；所有 API 用 Policy 判斷。服務端使用 Policy、FormRequest、交易與唯一索引；資料表外鍵約束不可由 UI 取代。需定義使用者停權、刪除帳號、隱私要求後的處理。

### API 草案

`GET /me/membership; GET /membership-levels; admin POST /members/{id}/levels`。統一採 `/api/v1`、JSON、ISO-8601 時間、分頁 cursor/limit、錯誤格式含 error.code、error.message、error.fields。寫入端支援必要冪等鍵與速率限制，OpenAPI 是 Flutter Client 的契約來源。

### 模組關聯與三 App 差異

關聯：M01/M03/M08/M15。

宗教：教友資格不是 VIP；無相：助教講師稱謂不等於後台角色；EachCore：VIP 可能依消費政策但不得直接賦管理權。三個 App 共享介面/規則模板，但各自 Migration、資料與權限獨立，不能以跨 App 外鍵互相查詢會員。

### MVP 與後期驗收

會員等級到期正確失效、角色提升必須授權；後期 VIP 自動升降級與權益規則。驗收至少涵蓋正常流程、未授權、資料不存在、重複提交、併發、取消/撤銷、手機離線與資料回復；PASS 必須附 API 回應、畫面與資料庫證據。

### M03｜Identity Verification（優先詳細規格）

**規劃狀態**：BLOCKED（業務規則待核准）；**優先級**：待決；**依賴**：M01/M02/M07。

**共用實作切割（新增規劃）**：L0 定義 Flutter 頁面/狀態；L1 以 OpenAPI 契約、Dart Client/DTO 對接；L2 在 Laravel Service／Policy／Migration/Filament Resource 實作可移植邏輯；L3 的宗教、學員、商務規則留在各 App。不得把 Filament Resource 當成 API，也不得讓手機直連 MySQL。

**本輪補充的驗收／限制**：身份驗證類型、證據與審核角色待團體決策；未核准前不得建立正式核實流程，特別避免把認證等同管理權。

**SPEC 需求基線（詳細內容，保留原始語意）**：

### 業務功能與狀態

基本帳號核實；後期多維證據、多審核者、多階段、有效期、撤銷、專業徽章。建議採用「草稿／有效／停用／撤銷」等明確狀態，任何狀態轉換必須在 Laravel 服務層檢查，不能只靠 Flutter 隱藏按鈕。新增與修改需記錄操作者、時間、原因，敏感欄位不得進入一般分析事件。

### Flutter 使用者畫面

認證狀態／申請／補件／進度；不公開證據。每頁必備載入、空資料、離線、權限不足、逾時、重試及提交中防連點狀態。表單顯示必填／選填與資料用途；不應一次要求所有個人資料。

### Filament 管理功能

Verification Types、審核佇列、審核分派、理由、稽核與撤銷。所有 Resource 的 List/Detail/Create/Edit/Action 皆需對應 Policy；大量異動提供預覽與操作日誌，審核操作禁止未授權者批次核准。

### 資料表與重要欄位（建議）

`verification_types(id,code,requirements_json); verification_requests(id,user_id,type_id,status); verification_evidence(id,request_id,file_id); verification_reviews(id,request_id,reviewer_id,decision,comment); verified_badges(id,user_id,type_id,valid_until)`。

建議通用欄位：`id`（ULID 或 bigint 擇一）、`created_at`、`updated_at`、必要的 `deleted_at`、`created_by`、`status`、`version`。不可對具有法規/稽核意義的紀錄直接硬刪；需依資料保留政策設定。

### 權限與安全

申請人不得自審；審核者只看分派證據；多人審核規則在後端原子判定；撤銷立即失去徽章。服務端使用 Policy、FormRequest、交易與唯一索引；資料表外鍵約束不可由 UI 取代。需定義使用者停權、刪除帳號、隱私要求後的處理。

### API 草案

`POST /verifications; GET /me/verifications; POST /verifications/{id}/evidence; admin POST /reviews/{id}/decision`。統一採 `/api/v1`、JSON、ISO-8601 時間、分頁 cursor/limit、錯誤格式含 error.code、error.message、error.fields。寫入端支援必要冪等鍵與速率限制，OpenAPI 是 Flutter Client 的契約來源。

### 模組關聯與三 App 差異

關聯：M01/M02/M04/M20。

宗教：分院/教友多維協同；無相：學員、師資、專業資格多維協同；EachCore：初期 email/phone，後期專業大師/人才徽章，不能購買 VIP 取得。三個 App 共享介面/規則模板，但各自 Migration、資料與權限獨立，不能以跨 App 外鍵互相查詢會員。

### MVP 與後期驗收

MVP 基本資格核實與禁止自審；後期 N 人審核、衝突處理、到期、徽章公示與申訴。驗收至少涵蓋正常流程、未授權、資料不存在、重複提交、併發、取消/撤銷、手機離線與資料回復；PASS 必須附 API 回應、畫面與資料庫證據。

### M04｜Notification（完整模組規格）

**規劃狀態**：PLANNED（未實作、未驗收）；**優先級**：P1；**依賴**：M01/M02。

**共用實作切割（新增規劃）**：L0 定義 Flutter 頁面/狀態；L1 以 OpenAPI 契約、Dart Client/DTO 對接；L2 在 Laravel Service／Policy／Migration/Filament Resource 實作可移植邏輯；L3 的宗教、學員、商務規則留在各 App。不得把 Filament Resource 當成 API，也不得讓手機直連 MySQL。

**本輪補充的驗收／限制**：本模組在導入前必須至少通過正常讀寫、未登入、未授權、資料不存在、驗證失敗、重複請求與管理端同步案例；有個資者另測隱私與稽核。後期能力不得混入 S1。

**SPEC 需求基線（詳細內容，保留原始語意）**：

**功能需求**：通知類別偏好、站內通知、推播權限、重要系統訊息與行銷訊息分開。必須區分必填與選填、公開與私人、一般與敏感資訊。所有列表支援分頁、排序、搜尋及無資料狀態；任何狀態改變需可追蹤。

**Flutter 畫面**：通知中心、通知偏好、裝置推播開關。統一 loading/error/empty/offline 樣式，必要時顯示未授權及登入逾期提示。

**Filament 管理**：類別與模板、發送排程、重試與失敗紀錄。操作按角色與 Policy 授權；建立、修改、下架、匯出均留必要日誌。

**資料欄位／關聯**：`notification_categories,notification_preferences,notifications,device_push_tokens`；統一 user_id、狀態、時間戳、外鍵與索引，敏感資料另做保護。與 Member Profile、權限、Notification、Log 的整合由 Laravel Service 層處理。

**API**：`GET/PATCH /me/notification-preferences; GET /me/notifications`；遵守 `/api/v1`、FormRequest、Policy、錯誤碼、分頁及速率限制。涉及推播與 Webhook 的操作採可重試、冪等設計。

**三 App 差異**：天帝教偏教友公告、活動與分院；無相偏課程、學員與服務；EachCore 偏品牌內容、商品與會員，WooCommerce 的交易資料不複製成第二套權威訂單。未啟用功能在 Flutter 隱藏入口，但服務端仍執行權限檢查。

**MVP／後期驗收**：MVP 可選擇類別且拒收行銷後不再推送；後期 FCM/APNs 批次推送。每個驗收項須提供 Flutter 畫面、API 回應、Filament 管理結果、權限拒絕案例與資料庫查核證據。

### M05｜Featured Articles（完整模組規格）

**規劃狀態**：PLANNED（未實作、未驗收）；**優先級**：P1；**依賴**：M01/M02/M20。

**共用實作切割（新增規劃）**：L0 定義 Flutter 頁面/狀態；L1 以 OpenAPI 契約、Dart Client/DTO 對接；L2 在 Laravel Service／Policy／Migration/Filament Resource 實作可移植邏輯；L3 的宗教、學員、商務規則留在各 App。不得把 Filament Resource 當成 API，也不得讓手機直連 MySQL。

**本輪補充的驗收／限制**：本模組在導入前必須至少通過正常讀寫、未登入、未授權、資料不存在、驗證失敗、重複請求與管理端同步案例；有個資者另測隱私與稽核。後期能力不得混入 S1。

**SPEC 需求基線（詳細內容，保留原始語意）**：

**功能需求**：精選文章、分享、按讚、可見按讚者名單、簡易留言板與檢舉。必須區分必填與選填、公開與私人、一般與敏感資訊。所有列表支援分頁、排序、搜尋及無資料狀態；任何狀態改變需可追蹤。

**Flutter 畫面**：文章列表/詳情、按讚名單、留言、分享。統一 loading/error/empty/offline 樣式，必要時顯示未授權及登入逾期提示。

**Filament 管理**：文章編輯、精選排序、留言審核、隱藏/下架。操作按角色與 Policy 授權；建立、修改、下架、匯出均留必要日誌。

**資料欄位／關聯**：`articles,article_likes,article_comments,comment_reports`；統一 user_id、狀態、時間戳、外鍵與索引，敏感資料另做保護。與 Member Profile、權限、Notification、Log 的整合由 Laravel Service 層處理。

**API**：`GET /articles; POST/DELETE /articles/{id}/likes; GET /articles/{id}/likes; POST /articles/{id}/comments`；遵守 `/api/v1`、FormRequest、Policy、錯誤碼、分頁及速率限制。涉及推播與 Webhook 的操作採可重試、冪等設計。

**三 App 差異**：天帝教偏教友公告、活動與分院；無相偏課程、學員與服務；EachCore 偏品牌內容、商品與會員，WooCommerce 的交易資料不複製成第二套權威訂單。未啟用功能在 Flutter 隱藏入口，但服務端仍執行權限檢查。

**MVP／後期驗收**：MVP 讚可取消、按讚名單遵守個資可見設定、留言可審核；後期巢狀討論。每個驗收項須提供 Flutter 畫面、API 回應、Filament 管理結果、權限拒絕案例與資料庫查核證據。

### M06｜Notice & News（完整模組規格）

**規劃狀態**：PLANNED（未實作、未驗收）；**優先級**：P0；**依賴**：M02/M07。

**共用實作切割（新增規劃）**：L0 定義 Flutter 頁面/狀態；L1 以 OpenAPI 契約、Dart Client/DTO 對接；L2 在 Laravel Service／Policy／Migration/Filament Resource 實作可移植邏輯；L3 的宗教、學員、商務規則留在各 App。不得把 Filament Resource 當成 API，也不得讓手機直連 MySQL。

**本輪補充的驗收／限制**：S1 完成草稿／發布／撤稿、有效期間、公開與會員受眾的最小實作。測試未發布不可見、過期不可見、管理端更新後手機同步、錯誤受眾 403 或隱藏、已讀冪等。

**SPEC 需求基線（詳細內容，保留原始語意）**：

**功能需求**：公告分類、受眾、置頂、開始/截止、草稿與發布。必須區分必填與選填、公開與私人、一般與敏感資訊。所有列表支援分頁、排序、搜尋及無資料狀態；任何狀態改變需可追蹤。

**Flutter 畫面**：公告清單/詳情/已讀。統一 loading/error/empty/offline 樣式，必要時顯示未授權及登入逾期提示。

**Filament 管理**：公告排程、受眾設定、版本與撤稿。操作按角色與 Policy 授權；建立、修改、下架、匯出均留必要日誌。

**資料欄位／關聯**：`notices,notice_categories,notice_audiences,notice_reads`；統一 user_id、狀態、時間戳、外鍵與索引，敏感資料另做保護。與 Member Profile、權限、Notification、Log 的整合由 Laravel Service 層處理。

**API**：`GET /notices; GET /notices/{id}; POST /notices/{id}/read`；遵守 `/api/v1`、FormRequest、Policy、錯誤碼、分頁及速率限制。涉及推播與 Webhook 的操作採可重試、冪等設計。

**三 App 差異**：天帝教偏教友公告、活動與分院；無相偏課程、學員與服務；EachCore 偏品牌內容、商品與會員，WooCommerce 的交易資料不複製成第二套權威訂單。未啟用功能在 Flutter 隱藏入口，但服務端仍執行權限檢查。

**MVP／後期驗收**：MVP 受眾與發布時間有效；後期多語及精準通知。每個驗收項須提供 Flutter 畫面、API 回應、Filament 管理結果、權限拒絕案例與資料庫查核證據。

### M07｜Log Management（完整模組規格）

**規劃狀態**：PLANNED（未實作、未驗收）；**優先級**：P0；**依賴**：Auth。

**共用實作切割（新增規劃）**：L0 定義 Flutter 頁面/狀態；L1 以 OpenAPI 契約、Dart Client/DTO 對接；L2 在 Laravel Service／Policy／Migration/Filament Resource 實作可移植邏輯；L3 的宗教、學員、商務規則留在各 App。不得把 Filament Resource 當成 API，也不得讓手機直連 MySQL。

**本輪補充的驗收／限制**：S1 最小稽核涵蓋登入失敗、權限拒絕、後台公告異動與 Profile 重要變更；敏感資料遮蔽。先不建立集中日誌平台。

**SPEC 需求基線（詳細內容，保留原始語意）**：

**功能需求**：安全登入、後台異動、認證決策、預約、簽到、API 錯誤的最小稽核。必須區分必填與選填、公開與私人、一般與敏感資訊。所有列表支援分頁、排序、搜尋及無資料狀態；任何狀態改變需可追蹤。

**Flutter 畫面**：本人帳號安全事件（可選）。統一 loading/error/empty/offline 樣式，必要時顯示未授權及登入逾期提示。

**Filament 管理**：Audit Log 檢索、保留期、權限。操作按角色與 Policy 授權；建立、修改、下架、匯出均留必要日誌。

**資料欄位／關聯**：`audit_logs,security_events,application_errors`；統一 user_id、狀態、時間戳、外鍵與索引，敏感資料另做保護。與 Member Profile、權限、Notification、Log 的整合由 Laravel Service 層處理。

**API**：`GET /me/security-events; admin GET /audit-logs`；遵守 `/api/v1`、FormRequest、Policy、錯誤碼、分頁及速率限制。涉及推播與 Webhook 的操作採可重試、冪等設計。

**三 App 差異**：天帝教偏教友公告、活動與分院；無相偏課程、學員與服務；EachCore 偏品牌內容、商品與會員，WooCommerce 的交易資料不複製成第二套權威訂單。未啟用功能在 Flutter 隱藏入口，但服務端仍執行權限檢查。

**MVP／後期驗收**：MVP 不寫入密碼/token/證據明文；後期集中日誌。每個驗收項須提供 Flutter 畫面、API 回應、Filament 管理結果、權限拒絕案例與資料庫查核證據。

### M08｜Booking（優先詳細規格）

**規劃狀態**：BLOCKED（業務規則待核准）；**優先級**：待決；**依賴**：M01/M02/M07。

**共用實作切割（新增規劃）**：L0 定義 Flutter 頁面/狀態；L1 以 OpenAPI 契約、Dart Client/DTO 對接；L2 在 Laravel Service／Policy／Migration/Filament Resource 實作可移植邏輯；L3 的宗教、學員、商務規則留在各 App。不得把 Filament Resource 當成 API，也不得讓手機直連 MySQL。

**本輪補充的驗收／限制**：課程團體名額、服務者個人時段、活動候補三種容量模型須先定義；確認取消／重報／超賣規則前不建完整 schema。

**SPEC 需求基線（詳細內容，保留原始語意）**：

### 業務功能與狀態

通用預約外殼＋不同 booking_type 策略；容量、候補、衝突、取消、服務資源、時間區。建議採用「草稿／有效／停用／撤銷」等明確狀態，任何狀態轉換必須在 Laravel 服務層檢查，不能只靠 Flutter 隱藏按鈕。新增與修改需記錄操作者、時間、原因，敏感欄位不得進入一般分析事件。

### Flutter 使用者畫面

類型選擇、時段、確認、我的預約、取消與候補。每頁必備載入、空資料、離線、權限不足、逾時、重試及提交中防連點狀態。表單顯示必填／選填與資料用途；不應一次要求所有個人資料。

### Filament 管理功能

Booking Types/Slots/Resources/Registrations、衝突檢查、手動調整。所有 Resource 的 List/Detail/Create/Edit/Action 皆需對應 Policy；大量異動提供預覽與操作日誌，審核操作禁止未授權者批次核准。

### 資料表與重要欄位（建議）

`booking_types(id,code); bookable_items(id,type_id,title,capacity); booking_slots(id,item_id,starts_at,ends_at,resource_id); bookings(id,user_id,slot_id,status,idempotency_key); waitlist_entries(id,slot_id,user_id,position)`。

建議通用欄位：`id`（ULID 或 bigint 擇一）、`created_at`、`updated_at`、必要的 `deleted_at`、`created_by`、`status`、`version`。不可對具有法規/稽核意義的紀錄直接硬刪；需依資料保留政策設定。

### 權限與安全

本人看本人；活動負責人看所管場次；並發使用 transaction+unique index；不得超賣；不把認捐意向當收款。服務端使用 Policy、FormRequest、交易與唯一索引；資料表外鍵約束不可由 UI 取代。需定義使用者停權、刪除帳號、隱私要求後的處理。

### API 草案

`GET /bookables; GET /bookables/{id}/slots; POST /bookings; POST /bookings/{id}/cancel; GET /me/bookings`。統一採 `/api/v1`、JSON、ISO-8601 時間、分頁 cursor/limit、錯誤格式含 error.code、error.message、error.fields。寫入端支援必要冪等鍵與速率限制，OpenAPI 是 Flutter Client 的契約來源。

### 模組關聯與三 App 差異

關聯：M01/M02/M03/M04/M09/M10/M11/M12。

宗教：上課/活動/服務/煩惱專線；「認捐」先定義非金錢意向且 MVP 不付款；無相：上課/推拿/活動/煩惱專線；EachCore：預購/搶購交易由 Woo 訂單主導，不能另建平行訂單。三個 App 共享介面/規則模板，但各自 Migration、資料與權限獨立，不能以跨 App 外鍵互相查詢會員。

### MVP 與後期驗收

重複提交不重複預約、同時搶最後一位僅一成功、取消釋出名額；後期候補/週期性預約/服務者排班。驗收至少涵蓋正常流程、未授權、資料不存在、重複提交、併發、取消/撤銷、手機離線與資料回復；PASS 必須附 API 回應、畫面與資料庫證據。

### M09｜Calendar（優先詳細規格）

**規劃狀態**：PLANNED（未實作、未驗收）；**優先級**：P1；**依賴**：M08。

**共用實作切割（新增規劃）**：L0 定義 Flutter 頁面/狀態；L1 以 OpenAPI 契約、Dart Client/DTO 對接；L2 在 Laravel Service／Policy／Migration/Filament Resource 實作可移植邏輯；L3 的宗教、學員、商務規則留在各 App。不得把 Filament Resource 當成 API，也不得讓手機直連 MySQL。

**本輪補充的驗收／限制**：本模組在導入前必須至少通過正常讀寫、未登入、未授權、資料不存在、驗證失敗、重複請求與管理端同步案例；有個資者另測隱私與稽核。後期能力不得混入 S1。

**SPEC 需求基線（詳細內容，保留原始語意）**：

### 業務功能與狀態

由已確認預約與活動派生個人日曆；顯示不等於預約權威資料。建議採用「草稿／有效／停用／撤銷」等明確狀態，任何狀態轉換必須在 Laravel 服務層檢查，不能只靠 Flutter 隱藏按鈕。新增與修改需記錄操作者、時間、原因，敏感欄位不得進入一般分析事件。

### Flutter 使用者畫面

月／週／清單、日曆詳情、加入外部行事曆。每頁必備載入、空資料、離線、權限不足、逾時、重試及提交中防連點狀態。表單顯示必填／選填與資料用途；不應一次要求所有個人資料。

### Filament 管理功能

行事曆設定、事件類型、時區、取消同步檢查。所有 Resource 的 List/Detail/Create/Edit/Action 皆需對應 Policy；大量異動提供預覽與操作日誌，審核操作禁止未授權者批次核准。

### 資料表與重要欄位（建議）

`calendar_event_views（建議以 SQL view/API 投影而非重複寫表）; calendar_subscriptions(user_id,...)`。

建議通用欄位：`id`（ULID 或 bigint 擇一）、`created_at`、`updated_at`、必要的 `deleted_at`、`created_by`、`status`、`version`。不可對具有法規/稽核意義的紀錄直接硬刪；需依資料保留政策設定。

### 權限與安全

個人只見有權的事件；隱私事件只顯示安全標題；時區存 UTC 顯示本地。服務端使用 Policy、FormRequest、交易與唯一索引；資料表外鍵約束不可由 UI 取代。需定義使用者停權、刪除帳號、隱私要求後的處理。

### API 草案

`GET /me/calendar?from=&to=; GET /me/calendar/{id}; GET /me/calendar.ics`。統一採 `/api/v1`、JSON、ISO-8601 時間、分頁 cursor/limit、錯誤格式含 error.code、error.message、error.fields。寫入端支援必要冪等鍵與速率限制，OpenAPI 是 Flutter Client 的契約來源。

### 模組關聯與三 App 差異

關聯：M04/M08/M11。

宗教：教友活動；無相：課程、推拿；EachCore：活動、服務、預購提醒但商品訂單仍以 Woo 為準。三個 App 共享介面/規則模板，但各自 Migration、資料與權限獨立，不能以跨 App 外鍵互相查詢會員。

### MVP 與後期驗收

報名新增/取消即更新日曆、跨時區正確；後期外部雙向同步需另行評估。驗收至少涵蓋正常流程、未授權、資料不存在、重複提交、併發、取消/撤銷、手機離線與資料回復；PASS 必須附 API 回應、畫面與資料庫證據。

### M10｜Survey（完整模組規格）

**規劃狀態**：PLANNED（未實作、未驗收）；**優先級**：P2；**依賴**：M01/M08/M11。

**共用實作切割（新增規劃）**：L0 定義 Flutter 頁面/狀態；L1 以 OpenAPI 契約、Dart Client/DTO 對接；L2 在 Laravel Service／Policy／Migration/Filament Resource 實作可移植邏輯；L3 的宗教、學員、商務規則留在各 App。不得把 Filament Resource 當成 API，也不得讓手機直連 MySQL。

**本輪補充的驗收／限制**：本模組在導入前必須至少通過正常讀寫、未登入、未授權、資料不存在、驗證失敗、重複請求與管理端同步案例；有個資者另測隱私與稽核。後期能力不得混入 S1。

**SPEC 需求基線（詳細內容，保留原始語意）**：

**功能需求**：依完成事件選擇性啟用問卷、評分與回饋。必須區分必填與選填、公開與私人、一般與敏感資訊。所有列表支援分頁、排序、搜尋及無資料狀態；任何狀態改變需可追蹤。

**Flutter 畫面**：待填問卷、跳過、填答、回顧。統一 loading/error/empty/offline 樣式，必要時顯示未授權及登入逾期提示。

**Filament 管理**：問卷題庫、啟用條件、匿名設定、匯總。操作按角色與 Policy 授權；建立、修改、下架、匯出均留必要日誌。

**資料欄位／關聯**：`surveys,survey_questions,survey_assignments,survey_responses`；統一 user_id、狀態、時間戳、外鍵與索引，敏感資料另做保護。與 Member Profile、權限、Notification、Log 的整合由 Laravel Service 層處理。

**API**：`GET /me/surveys; POST /surveys/{id}/responses`；遵守 `/api/v1`、FormRequest、Policy、錯誤碼、分頁及速率限制。涉及推播與 Webhook 的操作採可重試、冪等設計。

**三 App 差異**：天帝教偏教友公告、活動與分院；無相偏課程、學員與服務；EachCore 偏品牌內容、商品與會員，WooCommerce 的交易資料不複製成第二套權威訂單。未啟用功能在 Flutter 隱藏入口，但服務端仍執行權限檢查。

**MVP／後期驗收**：MVP 問卷可不填、不阻擋學習歷程；後期分支題與統計。每個驗收項須提供 Flutter 畫面、API 回應、Filament 管理結果、權限拒絕案例與資料庫查核證據。

### M11｜Check-in（優先詳細規格）

**規劃狀態**：BLOCKED（業務規則待核准）；**優先級**：待決；**依賴**：M01/M08/M09/M07。

**共用實作切割（新增規劃）**：L0 定義 Flutter 頁面/狀態；L1 以 OpenAPI 契約、Dart Client/DTO 對接；L2 在 Laravel Service／Policy／Migration/Filament Resource 實作可移植邏輯；L3 的宗教、學員、商務規則留在各 App。不得把 Filament Resource 當成 API，也不得讓手機直連 MySQL。

**本輪補充的驗收／限制**：簽到必須以真實活動／課程與已驗證的伺服器 Token 為依據；重複簽到、過期與補登都要稽核。

**SPEC 需求基線（詳細內容，保留原始語意）**：

### 業務功能與狀態

簽到需對應真實活動/課程；簽到紀錄與完成歷程分開；防重複與授權。建議採用「草稿／有效／停用／撤銷」等明確狀態，任何狀態轉換必須在 Laravel 服務層檢查，不能只靠 Flutter 隱藏按鈕。新增與修改需記錄操作者、時間、原因，敏感欄位不得進入一般分析事件。

### Flutter 使用者畫面

我的 QR/活動 QR、掃描、成功/失敗、出席歷程。每頁必備載入、空資料、離線、權限不足、逾時、重試及提交中防連點狀態。表單顯示必填／選填與資料用途；不應一次要求所有個人資料。

### Filament 管理功能

簽到站點、工作人員授權、補登與更正原因。所有 Resource 的 List/Detail/Create/Edit/Action 皆需對應 Policy；大量異動提供預覽與操作日誌，審核操作禁止未授權者批次核准。

### 資料表與重要欄位（建議）

`checkin_sessions(id,bookable_id,opens_at,closes_at); checkin_tokens(id,session_id,nonce_hash,expires_at); attendance_records(id,session_id,user_id,status,checked_at,method,operator_id)`。

建議通用欄位：`id`（ULID 或 bigint 擇一）、`created_at`、`updated_at`、必要的 `deleted_at`、`created_by`、`status`、`version`。不可對具有法規/稽核意義的紀錄直接硬刪；需依資料保留政策設定。

### 權限與安全

簽到 QR 必須有期限/伺服器驗證；補登需權限與稽核；不可憑靜態 QR 文字自行簽到。服務端使用 Policy、FormRequest、交易與唯一索引；資料表外鍵約束不可由 UI 取代。需定義使用者停權、刪除帳號、隱私要求後的處理。

### API 草案

`POST /checkins/verify; GET /me/attendance; admin POST /attendance/{id}/correct`。統一採 `/api/v1`、JSON、ISO-8601 時間、分頁 cursor/limit、錯誤格式含 error.code、error.message、error.fields。寫入端支援必要冪等鍵與速率限制，OpenAPI 是 Flutter Client 的契約來源。

### 模組關聯與三 App 差異

關聯：M01/M03/M08/M09/M10/M07。

宗教：活動/課程成個人參與歷程；無相：課程/活動成學習歷程；EachCore：首版不顯示，後期店家合作再啟用。三個 App 共享介面/規則模板，但各自 Migration、資料與權限獨立，不能以跨 App 外鍵互相查詢會員。

### MVP 與後期驗收

未報名者拒絕、過期 QR 拒絕、重複簽到冪等、補登有紀錄；後期離線掃描與跨店家。驗收至少涵蓋正常流程、未授權、資料不存在、重複提交、併發、取消/撤銷、手機離線與資料回復；PASS 必須附 API 回應、畫面與資料庫證據。

### M12｜Personal Payment（完整模組規格）

**規劃狀態**：PLANNED（未實作、未驗收）；**優先級**：EachCore 專用；**依賴**：WooCommerce。

**共用實作切割（新增規劃）**：L0 定義 Flutter 頁面/狀態；L1 以 OpenAPI 契約、Dart Client/DTO 對接；L2 在 Laravel Service／Policy／Migration/Filament Resource 實作可移植邏輯；L3 的宗教、學員、商務規則留在各 App。不得把 Filament Resource 當成 API，也不得讓手機直連 MySQL。

**本輪補充的驗收／限制**：僅 EachCore 使用 WooCommerce 交易；天帝教與無相不建立 donation/payment API，所有金流開發需另經核准。

**SPEC 需求基線（詳細內容，保留原始語意）**：

**功能需求**：僅保留可辨別「訂單支付」「實體服務」「非交易意向」的領域概念；兩團體贊助絕不導入。必須區分必填與選填、公開與私人、一般與敏感資訊。所有列表支援分頁、排序、搜尋及無資料狀態；任何狀態改變需可追蹤。

**Flutter 畫面**：EachCore 查看 Woo 訂單；團體 App 不設贊助付款。統一 loading/error/empty/offline 樣式，必要時顯示未授權及登入逾期提示。

**Filament 管理**：EachCore 商品訂單在 Woo 後台；Filament 僅讀取必要狀態。操作按角色與 Policy 授權；建立、修改、下架、匯出均留必要日誌。

**資料欄位／關聯**：`commerce_order_mappings（必要時）, payment_status_projection（唯讀）；團體不建立 donation/payment 表`；統一 user_id、狀態、時間戳、外鍵與索引，敏感資料另做保護。與 Member Profile、權限、Notification、Log 的整合由 Laravel Service 層處理。

**API**：`GET /commerce/orders（由安全 Gateway）；不提供團體贊助付款 API`；遵守 `/api/v1`、FormRequest、Policy、錯誤碼、分頁及速率限制。涉及推播與 Webhook 的操作採可重試、冪等設計。

**三 App 差異**：天帝教偏教友公告、活動與分院；無相偏課程、學員與服務；EachCore 偏品牌內容、商品與會員，WooCommerce 的交易資料不複製成第二套權威訂單。未啟用功能在 Flutter 隱藏入口，但服務端仍執行權限檢查。

**MVP／後期驗收**：MVP EachCore 真實交易在 Woo；團體無贊助付款入口；後期逐一核對平台支付規範。每個驗收項須提供 Flutter 畫面、API 回應、Filament 管理結果、權限拒絕案例與資料庫查核證據。

### M13｜Marketing-Referral（優先詳細規格）

**規劃狀態**：PLANNED（未實作、未驗收）；**優先級**：P1；**依賴**：M01/M14/M17。

**共用實作切割（新增規劃）**：L0 定義 Flutter 頁面/狀態；L1 以 OpenAPI 契約、Dart Client/DTO 對接；L2 在 Laravel Service／Policy／Migration/Filament Resource 實作可移植邏輯；L3 的宗教、學員、商務規則留在各 App。不得把 Filament Resource 當成 API，也不得讓手機直連 MySQL。

**本輪補充的驗收／限制**：分享落地頁與註冊歸因必須分離；不保證跨安裝參數保留，提供手動推薦碼回退。

**SPEC 需求基線（詳細內容，保留原始語意）**：

### 業務功能與狀態

Refer a Friend、分享文案、推薦碼、歸因、轉換統計、防濫用。建議採用「草稿／有效／停用／撤銷」等明確狀態，任何狀態轉換必須在 Laravel 服務層檢查，不能只靠 Flutter 隱藏按鈕。新增與修改需記錄操作者、時間、原因，敏感欄位不得進入一般分析事件。

### Flutter 使用者畫面

固定 Refer a Friend 按鈕、分享預覽、推薦碼、邀請進度。每頁必備載入、空資料、離線、權限不足、逾時、重試及提交中防連點狀態。表單顯示必填／選填與資料用途；不應一次要求所有個人資料。

### Filament 管理功能

Campaigns、文案、有效期、來源、推薦關係、轉換漏斗、異常事件。所有 Resource 的 List/Detail/Create/Edit/Action 皆需對應 Policy；大量異動提供預覽與操作日誌，審核操作禁止未授權者批次核准。

### 資料表與重要欄位（建議）

`referral_campaigns(id,app,starts_at,ends_at); referral_codes(id,user_id,campaign_id,code); referral_clicks(id,code_id,occurred_at,utm_json); referral_conversions(id,code_id,invitee_user_id,converted_at)`。

建議通用欄位：`id`（ULID 或 bigint 擇一）、`created_at`、`updated_at`、必要的 `deleted_at`、`created_by`、`status`、`version`。不可對具有法規/稽核意義的紀錄直接硬刪；需依資料保留政策設定。

### 權限與安全

推薦關係後端驗證；禁止自我推薦與重複註冊刷量；原始 IP 最小化；不能默認給金錢獎勵。服務端使用 Policy、FormRequest、交易與唯一索引；資料表外鍵約束不可由 UI 取代。需定義使用者停權、刪除帳號、隱私要求後的處理。

### API 草案

`GET /referrals/share; GET /r/{code}; POST /referrals/claim; GET /me/referrals`。統一採 `/api/v1`、JSON、ISO-8601 時間、分頁 cursor/limit、錯誤格式含 error.code、error.message、error.fields。寫入端支援必要冪等鍵與速率限制，OpenAPI 是 Flutter Client 的契約來源。

### 模組關聯與三 App 差異

關聯：M01/M04/M14/M15/M17。

宗教：邀請朋友下載與參與；無相：邀請學員；EachCore：導向會員註冊或商品內容，購物獎勵另受交易規則。三個 App 共享介面/規則模板，但各自 Migration、資料與權限獨立，不能以跨 App 外鍵互相查詢會員。

### MVP 與後期驗收

已安裝 App 開指定頁；未安裝顯示 Web 雙平台下載頁；推薦碼可在註冊兌換；後期 deferred deep link 與反作弊。驗收至少涵蓋正常流程、未授權、資料不存在、重複提交、併發、取消/撤銷、手機離線與資料回復；PASS 必須附 API 回應、畫面與資料庫證據。

### M14｜Deep Link（完整模組規格）

**規劃狀態**：PLANNED（未實作、未驗收）；**優先級**：P1；**依賴**：路由/域名。

**共用實作切割（新增規劃）**：L0 定義 Flutter 頁面/狀態；L1 以 OpenAPI 契約、Dart Client/DTO 對接；L2 在 Laravel Service／Policy／Migration/Filament Resource 實作可移植邏輯；L3 的宗教、學員、商務規則留在各 App。不得把 Filament Resource 當成 API，也不得讓手機直連 MySQL。

**本輪補充的驗收／限制**：Android App Links 與 iOS Universal Links 的域名驗證各自測試；未安裝走 Web 下載頁。

**SPEC 需求基線（詳細內容，保留原始語意）**：

**功能需求**：Android App Links/iOS Universal Links；未安裝導 Web 下載選擇頁；延遲深連結另評估。必須區分必填與選填、公開與私人、一般與敏感資訊。所有列表支援分頁、排序、搜尋及無資料狀態；任何狀態改變需可追蹤。

**Flutter 畫面**：路由解析、錯誤 fallback、平台下載頁。統一 loading/error/empty/offline 樣式，必要時顯示未授權及登入逾期提示。

**Filament 管理**：可管理連結路由、活動碼、落地頁文案。操作按角色與 Policy 授權；建立、修改、下架、匯出均留必要日誌。

**資料欄位／關聯**：`deep_link_routes,landing_pages`；統一 user_id、狀態、時間戳、外鍵與索引，敏感資料另做保護。與 Member Profile、權限、Notification、Log 的整合由 Laravel Service 層處理。

**API**：`GET /links/{slug}; Web GET /invite/{code}`；遵守 `/api/v1`、FormRequest、Policy、錯誤碼、分頁及速率限制。涉及推播與 Webhook 的操作採可重試、冪等設計。

**三 App 差異**：天帝教偏教友公告、活動與分院；無相偏課程、學員與服務；EachCore 偏品牌內容、商品與會員，WooCommerce 的交易資料不複製成第二套權威訂單。未啟用功能在 Flutter 隱藏入口，但服務端仍執行權限檢查。

**MVP／後期驗收**：MVP 安裝/未安裝兩種路徑通過；不採已停用 Firebase Dynamic Links。每個驗收項須提供 Flutter 畫面、API 回應、Filament 管理結果、權限拒絕案例與資料庫查核證據。

### M15｜Marketing（完整模組規格）

**規劃狀態**：PLANNED（未實作、未驗收）；**優先級**：P2；**依賴**：M06/M17。

**共用實作切割（新增規劃）**：L0 定義 Flutter 頁面/狀態；L1 以 OpenAPI 契約、Dart Client/DTO 對接；L2 在 Laravel Service／Policy／Migration/Filament Resource 實作可移植邏輯；L3 的宗教、學員、商務規則留在各 App。不得把 Filament Resource 當成 API，也不得讓手機直連 MySQL。

**本輪補充的驗收／限制**：本模組在導入前必須至少通過正常讀寫、未登入、未授權、資料不存在、驗證失敗、重複請求與管理端同步案例；有個資者另測隱私與稽核。後期能力不得混入 S1。

**SPEC 需求基線（詳細內容，保留原始語意）**：

**功能需求**：簡單 Banner、宣傳活動、內容曝光與基本轉換統計。必須區分必填與選填、公開與私人、一般與敏感資訊。所有列表支援分頁、排序、搜尋及無資料狀態；任何狀態改變需可追蹤。

**Flutter 畫面**：活動橫幅與專區。統一 loading/error/empty/offline 樣式，必要時顯示未授權及登入逾期提示。

**Filament 管理**：Campaigns/Banners/受眾/時效/成效。操作按角色與 Policy 授權；建立、修改、下架、匯出均留必要日誌。

**資料欄位／關聯**：`marketing_campaigns,marketing_assets,marketing_impressions`；統一 user_id、狀態、時間戳、外鍵與索引，敏感資料另做保護。與 Member Profile、權限、Notification、Log 的整合由 Laravel Service 層處理。

**API**：`GET /campaigns; POST /analytics/events`；遵守 `/api/v1`、FormRequest、Policy、錯誤碼、分頁及速率限制。涉及推播與 Webhook 的操作採可重試、冪等設計。

**三 App 差異**：天帝教偏教友公告、活動與分院；無相偏課程、學員與服務；EachCore 偏品牌內容、商品與會員，WooCommerce 的交易資料不複製成第二套權威訂單。未啟用功能在 Flutter 隱藏入口，但服務端仍執行權限檢查。

**MVP／後期驗收**：MVP 只做少量人工活動；不做複雜自動化。每個驗收項須提供 Flutter 畫面、API 回應、Filament 管理結果、權限拒絕案例與資料庫查核證據。

### M16｜Tag Intelligence（完整模組規格）

**規劃狀態**：PLANNED（未實作、未驗收）；**優先級**：P2；**依賴**：M05/M06。

**共用實作切割（新增規劃）**：L0 定義 Flutter 頁面/狀態；L1 以 OpenAPI 契約、Dart Client/DTO 對接；L2 在 Laravel Service／Policy／Migration/Filament Resource 實作可移植邏輯；L3 的宗教、學員、商務規則留在各 App。不得把 Filament Resource 當成 API，也不得讓手機直連 MySQL。

**本輪補充的驗收／限制**：本模組在導入前必須至少通過正常讀寫、未登入、未授權、資料不存在、驗證失敗、重複請求與管理端同步案例；有個資者另測隱私與稽核。後期能力不得混入 S1。

**SPEC 需求基線（詳細內容，保留原始語意）**：

**功能需求**：標籤分類、同義詞、別名、標籤權限；智慧推薦延後。必須區分必填與選填、公開與私人、一般與敏感資訊。所有列表支援分頁、排序、搜尋及無資料狀態；任何狀態改變需可追蹤。

**Flutter 畫面**：標籤選擇、標籤瀏覽。統一 loading/error/empty/offline 樣式，必要時顯示未授權及登入逾期提示。

**Filament 管理**：Tag/Taxonomy/合併。操作按角色與 Policy 授權；建立、修改、下架、匯出均留必要日誌。

**資料欄位／關聯**：`tags,taggables,tag_aliases`；統一 user_id、狀態、時間戳、外鍵與索引，敏感資料另做保護。與 Member Profile、權限、Notification、Log 的整合由 Laravel Service 層處理。

**API**：`GET /tags; GET /contents?tag=`；遵守 `/api/v1`、FormRequest、Policy、錯誤碼、分頁及速率限制。涉及推播與 Webhook 的操作採可重試、冪等設計。

**三 App 差異**：天帝教偏教友公告、活動與分院；無相偏課程、學員與服務；EachCore 偏品牌內容、商品與會員，WooCommerce 的交易資料不複製成第二套權威訂單。未啟用功能在 Flutter 隱藏入口，但服務端仍執行權限檢查。

**MVP／後期驗收**：MVP 人工標籤；後期推薦與標籤品質分數。每個驗收項須提供 Flutter 畫面、API 回應、Filament 管理結果、權限拒絕案例與資料庫查核證據。

### M17｜URL Clicks & Analytics（完整模組規格）

**規劃狀態**：PLANNED（未實作、未驗收）；**優先級**：P2；**依賴**：M07/隱私。

**共用實作切割（新增規劃）**：L0 定義 Flutter 頁面/狀態；L1 以 OpenAPI 契約、Dart Client/DTO 對接；L2 在 Laravel Service／Policy／Migration/Filament Resource 實作可移植邏輯；L3 的宗教、學員、商務規則留在各 App。不得把 Filament Resource 當成 API，也不得讓手機直連 MySQL。

**本輪補充的驗收／限制**：本模組在導入前必須至少通過正常讀寫、未登入、未授權、資料不存在、驗證失敗、重複請求與管理端同步案例；有個資者另測隱私與稽核。後期能力不得混入 S1。

**SPEC 需求基線（詳細內容，保留原始語意）**：

**功能需求**：最小事件追蹤，區分點擊與實際轉換，資料保留。必須區分必填與選填、公開與私人、一般與敏感資訊。所有列表支援分頁、排序、搜尋及無資料狀態；任何狀態改變需可追蹤。

**Flutter 畫面**：不展示隱私追蹤細節。統一 loading/error/empty/offline 樣式，必要時顯示未授權及登入逾期提示。

**Filament 管理**：事件查詢、匿名彙總。操作按角色與 Policy 授權；建立、修改、下架、匯出均留必要日誌。

**資料欄位／關聯**：`analytics_events,tracked_links`；統一 user_id、狀態、時間戳、外鍵與索引，敏感資料另做保護。與 Member Profile、權限、Notification、Log 的整合由 Laravel Service 層處理。

**API**：`POST /analytics/events; GET /r/{code}`；遵守 `/api/v1`、FormRequest、Policy、錯誤碼、分頁及速率限制。涉及推播與 Webhook 的操作採可重試、冪等設計。

**三 App 差異**：天帝教偏教友公告、活動與分院；無相偏課程、學員與服務；EachCore 偏品牌內容、商品與會員，WooCommerce 的交易資料不複製成第二套權威訂單。未啟用功能在 Flutter 隱藏入口，但服務端仍執行權限檢查。

**MVP／後期驗收**：MVP 匿名/必要資料、拒絕過度追蹤；後期漏斗分析。每個驗收項須提供 Flutter 畫面、API 回應、Filament 管理結果、權限拒絕案例與資料庫查核證據。

### M18｜Context Score（完整模組規格）

**規劃狀態**：PLANNED（未實作、未驗收）；**優先級**：後期；**依賴**：M16/M17。

**共用實作切割（新增規劃）**：L0 定義 Flutter 頁面/狀態；L1 以 OpenAPI 契約、Dart Client/DTO 對接；L2 在 Laravel Service／Policy／Migration/Filament Resource 實作可移植邏輯；L3 的宗教、學員、商務規則留在各 App。不得把 Filament Resource 當成 API，也不得讓手機直連 MySQL。

**本輪補充的驗收／限制**：本模組在導入前必須至少通過正常讀寫、未登入、未授權、資料不存在、驗證失敗、重複請求與管理端同步案例；有個資者另測隱私與稽核。後期能力不得混入 S1。

**SPEC 需求基線（詳細內容，保留原始語意）**：

**功能需求**：可解釋、可版本化的內容分數，不以不透明 AI 分數直接裁決會員。必須區分必填與選填、公開與私人、一般與敏感資訊。所有列表支援分頁、排序、搜尋及無資料狀態；任何狀態改變需可追蹤。

**Flutter 畫面**：內容推薦標示（後期）。統一 loading/error/empty/offline 樣式，必要時顯示未授權及登入逾期提示。

**Filament 管理**：規則權重、試算、版本。操作按角色與 Policy 授權；建立、修改、下架、匯出均留必要日誌。

**資料欄位／關聯**：`score_rules,content_scores,score_versions`；統一 user_id、狀態、時間戳、外鍵與索引，敏感資料另做保護。與 Member Profile、權限、Notification、Log 的整合由 Laravel Service 層處理。

**API**：`GET /content/{id}/score`；遵守 `/api/v1`、FormRequest、Policy、錯誤碼、分頁及速率限制。涉及推播與 Webhook 的操作採可重試、冪等設計。

**三 App 差異**：天帝教偏教友公告、活動與分院；無相偏課程、學員與服務；EachCore 偏品牌內容、商品與會員，WooCommerce 的交易資料不複製成第二套權威訂單。未啟用功能在 Flutter 隱藏入口，但服務端仍執行權限檢查。

**MVP／後期驗收**：MVP 不強制上線；後期可回溯重算。每個驗收項須提供 Flutter 畫面、API 回應、Filament 管理結果、權限拒絕案例與資料庫查核證據。

### M19｜Search & Bookmark（完整模組規格）

**規劃狀態**：PLANNED（未實作、未驗收）；**優先級**：P1/P2；**依賴**：M05/M06。

**共用實作切割（新增規劃）**：L0 定義 Flutter 頁面/狀態；L1 以 OpenAPI 契約、Dart Client/DTO 對接；L2 在 Laravel Service／Policy／Migration/Filament Resource 實作可移植邏輯；L3 的宗教、學員、商務規則留在各 App。不得把 Filament Resource 當成 API，也不得讓手機直連 MySQL。

**本輪補充的驗收／限制**：本模組在導入前必須至少通過正常讀寫、未登入、未授權、資料不存在、驗證失敗、重複請求與管理端同步案例；有個資者另測隱私與稽核。後期能力不得混入 S1。

**SPEC 需求基線（詳細內容，保留原始語意）**：

**功能需求**：全文檢索、內容收藏、權限過濾。必須區分必填與選填、公開與私人、一般與敏感資訊。所有列表支援分頁、排序、搜尋及無資料狀態；任何狀態改變需可追蹤。

**Flutter 畫面**：搜尋結果、我的收藏。統一 loading/error/empty/offline 樣式，必要時顯示未授權及登入逾期提示。

**Filament 管理**：索引設定、失效收藏處理。操作按角色與 Policy 授權；建立、修改、下架、匯出均留必要日誌。

**資料欄位／關聯**：`bookmarks,search_index_metadata`；統一 user_id、狀態、時間戳、外鍵與索引，敏感資料另做保護。與 Member Profile、權限、Notification、Log 的整合由 Laravel Service 層處理。

**API**：`GET /search; POST/DELETE /bookmarks`；遵守 `/api/v1`、FormRequest、Policy、錯誤碼、分頁及速率限制。涉及推播與 Webhook 的操作採可重試、冪等設計。

**三 App 差異**：天帝教偏教友公告、活動與分院；無相偏課程、學員與服務；EachCore 偏品牌內容、商品與會員，WooCommerce 的交易資料不複製成第二套權威訂單。未啟用功能在 Flutter 隱藏入口，但服務端仍執行權限檢查。

**MVP／後期驗收**：MVP 資料庫搜尋+收藏；後期 Scout/外部引擎。每個驗收項須提供 Flutter 畫面、API 回應、Filament 管理結果、權限拒絕案例與資料庫查核證據。

### M20｜Files & Media / Rclone/Syncthing（完整模組規格）

**規劃狀態**：PLANNED（未實作、未驗收）；**優先級**：P1/後期；**依賴**：Storage/M02。

**共用實作切割（新增規劃）**：L0 定義 Flutter 頁面/狀態；L1 以 OpenAPI 契約、Dart Client/DTO 對接；L2 在 Laravel Service／Policy／Migration/Filament Resource 實作可移植邏輯；L3 的宗教、學員、商務規則留在各 App。不得把 Filament Resource 當成 API，也不得讓手機直連 MySQL。

**本輪補充的驗收／限制**：備份同步工具屬維運選項，不是共享會員資料同步；私有檔案不得暴露永久公開 URL。

**SPEC 需求基線（詳細內容，保留原始語意）**：

**功能需求**：Laravel Storage、檔案權限、病毒與格式檢查；備份同步是維運而非會員共享資料。必須區分必填與選填、公開與私人、一般與敏感資訊。所有列表支援分頁、排序、搜尋及無資料狀態；任何狀態改變需可追蹤。

**Flutter 畫面**：頭像/附件上傳。統一 loading/error/empty/offline 樣式，必要時顯示未授權及登入逾期提示。

**Filament 管理**：媒體庫、檔案可見性、保留政策。操作按角色與 Policy 授權；建立、修改、下架、匯出均留必要日誌。

**資料欄位／關聯**：`media_files,media_attachments`；統一 user_id、狀態、時間戳、外鍵與索引，敏感資料另做保護。與 Member Profile、權限、Notification、Log 的整合由 Laravel Service 層處理。

**API**：`POST /uploads; GET /files/{id}`；遵守 `/api/v1`、FormRequest、Policy、錯誤碼、分頁及速率限制。涉及推播與 Webhook 的操作採可重試、冪等設計。

**三 App 差異**：天帝教偏教友公告、活動與分院；無相偏課程、學員與服務；EachCore 偏品牌內容、商品與會員，WooCommerce 的交易資料不複製成第二套權威訂單。未啟用功能在 Flutter 隱藏入口，但服務端仍執行權限檢查。

**MVP／後期驗收**：MVP 私有檔不可用永久公開 URL；後期備份同步與大型媒體處理。每個驗收項須提供 Flutter 畫面、API 回應、Filament 管理結果、權限拒絕案例與資料庫查核證據。

## 06｜跨模組流程、關聯與資料權威

## 3. 跨模組整合契約

### 3.1 會員、等級、認證與專業徽章

- `users` 是登入主體；`member_profiles` 是資料；`membership_levels` 是待遇；`roles/permissions` 是操作權；`verification_requests` 是核實流程；`verified_badges` 是可公開的已核實聲明。五者不可共用單一 `status` 欄位。
- 認證成功不能自動給管理員權限；VIP 購買不能自動給專業徽章。徽章顯示需具可撤銷、有效期、發證來源與申訴流程。
- 宗教與無相的多維協同認證：MVP 先完成一個受控類型與單次審核流程，後期依實際審核章程設計多審核者 quorum、利益衝突迴避、覆核及申訴。

### 3.2 預約、日曆、簽到、歷程、問卷

1. 管理員建立活動／課程／服務與場次；每個場次有時間、名額、地點、可報名資格。
2. 使用者提交 Booking，服務端交易檢查資格、時段衝突、容量、重複預約與截止時間。
3. Booking 成功後，Calendar 以投影顯示；取消或改期即反映，不維護另一套獨立「日曆權威訂單」。
4. Check-in 依活動、會員與可驗證憑證建立 attendance；**預約成功不等於實際出席**。
5. Learning/Participation History 由完成/簽到證據形成，可標註補登與撤銷；Survey 是選填，不填不應取消既有出席。
6. 通知可按使用者偏好派送，但與安全有關的必要系統訊息需另行定義；不能把所有訊息當行銷推播。

### 3.3 Referral 與 Deep Link

`share button → /invite/{code} → Android App Links/iOS Universal Links → app target page`；未安裝時進 Web landing page 讓使用者選 Google Play／App Store。完成註冊後用有效推薦碼建立 `referral_conversions`；單一新帳號只能歸因一次。MVP 不保證跨安裝過程自動保存參數；提供註冊頁輸入推薦碼作可靠 fallback。

### 3.4 EachCore 交易邊界

- Flutter 商品顯示與購物車可走安全 Store API 或自有 Gateway，依 WooCommerce 正式 API 支援情況實測。
- WooCommerce 為商品 SKU、價格、庫存、購物車、訂單、付款、退款的權威資料；Laravel 只保存必要映射與非交易功能，不另建可修改金額的平行訂單。
- Laravel 會員與 WooCommerce customer 需明確一對一映射、錯誤復原與帳號合併規則；未完成此 PoC 前不承諾跨後端單一登入已可用。
- 電商「預購／搶購」屬商品交易；若涉及付款、限購、庫存，應由 WooCommerce 商品/訂單規則處理，不使用一般 Booking 當第二套交易引擎。

### 3.5 App 外支持資訊法律邊界

天帝教與無相若需要在 App 顯示團體發展資訊，僅使用一般 CMS 文字內容；不提供「立即贊助」、金額輸入、付款方式、QR 收款、外部付款導流、贊助交易統計或收據功能。此規格不構成法律意見，對外文案需由有權團體與法律專業者確認。一般 Booking 的「認捐」若無法確認是否涉及資金募集，MVP 不實作該類型。


## 07｜三 App 導入差異與禁止跨用

## 5. 各 App 專屬規格與驗收

### 5.1 天帝教親和網

**定位**：教友團體聯繫、公告、課程、活動、簽到、個人參與歷程；原始需求還包括分院申請、教友認證、個人 QR、經典、功課、聊天、問卷。後者按實際制度與資源分期，不視為取消。

**Profile**：基本資料、聯絡方式、隱私、所屬分院關係、教友資格（由獨立認證表核實）；分院欄位與個人宗教相關資訊不可預設公開。

**會員權限**：訪客、一般會員、活動工作者、分院管理者、系統管理員等僅為角色建議；實際角色名稱、分院管理權與可審核者名單需團體核定。VIP 不是宗教身分。

**認證**：初期採必要的簡單核實；未來多條件、多審核者，審核者必須可追蹤。認證狀態不得公開暴露個人證據。

**預約**：上課、活動、服務、煩惱專線可共用預約外殼，實際時段、名額、保密性各自配置；「認捐」類型暫不啟用，直到確認其非交易意向性質與合規邊界。

**學習／參與歷程**：活動簽到後產生可追溯 attendance；補登/撤銷需審核。問卷可跳過。公告與文章具不同發布、互動規則。

**MVP 驗收**：新教友註冊→個資編輯→查看公告→活動報名→日曆→QR 簽到→參與歷程；非授權分院管理者不得看到他院名單；無 App 內贊助金流或付款導流。

### 5.2 無相氣學

**定位**：學員、課程、活動、學習歷程、推拿服務與煩惱專線預約；管理端服務助教、講師、活動負責人及服務者。

**Profile**：學員基本資料、聯絡、學習偏好、課程關聯、服務預約所需的最小必要資訊；任何健康相關資訊應與一般會員資料隔離、限制存取與保留。

**會員等級／稱謂／角色**：VIP、學員程度、助教/講師稱謂、管理員權限為不同維度；不因稱謂自動賦管理權。若參考既有教室系統的 title_level 設計，需確認是否適用本 App，不直接照搬。

**認證**：後期可支援學員程度、師資、專業服務資格等多維協同審核；專業標記需證據、核發者與撤銷。

**預約**：上課是團體名額；推拿/煩惱專線通常是服務者時段互斥；活動有報名/候補；不能用同一個 capacity 規則覆蓋全部。取消截止、缺席、改期、服務保密另定。

**歷程**：課程報名與實際簽到分離；學習歷程可連結課程、梯次、出席、學習紀錄與可選回饋。問卷不應強制完成。

**MVP 驗收**：學員註冊→課程查看→報名→日曆→簽到→歷程；推拿預約的時段互斥測試；兩位學員同時搶最後名額僅一人成功；無 App 內贊助收款。

### 5.3 EachCore 電商

**定位**：品牌商品銷售與生活圈；八大主力商品以產品研發／銷售路線分階段上架，不能假定每項已有可販售庫存。IoT 語音助理未來含硬體連線 App，但目前僅作商品與研發規劃。

**Profile**：會員資料、地址、行銷偏好、VIP、收藏、內容互動、訂單對照；WooCommerce customer ID 與 Laravel user ID 映射，不以手機直接查詢管理 API。

**認證**：MVP 只做基本帳號驗證；未來專業大師/人才需獨立認證流程，帳號旁顯示經核實 Icon，不得因購買 VIP 或付費自動取得權威標章。

**Booking**：活動/服務可用 Laravel Booking；預購/搶購與實體商品付款由 WooCommerce 商品與訂單控制。個人日曆可顯示活動或預購提醒，但不成為訂單權威來源。

**支付**：WooCommerce 負責結帳、金流、庫存、退款、交易紀錄；Laravel/Filament 負責非交易業務。實體商品、食品、茶品、盆栽、硬體產品各需合規、物流與退換貨規則。

**MVP 驗收**：商品瀏覽→購物車→結帳→Woo 訂單→App 查詢訂單；商品價格由服務端計算，修改客戶端價格無效；Webhook 重送不重複產生業務結果；**不要求 IoT 硬體連線**。


## 08｜S0～S5 實施順序與明確交付物

| 階段 | 可交付物 | Gate | 不做 |
|---|---|---|---|
| S0 文件基準 | 原始 SPEC/POC、本文、AGENTS、CLAUDE、README、最小 CI | 原件一致性、M01–M20 完整、AI 規則一致 | 不開發 20 個空模組 |
| S1 共用垂直切片 | Auth + M01 + M02 最小權限 + M06 + M07 必要稽核；Flutter、Laravel、Filament 可運作 | 真實 API、DB、管理端、Flutter 測試 | 不導入未核准認證／預約／簽到 |
| S2 天帝教整合 | 以指定版本整合至 `tiandi-app`，Android Pixel 實機驗收 | PR、CI、APK、實機、API/DB 證據 | 不視 CI 綠燈為實機 PASS |
| S3 擴充 | 依需求優先序增加內容、通知、預約、簽到、深連結等 | 各模組獨立驗收與回歸 | 不一次實作所有模組 |
| S4 無相整合 | 不複製天帝教品牌與資料；實證第二 App 可重用 | 第二 App CI 與真實流程 | 不直接共用天帝教 DB |
| S5 EachCore 整合 | 共用非交易功能，WooCommerce 交易 Gateway | 訂單權威、價格、Webhook 重試 | 不建立 Laravel 平行訂單 |

### S1 最小真實流程（先共用、後導入）

1. 管理員透過 Filament 建立測試會員、授予最小權限、發布公告。
2. Flutter 登入 → Laravel Sanctum 驗證 → 取得 Token → `/api/v1/me/profile` 讀寫資料。
3. Flutter 取得 `/api/v1/notices` 與 `/api/v1/notices/{id}`；管理員撤稿後手機無法再讀取。
4. 直接測試無權 API、跨帳號存取、未發布公告、Token 撤銷、無效表單、離線與逾時。
5. 確認共用程式沒有天帝教名稱、分院外鍵或硬編碼 App ID，才發行首個可整合版本。

### API 契約草案（S1，實作前須核准）

| Method | Path | 用途 | 權限 |
|---|---|---|---|
| POST | `/api/v1/auth/login` | 登入並建立 Token | 公開、限速 |
| POST | `/api/v1/auth/logout` | 撤銷目前 Token | 已登入 |
| GET | `/api/v1/me/profile` | 取得本人資料 | 已登入 |
| PATCH | `/api/v1/me/profile` | 修改本人資料 | 已登入／驗證 |
| GET | `/api/v1/notices` | 取得可見公告 | 公開／會員受眾判斷 |
| GET | `/api/v1/notices/{id}` | 取得可見公告內容 | 同上 |
| POST | `/api/v1/notices/{id}/read` | 記錄已讀（可後移） | 已登入 |

> API 路徑與欄位以 SPEC/POC 契約為基準；上表是 S1 切片提案，不宣稱已實作。

## 09｜GitHub Repository 與跨 Repository 整合

**實際 Repo 名稱**：`ilifeup/Shared_Modules_For_App`，不是原先預計的 `ilifeup/app-shared-modules`。GitHub Repository 大小寫與名稱以實際建立者為準；本文件不得誤用舊名稱。

建議樹狀結構：

```text
Shared_Modules_For_App/
├── README.md
├── AGENTS.md
├── CLAUDE.md
├── docs/
│   ├── shared_modules_list.md
│   ├── architecture.md
│   ├── module_matrix.md
│   ├── decisions/
│   └── specs/
│       ├── three_apps_shared_modules_spec_v1.md
│       └── three_apps_poc_acceptance_plan_v1.md
├── contracts/               # S1 才放 OpenAPI
├── flutter/                 # S1 才建立實際 package
├── laravel/                 # S1 才建立可移植 Service/module
└── .github/workflows/      # S0 文件驗證，S1 再加程式測試
```

**Private 跨 Repo 引用風險**：`tiandi-app` 的預設 `GITHUB_TOKEN` 不保證可讀另一個 Private Repository。S1/S2 應驗證採 Git 依賴／Composer 私有套件或受控 vendor 方式的憑證與 CI；不得提交 PAT 或秘密到 Repository。整合依固定 Tag/SHA，留回退方式。每個 App 的 DB Migration 在自己的資料庫執行，不跨庫。

**Actions 分工**：S0 `validate.yml` 驗證必要文件、M01–M20、連結與原件雜湊；S1 增 `flutter.yml`（format/analyze/test）、`laravel.yml`（Composer/Pint/Pest）；S2 由 tiandi CI 執行整合與 APK Build。任何需要付費 Runner、部署憑證、正式金流，先徵得使用者同意。

## 10｜驗收證據、測試矩陣與風險

## 8｜逐項驗收制度：PASS／FAIL／BLOCKED／NOT TESTED
**狀態定義**：PASS = 有可重現證據且符合預期；FAIL = 已執行但不符合；BLOCKED = 受未解決前置條件阻擋；NOT TESTED = 尚未執行。所有測試預設 NOT TESTED。
**每筆測試紀錄模板**：`Test ID | Build/Commit | Tester | Device/OS | Env | Preconditions | Steps | Expected | Actual | Evidence | Status | Issue | Retest date`。
**證據命名**：`docs/acceptance/<app>/<test_id>/<timestamp>_{screenshot|log|video|api}.ext`；證據遮蔽姓名、電話、Token、付款資訊；不可用合成截圖冒充實測。
**放行規則**：每個 Gate 的 P0 案例全部 PASS，且沒有未解決 P0/P1 安全缺陷；P1 允許延後需明確批准並記錄。BLOCKED 不等於 PASS；不因預計功能後期開發而把 MVP 必做項標 PASS。

## 9｜共用基礎 PoC 驗收案例（P0）
### G0｜開發環境與版本鎖定
| ID | 優先 | 前置條件／操作步驟 | 預期結果（PASS 判準） | 狀態 | 證據 |
|---|---|---|---|---|---|
| `CORE-ENV-001` | P0 | 乾淨 Ubuntu 安裝 PHP/Composer/MySQL；執行版本命令 | 官方相容矩陣符合；鎖版檔提交 | NOT TESTED | 待附 |
| `CORE-ENV-002` | P0 | Flutter doctor、Android SDK、實機連線 | Flutter analyze 與 Debug APK 可執行 | NOT TESTED | 待附 |
| `CORE-ENV-003` | P0 | clone repo；複製 .env.example；依 README 安裝 | 新環境 1 次按文件成功啟動 | NOT TESTED | 待附 |
| `CORE-ENV-004` | P0 | 掃描 git history、env、log | 無真實金鑰/會員資料入版控 | NOT TESTED | 待附 |
| `CORE-ENV-005` | P1 | 檢查 iOS build host/帳號/憑證計畫 | 確認可用 macOS CI，未具備則標上架 BLOCKED | NOT TESTED | 待附 |

### G1｜Laravel、Filament、MySQL
| ID | 優先 | 前置條件／操作步驟 | 預期結果（PASS 判準） | 狀態 | 證據 |
|---|---|---|---|---|---|
| `CORE-DB-001` | P0 | 空 DB 執行 migrate/seed | 所有表與 FK 建立成功 | NOT TESTED | 待附 |
| `CORE-DB-002` | P0 | 執行測試 DB migration rollback/re-run | 可重建且無 schema 漂移 | NOT TESTED | 待附 |
| `CORE-DB-003` | P0 | 管理員登入 Filament 新增草稿公告 | 儲存成功，操作日誌存在 | NOT TESTED | 待附 |
| `CORE-DB-004` | P0 | 未授權會員直訪 Filament/管理 API | 403 或重新導向；不得洩漏資料 | NOT TESTED | 待附 |
| `CORE-DB-005` | P0 | 建立 DB 備份、刪除測試 DB、還原 | 還原後公告/會員測試資料一致 | NOT TESTED | 待附 |
| `CORE-DB-006` | P1 | 時區轉換 UTC↔Asia/Taipei | API 與 UI 時間一致，無跨日偏移 | NOT TESTED | 待附 |

### G2｜Sanctum、Flutter Auth、API
| ID | 優先 | 前置條件／操作步驟 | 預期結果（PASS 判準） | 狀態 | 證據 |
|---|---|---|---|---|---|
| `CORE-AUTH-001` | P0 | 正確測試帳密登入 | 取得 token，Flutter me/profile 成功 | NOT TESTED | 待附 |
| `CORE-AUTH-002` | P0 | 錯誤密碼、空值、惡意輸入 | 401/422；無 token；限流 | NOT TESTED | 待附 |
| `CORE-AUTH-003` | P0 | 會員登出後重用舊 token | 401，後端 token 已撤銷 | NOT TESTED | 待附 |
| `CORE-AUTH-004` | P0 | A 用戶修改 B 的 Profile ID | 403/404；B 資料未變 | NOT TESTED | 待附 |
| `CORE-AUTH-005` | P0 | 一般會員調用管理員寫入 API | 403；不可只靠 UI 隱藏 | NOT TESTED | 待附 |
| `CORE-AUTH-006` | P0 | 重開 App／網路斷線／token 失效 | 不洩漏 token；可安全重新登入 | NOT TESTED | 待附 |
| `CORE-AUTH-007` | P1 | 多裝置登入及撤銷指定 token | 符合已批准的多裝置策略 | NOT TESTED | 待附 |
| `CORE-API-001` | P0 | OpenAPI lint + Flutter 呼叫公告列表 | 欄位/狀態碼/時間/分頁一致 | NOT TESTED | 待附 |
| `CORE-API-002` | P0 | 模擬 429、500、timeout | 顯示正確提示；不無限重試 | NOT TESTED | 待附 |
| `CORE-API-003` | P0 | SQL injection/XSS 基本攻擊樣本 | 輸入被安全處理，無跨站執行 | NOT TESTED | 待附 |

### G3｜共用元件與通知/追蹤基線
| ID | 優先 | 前置條件／操作步驟 | 預期結果（PASS 判準） | 狀態 | 證據 |
|---|---|---|---|---|---|
| `CORE-UI-001` | P0 | Flutter 登入、公告列表、詳情、Profile | Android 實機完整可操作 | NOT TESTED | 待附 |
| `CORE-UI-002` | P0 | 檢查 loading/empty/offline/403/429 | 所有必要狀態清楚且可重試 | NOT TESTED | 待附 |
| `CORE-LOG-001` | P0 | 登入/公告異動產生 audit | 有 actor/time/action，無 token/敏感原文 | NOT TESTED | 待附 |
| `CORE-NOTIF-001` | P1 | 切換公告/活動通知類別偏好 | 保存後再讀一致 | NOT TESTED | 待附 |
| `CORE-REF-001` | P1 | 產生分享碼與分享 URL | 連結可辨識來源，無敏感參數 | NOT TESTED | 待附 |
| `CORE-SEC-001` | P0 | 伺服器啟用 HTTPS、CORS 限定、Rate limit | 無明文 API，無任意 Origin 放行 | NOT TESTED | 待附 |

### G4｜CI、部署、復原與 PoC 結案
| ID | 優先 | 前置條件／操作步驟 | 預期結果（PASS 判準） | 狀態 | 證據 |
|---|---|---|---|---|---|
| `CORE-CI-001` | P0 | 開 PR 觸發所有測試 | 任一 P0 測試失敗 PR 不可合併 | NOT TESTED | 待附 |
| `CORE-CI-002` | P0 | 以空白測試環境部署 tag | 自動/文件化部署成功，可回溯版本 | NOT TESTED | 待附 |
| `CORE-CI-003` | P0 | 部署後跑登入、公告、後台 smoke | 全部 PASS 且版本一致 | NOT TESTED | 待附 |
| `CORE-OPS-001` | P0 | 測試 DB 備份及還原 | 資料可讀，記錄 RTO/RPO 實測值 | NOT TESTED | 待附 |
| `CORE-OPS-002` | P0 | 模擬錯誤版本回退 | 程式回退可執行；DB 變更有相容策略 | NOT TESTED | 待附 |
| `CORE-OPS-003` | P1 | 監控 500/異常登入與備份失敗 | 管理者能收到有效告警 | NOT TESTED | 待附 |


### 逐模組驗收紀錄格式

| 欄位 | 必填內容 |
|---|---|
| Module ID | M01–M20／Auth |
| Requirement ID | SPEC 章節、API 契約與對應測試 ID |
| Test Environment | Repo/branch/commit SHA、Flutter/PHP/Laravel/MySQL 版本 |
| Evidence | CI Run URL、API request/response（遮蔽敏感資訊）、Filament 畫面、Android 實機結果 |
| Result | PASS / FAIL / BLOCKED / NOT TESTED |
| Integration | 導入 App、依賴版本、回退 Commit |
| Reviewer | ChatGPT/Claude/使用者及核准事項 |

### 安全與架構未決事項（不可假設完成）

- 天帝教分院管理範圍、教友核實流程、證據與權限；無相學員等級、助教／講師與推拿預約規則。
- M03 身分認證、M08 預約、M11 簽到業務規則；三 App Profile 的敏感欄位、公開與保留政策。
- WooCommerce 與 Laravel 會員映射、登入、結帳與正式金流；iOS 開發者與建置資源。
- 私有 Repo 的 CI 讀取權限、版本發布、依賴更新及回退；GitHub Free Private 分支保護限制。
- 未核准不得開通付費 Hosting、正式部署、遷移真實資料、變更 iStudy Ubuntu 主機。

## 11｜來源、版本與維護政策

- SPEC：`three_apps_shared_modules_spec_v1.md`（M01–M20 詳細功能與跨模組規則）。
- POC：`three_apps_poc_acceptance_plan_v1.md`（環境、測試矩陣、G0–G9 與 G-S）。
- V8：`flutter_laravel_3app_share_modules_full_plan_v8.html`（36 章背景計畫；若與後續決策矛盾，以 SPEC/POC/最新決策為準）。
- 原始文件保持不變；變更透過 ADR + 更新本文的版本與影響模組；每個新模組另有規格與測試追溯。
- 本文的詳細模組段落引用／整合原始 SPEC，並加上當前共用 Repo 的實作切割與驗收說明；不是對來源內容作獨立外部驗證。