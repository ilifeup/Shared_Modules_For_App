# S9 現有功能對照表與雙 AI 同步基線

> 更新：2026-10-25；狀態：第一版唯讀 GitHub 證據盤點（不是九模組完整程式審計）。  
> 主文件：[S9 決策](s9_decisions.md)。本文件供 ChatGPT、Claude 與開發人員共用。  
> 來源：`ilifeup/Shared_Modules_For_App`（Shared）與 `ilifeup/tiandi-app`（Tiandi）。  
> **禁止將「文件已決策」視為「程式已實作、測試通過、合併或部署」。**

## 1. 判讀規則

| 代碼 | 意義 |
|---|---|
| MAIN | 已在 `main` 找到對應程式／已合併 PR；不表示功能完整驗收 |
| PR | 已在未合併 PR／功能分支找到對應程式；不等於 main |
| SPEC | 已有 S9 業務決策或技術草案；未取得已實作證據 |
| CHECK | 本輪尚未充分核對；**不得直接宣稱不存在或已完成** |

任何 AI 更新狀態時必須附 repo、branch/commit SHA、檔案路徑或 PR，以及核對日期。PR 合併狀態會變動，不能沿用舊結論。

## 2. 已確認 GitHub 基線

| 項目 | Shared | Tiandi | 判讀 |
|---|---|---|---|
| 預設分支 | `main` | `main` | 各自獨立 |
| S5 M09 活動行事曆 | [PR #17](https://github.com/ilifeup/Shared_Modules_For_App/pull/17) 已合併 | [PR #14](https://github.com/ilifeup/tiandi-app/pull/14) 已合併 | MAIN |
| S6 M08 活動預約 | [PR #18](https://github.com/ilifeup/Shared_Modules_For_App/pull/18) 已合併 | [PR #15](https://github.com/ilifeup/tiandi-app/pull/15) 已合併 | MAIN |
| S7 Flutter 共用 UI | [PR #19](https://github.com/ilifeup/Shared_Modules_For_App/pull/19) 已合併 | [PR #16](https://github.com/ilifeup/tiandi-app/pull/16) 已合併 | MAIN |
| S8 M05 文章與互動 | [PR #20](https://github.com/ilifeup/Shared_Modules_For_App/pull/20) 未合併；HEAD `0e16f591ce17e2fc23ac8ff61061c012c4d15299` | [PR #17](https://github.com/ilifeup/tiandi-app/pull/17) 未合併；HEAD `5b071aefd25459d9cad1b586cbc9374cc279dafd` | PR |
| OpenAPI | `main: contracts/openapi.yaml` v0.6.0；S8 branch v0.7.0 | Tiandi 路由需依實際分支核對 | MAIN / PR |
| Auto-merge repository setting | 未啟用 | 未啟用 | 尚未符合 S9 未來自動交付目標 |
| 部署 | 未核實正式環境及部署 workflow | 未核實正式環境及部署 workflow | CHECK |

注意：S8 兩個 PR 的 `base` 都是 `main`，各變更 72 個檔案；已確認 `merged=false`，不能因檔案存在於功能分支就視為已正式交付。

## 3. S9 九模組功能對照

| 模組 | 業務決策／草案 | 已核對的現有功能證據 | 現階段分類 | S9 待核對／整合重點 |
|---|---|---|---|---|
| M03 身分驗證 | S9 決策 D01–D12 | Shared/Tiandi S8 分支 `routes/api.php` 有 `/auth/*`、`/me/*`，Sanctum 與 `member.verified`；Shared main OpenAPI 也有 auth 契約 | MAIN（既有認證基礎）＋ SPEC（S9 擴充） | 與既有 OTP、註冊、會員驗證、Token/Policy 比對；避免另建認證引擎 |
| M08 活動預約 | S9 決策 D01–D24 | Shared `main: examples/poc_backend/app/Http/Controllers/Api/BookingController.php`；兩 Repo S6 PR 已合併；既有 `/events/{id}/bookings`、`/me/bookings` | MAIN（S6 基礎）＋ SPEC（S9 擴充） | 既有預約狀態、容量、取消與新規格差異 |
| M09 活動行事曆 | S9 決策 D01–D25 | Shared `main: examples/poc_backend/app/Http/Controllers/Api/CalendarEventController.php`；兩 Repo S5 PR 已合併；既有 `/events` | MAIN（S5 基礎）＋ SPEC（S9 擴充） | 與 M08、M11 的事件 ID、時間、可見性、取消狀態一致性 |
| M11 報到 | S9 決策 D01–D30 | 尚未完成現有 repo 全樹與相關 API 掃描 | SPEC / CHECK | 與 M08 預約及 M09 活動關聯；報到權限、重複報到與狀態 |
| M14 Deep Link | S9 決策 D01–D30 | S8 有 `ArticleSharePageController.php`、文章分享路由（僅 PR）；尚未證明已有通用 M14 | SPEC / CHECK | 不重複實作 S8 分享頁；統一內外連結、安全與權限 |
| M15 Marketing | S9 決策 D01–D30；D30 修訂為未來上線後觀察 | 尚未核對到獨立 M15 引擎；既有 S8 `featured` 文章 API 只可作相關能力線索 | SPEC / CHECK | 版位、排程、CTA、M14 連結、M17 統計及既有精選文章是否重疊 |
| M16 Tag Intelligence | S9 決策 D01–D30 | 尚未完成程式掃描；不可把文章分類直接等同標籤智慧 | SPEC / CHECK | 標籤所有權、內容型別、顏色及與搜尋索引的邊界 |
| M17 URL Clicks & Analytics | S9 決策 D01–D30；D30 修訂為未來上線後觀察 | 尚未核對到獨立通用點擊分析引擎 | SPEC / CHECK | M14 點擊事件與 M15 行銷成效單一來源；保留期限與隱私 |
| M19 Search & Bookmark | S9 決策 D01–D30 | S8 有文章列表／搜尋相關介面線索（PR），未核對到跨內容通用搜尋與收藏 | SPEC / CHECK | 與既有文章查詢、M16 標籤、各模組讀取權限整合 |

**判讀：** 「SPEC / CHECK」只表示本輪尚未取得實作證據，**不是證明功能完全不存在**；「MAIN」也不代表 S9 新增需求已完成。

## 4. 已核對的 API 與程式位置

| 來源 | 分支 | 路徑 | 觀察 |
|---|---|---|---|
| Shared | main | `contracts/openapi.yaml` | OpenAPI 3.1；info.version 0.6.0；每 App 獨立 backend、DB、token；API `/api/v1` |
| Shared | `feat/s8-m05-articles` | `contracts/openapi.yaml` | info.version 0.7.0；S8 文章 API 新增契約；PR 未合併 |
| Shared | main | `examples/poc_backend/app/Http/Controllers/Api/CalendarEventController.php` | S5 M09 事件查詢 |
| Shared | main | `examples/poc_backend/app/Http/Controllers/Api/BookingController.php` | S6 M08 預約 API |
| Shared | `feat/s8-m05-articles` | `examples/poc_backend/routes/api.php` | 認證、公告、事件、預約及文章路由 |
| Tiandi | `feat/s8-m05-articles` | `backend/routes/api.php` | 與 Shared S8 分支路由結構對應，需持續檢查實作漂移 |
| Shared | main / S8 branch | `.github/workflows/s1-core.yml` | PHP lint/smoke、Composer validate、Dart format/analyze/smoke；`contents: read`；非部署 workflow |

已讀取的 Shared S8 路由包含 `/articles`、`/articles/{id}`、`/public/events`、`/events/{id}/bookings`、`/auth/login` 等；此為**路由存在**的證據，尚非所有端點的實際回應／權限測試。

## 5. 第一輪風險／差異

| 優先 | 項目 | 具體發現 | 後續動作 |
|---|---|---|---|
| P0 | S8 未合併 | 兩 Repo S8 PR 仍 `merged=false`；S9 可能依賴文章／分享／權限 | 核對 PR 差異、CI、與 S9 M14/M15/M19 的關係；**不可自行合併** |
| P0 | 自動合併／部署 | 兩 Repo `allow_auto_merge=false`；已看到 Shared 核心測試 workflow，尚未證實部署流程 | 盤點 Actions、分支保護、Secrets 權限、部署目標及回退 |
| P1 | API 版本落差 | Shared main OpenAPI 0.6.0，S8 PR branch 0.7.0 | 明確定義 S9 以何種 HEAD/基線整合，不直接覆寫既有契約 |
| P1 | M08/M09 重用 | 已有 S5/S6 程式，S9 決策可能涵蓋增強需求 | 逐欄對照 Migration、Controller、Service、Policy、Flutter |
| P1 | M14/M15/M17 邊界 | 三模組互相依賴，但尚未有已核對的共用事件/API 證據 | 先定介面、資料主責、失敗降級與隱私 |
| P1 | M03 權限 | 現有 Sanctum + `member.verified`，S9 必須兼容 | 逐端點核對驗證與 Policy，不只看 Flutter 顯示 |
| P2 | M16/M19 | 標籤、搜尋、收藏可能與 S8 文章分類／列表重疊 | 先辨識現有模型和查詢，再決定重用範圍 |

## 6. S9 已確認整合原則（不可自行改寫）

- INT-D01–D05：Shared 共用程式與契約；各 App 獨立 DB/Migration/會員；M14 連結、M15 行銷、M17 分析分工；後端 Policy 權威；先核對 HEAD/API 再核准 Schema。
- INT-D06 **已修訂為 B**：未來全部一次開發並自動合併部署。
- INT-D07–D12：跨模組 ID/內容型別關聯、Laravel 事件/服務介面、單一資料主責、API 相容、附屬故障降級、各 App 自行執行 Migration。
- INT-D13 **B**：立即啟動整合工作；但同一使用者指示明確「不要開發」，本輪僅文件與唯讀核對。
- INT-D14–D18：九模組同一交付但保留邊界；CI 失敗停止；部署失敗停止並保留回退；各 App Secrets 隔離；先核對真實 CI 與部署目標。
- M15-D30、M17-D30：未來上線後觀察驗證；**不代表當前允許部署**。

## 7. ChatGPT × Claude 協作規則

1. **先讀** `docs/s9_decisions.md` 與本檔，並核對當下 GitHub 的 PR、HEAD、CI；不要依 AI 對話記憶取代 GitHub 現況。
2. 對每個模組分開標記「決策／現有 main／未合併 PR／待核對／缺口」，不要將草案寫成已完成。
3. 發現契約衝突，先列出「檔案、原行為、S9 決策、建議修法、相容性風險」，由使用者裁決。
4. 本輪允許更新**文件分支**；使用者本次未授權修改功能程式、合併 PR 或部署。
5. 更新本檔時補上「證據路徑、分支與 commit、核對時間」，並更新 P0/P1 狀態；不要覆寫另一位 AI 尚未核對的事項為已通過。
6. 後續若要共享給兩個 AI，提供本檔的**固定分支 URL**與當次 commit SHA；要固定某版則用 commit URL。此文件目前在 docs branch，**尚未進入 main**。

## 8. 下一輪唯讀核對清單

- [ ] 取得兩 Repo 當前 main HEAD SHA、S8 PR HEAD、PR 檢查狀態與相依關係。
- [ ] 掃描兩 Repo 全部 workflow、觸發條件、部署 job、分支保護與可用部署目標。
- [ ] 逐模組掃描 Controller、Service、Policy、Migration、Flutter UI，填入確切檔案／行為證據。
- [ ] 比對 S9 D01–D30 與既有 API/OpenAPI；建立具體缺口與衝突表。
- [ ] 核對 M08/M09 現有 Schema 和 S9 預定 Schema，避免重複 Migration。
- [ ] 核對 S8 M05 與 M14/M15/M16/M19 的內容與權限重用邊界。
- [ ] 盤點可執行的自動測試、整合驗證、Secrets 隔離及回退能力。
- [ ] 完成技術審查後，僅對真正需要使用者決定的衝突提問。

---

本檔是**持續更新的工作對照表**，不是開發完成證明或部署授權。

## 9. 第二輪實碼核對：M08/M09 與 S8 資料模型（2026-10-25）

### 9.1 M08/M09：已有實作與 S9 差距

| 功能 | 現有實碼證據（Shared main） | S9 決策／缺口 |
|---|---|---|
| 活動基本狀態 | `CalendarEvent.php` 定義 `draft/published/cancelled`；取消活動保留紀錄；公開查詢只列 published/cancelled | S9 M09 擴充仍須逐條對照；不能重新建立另一張活動主表 |
| 活動時間 | `CalendarEvent.php` 使用 UTC 儲存 `starts_at/ends_at`，查詢以半開區間判定重疊；最大 62 天、200 筆 | 新日曆查詢與時區／跨日規格須保留相容性 |
| 活動與預約關聯 | `CalendarEvent::bookings()`；`EventBooking.calendar_event_id` 與 `user_id` | M11 報到應重用活動／預約 ID，避免重複報名資料 |
| 名額與重複報名 | `BookingService::book()` 使用 DB transaction、`lockForUpdate()`；檢查重複確認預約；程式註解指出 active 唯一索引 | 現有「每會員每活動一筆有效預約」不等於 S9 M08-D05/D06/D07 的多人／訪客／代報名 |
| 預約狀態 | `EventBooking.php` 僅 `confirmed/cancelled`；取消後 `active=null`，保留歷史 | **確定的功能差距：** S9 M08-D03/D08/D09 已決定候補及自動遞補，但現有模型無 `waitlisted` 狀態 |
| 資格與可見性 | `BookingController.php` 與 `routes/api.php` 使用 Sanctum／驗證會員與可見活動檢查 | **確定的介面差距：** S9 M08-D06 支援訪客報名，但現有報名 API 在 `auth:sanctum` + `member.verified` 群組內 |
| 取消 | `BookingService::cancel()` 在 transaction 中更新狀態，寫入 `AuditEvent` | S9 候補遞補必須掛接取消後的安全事件／服務邊界；不能破壞現有審計 |

**設計含意（待核准，尚非實作）：** S9 M08 應以既有 `calendar_events`／`event_bookings` 進行向後相容擴充，將候補、訪客與多參加者視為增量能力。實際 Migration 欄位與索引須核對既有 Migration 後才能定稿。

### 9.2 S8 M05：對 M14/M15/M16/M19 的實際重用證據

已讀取 Shared 未合併分支 `feat/s8-m05-articles` 的 `examples/poc_backend/database/migrations/2026_10_31_000100_s8_articles.php`，確認包含：

| 已存在於 S8 PR 的資料結構 | S9 相關模組 | 整合風險／建議 |
|---|---|---|
| `articles`：`slug`、`status`、`published_at`、`unpublish_at`、`visibility`、`is_featured`、`featured_order` | M14、M15、M19 | 文章路由與精選欄位已存在於 PR；M15 不應直接把 `is_featured` 當成完整行銷排程引擎；M14 應重用文章 slug／權限判斷 |
| `article_categories` + `article_article_category` | M16、M19 | 已有最多三層文章分類；**分類不等於 Tag Intelligence**，不得另建重複的文章分類系統 |
| `article_access_rules`（level/role）與文章 `visibility` | M14、M15、M19 | 搜尋結果、分享連結、行銷點擊都須再次套用內容可見性／權限，不得因入口公開而繞過 |
| `article_likes`、`article_comments`、`comment_reports`、`comment_moderation_cases` | M17、M19 | 按讚、留言、檢舉不是 URL 點擊事件；不可將既有互動數誤作 M17 統計 |
| `users.nickname`、`users.show_in_like_list` | M03、M19 | S8 新增公開暱稱／隱私設定，S9 不應重複加欄位 |
| `articles.view_count`、`like_count` | M17 | 文章檢視數與 M17 來源歸因／連結點擊不同；需要清楚事件來源與去重界線 |

**分支界線：** 上述 S8 Schema 只在未合併 PR 中確認，不得標示為 main 已上線。若 S8 先合併，必須重新比對合併後 HEAD。

### 9.3 尚未完成

- 尚未核對 M08/M09 **Migration 全欄位、索引及回滾**；不能宣稱 Schema 完整相容。
- 尚未逐行審查 S8 文章 Controller、Policy、Flutter 路由、實際權限測試。
- 尚未完成全部 GitHub Actions workflow、分支保護、Secrets／部署目標盤點；目前不能證明自動合併部署已就緒。
- 尚未執行測試或修改功能程式。

**第二輪結論：** 目前至少有兩項可定位的 S9 功能差距：M08 候補／自動遞補與 M08 訪客報名；另有一項高風險重疊：S8 文章精選／分類／分享／計數與 M14/M15/M16/M17/M19 的界線。這些屬技術整合工作，**不需重新詢問已決定的業務規則**。

## 10. 第三輪：M08／M09／M11 場次與參加者整合審查（2026-10-25）

### 10.1 現有程式證據（Shared `main`）

- `examples/poc_backend/app/Models/CalendarEvent.php`：`draft/published/cancelled`；`starts_at/ends_at`；`booking_capacity`、`booking_opens_at`、`booking_closes_at`；`bookings()` 連至 `EventBooking`；尚未在本檔確認重複活動場次模型。
- `examples/poc_backend/app/Models/EventBooking.php`：`calendar_event_id`、`user_id`；狀態僅 `confirmed/cancelled`，無每位實際參加者的獨立關聯欄位。
- `examples/poc_backend/app/Services/BookingService.php`：預約使用 DB transaction + event `lockForUpdate`；同會員重複有效預約拒絕；取消保留歷史及 `AuditEvent`。候補 FIFO／多人整組遞補未在此服務中實作。
- `examples/poc_backend/app/Http/Controllers/Api/BookingController.php`：現有 `store` 直接以 `$request->user()` 預約；`mine` 只列會員本人；`cancel` 只允許取消本人預約。
- `examples/poc_backend/app/Http/Controllers/Api/CalendarEventController.php`：支援 `/events?from=&to=`、`/public/events`；查詢半開區間、62 天上限及 200 筆上限；沒有在已讀控制器看到重複活動單次例外或 Google 同步 API。

### 10.2 已確認的 S9 規格對應

| 跨模組主題 | 已確認決策 | 既有實作差距／必要設計問題 | 判讀 |
|---|---|---|---|
| 重複活動場次 | M09-D03/D09/D19：每週、每月；單次例外；修改單次／未來／全部 | 現有 `CalendarEvent` 以單一開始／結束時間為主；需要「系列、實際場次、例外」的穩定識別，避免用日期字串當唯一識別 | **缺口，待設計** |
| 多人代報名 | M08-D02/D05/D07；M11-D03 | `EventBooking` 只有 `user_id`，缺每位實際參加者的獨立簽到身分 | **缺口，待設計** |
| 訪客報名／簽到 | M08-D06/D18/D24；M11-D02/D11 | 現有 `BookingController::store` 依賴登入會員；需新增訪客聯絡驗證與註冊後安全綁定，不得直接放寬現有會員 API | **缺口，待設計** |
| 候補 FIFO | M08-D03/D08/D09/D16/D17/D22；M11-D30 | 現有只有 confirmed/cancelled；須以報名群組大小、嚴格 FIFO、暫時故障不得跳號及轉正後才可簽到設計 | **缺口，待設計** |
| 簽到唯一性 | M11-D05/D14/D27 | 應以「實際場次 + 實際參加者」為邏輯唯一範圍；更正／撤銷需稽核；現有簽到 Schema 尚未核對 | **設計推論，非既有程式** |
| Google 日曆同步 | M09-D04/D06/D07/D12/D16/D17/D24/D25 | 同一報名會員、同一實際場次只同步一筆；須保存 Google 對應事件 ID 與同步狀態，並處理取消／例外 | **缺口，待設計** |
| 會員資格與隱私 | M09-D05/D08/D14/D15/D22；M08-D19；M11-D29 | VIP 私人行程與一般活動權限分離；資格失效不得自動取消既有報名；出席明細僅授權者可讀 | **需 Policy 核對** |

### 10.3 建議的最小資料責任邊界（提案，非已核准 Schema）

- **M09** 主責活動系列、場次、時間、例外、Google 日曆同步對應；不可讓 M08/M11 各自複製一份場次主資料。
- **M08** 主責報名單、實際參加者、候補佇列、資格／名額、報名狀態；重用現有 `BookingService` 的鎖定與稽核原則。
- **M11** 主責每位參加者的簽到／簽退／更正與稽核，透過 M08 查詢「是否已正式報名」、透過 M09 查詢「實際場次」；不另建活動或報名主資料。
- Google 同步需維持 M09-D25：同會員同場次只建立一筆 Google 事件，即使有多人參加者。
- 以上是實作切分建議，**不得直接當成使用者已核准的具體表名、外鍵、API 路徑**。

### 10.4 需注意的技術風險

1. **跨 App 外鍵限制（INT-D02/D07）**：App 內部現有外鍵可保留；不可把不同 App 的資料庫用外鍵直接相連。
2. **相容性（INT-D10）**：既有 `/api/v1/events`、`/events/{id}/bookings` 不能因引入場次而直接破壞現有回應。
3. **併發一致性**：取消、候補遞補、管理員補報名及簽到資格檢查必須共用權威報名狀態，避免超額或候補未轉正就簽到。
4. **權限與資料保護**：訪客聯絡資訊不可用可猜測的報名 ID 查閱；QR/GPS 簽到不得以 Flutter UI 隱藏代替後端驗證。
5. **同步非核心失敗降級（INT-D11）**：Google 同步失敗不應讓已成功的報名交易回滾；需可追蹤重試，但不引入大型訊息平台。
6. **Migration 仍未核實**：尚未定位既有 S5/S6 Migration 正確檔名，不得假稱已完成索引／回滾審查。

### 10.5 後續唯讀核對

- [ ] 透過 GitHub 樹／PR 檔案清單定位 S5/S6 Migration 正確路徑，核對表與索引。
- [ ] 對照 Tiandi `main` 的同名 Model、Service、Controller，確認是否有程式漂移。
- [ ] 查驗 S8 未合併 PR 的權限與文章分享路由，對照 M14/M15/M19。
- [ ] 盤點兩 Repo 全部 CI／部署 workflow 及當前 PR checks。
- [ ] 彙整具體 Schema／API 增量提案，僅將真正的規格衝突交由使用者裁決。

本輪僅唯讀審查與文件更新，未修改程式、合併或部署。

## 11. 第四輪：S5/S6 Migration 與現有 CI 核對（2026-10-25）

### 11.1 Migration：已定位並讀取兩 Repo 的對應檔案

| 功能 | Shared `main` | Tiandi `main` | 已核對 |
|---|---|---|---|
| S5 M09 活動 | `examples/poc_backend/database/migrations/2026_10_29_000100_s5_calendar_events.php` | `backend/database/migrations/2026_10_29_000100_s5_calendar_events.php` | `calendar_events` 欄位／索引／權限 |
| S6 M08 報名 | `examples/poc_backend/database/migrations/2026_10_30_000100_s6_event_bookings.php` | `backend/database/migrations/2026_10_30_000100_s6_event_bookings.php` | `calendar_events` 擴充、`event_bookings` 欄位／索引／down() |

S5 的 `calendar_events` 有 `id`、`title`、`description`、`location`、`starts_at`、`ends_at`、`status`、`audience`、`cancelled_at`、`cancel_reason`、timestamps，索引 `(status,starts_at)` 與 `(status,ends_at)`。S5 同時建立 `events.manage` 權限並賦予 admin。

S6 在 `calendar_events` 增加 `booking_enabled`、`booking_capacity`、`booking_opens_at`、`booking_closes_at`。建立 `event_bookings`，含 `calendar_event_id`、`user_id`、`status`、`active`、`booked_at`、`cancelled_at`、`cancelled_by`、timestamps；唯一索引 `(calendar_event_id,user_id,active)`；查詢索引 `(calendar_event_id,status)`、`(user_id,status)`。取消後 `active=NULL`，可保留歷史。

兩 Repo 所讀取的 S5/S6 Migration 內容相對應；**本輪未進行完整字節 hash 比較，也未實際執行 migrate／rollback**。`down()` 已在程式中確認，但回退是否能在 S9 新資料下安全執行仍待測試。

### 11.2 S9 Schema 擴充限制

1. 不可重新 `Schema::create('calendar_events')` 或 `Schema::create('event_bookings')` 取代現有表。
2. 不可直接刪除 `(calendar_event_id,user_id,active)` 唯一索引來達成多人報名；應先明確定義「報名聯絡人／報名群組／實際參加者」與既有會員預約的相容關係。
3. M09 重複場次需穩定識別，不應以顯示時間代替主鍵；M08/M11 透過正式場次關聯，不複製活動主表。
4. S9 候補狀態／訪客資料／簽到資料需新增增量 Migration，並保留歷史資料及稽核。
5. 同 App 內現有 FK 合法；INT-D07 禁止的是**跨 App 資料庫外鍵**，不是禁止 App 內所有 FK。

### 11.3 已讀取的 GitHub Actions（main）

| Repository | Workflow | 實際工作 | 是否含自動合併／部署 |
|---|---|---|---|
| Shared | `.github/workflows/s1-core.yml` | Shared PHP/Dart lint、smoke、Composer 檢查 | 否 |
| Shared | `.github/workflows/s1-poc-backend.yml` | PHP 8.4、MySQL 8.4、Migration、OpenAPI 路由對照、Pest、HTTP E2E、OTP／Booking 併發、Gate | 否，檔案明載 test only |
| Shared | `.github/workflows/s1-poc-mobile.yml` | Flutter 3.47、format/analyze/test、Shared UI、Android Debug APK、Flutter→Laravel→MySQL E2E | 否，產生測試 APK artifact |
| Tiandi | `.github/workflows/backend.yml` | PHP 8.4、MySQL 8.4、Composer audit、Migration、OpenAPI、Pest、HTTP E2E、OTP／Booking 併發、最終 Gate | 否，檔案明載 tests only |
| Tiandi | `.github/workflows/mobile.yml` | Flutter 3.47、分析、單元／Widget 測試、Shared UI、Debug APK、E2E | 否，檔案明載 tests only |

**範圍限制：** 這是已定位的五個 workflow 審查，不代表已列出兩 Repo 的所有 workflow。尚未核對完整分支保護規則、正式部署環境、Secrets 設定與 CI 最新執行結果。測試「有寫在 YAML」不等於「最新 HEAD 全數通過」。

### 11.4 對 INT-D06/D13/D15–D18 的實際影響

- 使用者決策仍為「一次開發、自動合併部署」，但現有已讀 workflow 只執行測試，且先前查得兩 Repo `allow_auto_merge=false`。
- 後續須另行設計可檢查、可停止、可回退的合併／部署流程；**不得把 GitHub Actions 產生 Debug APK 當成正式發布**。
- 自動合併前要確認 Shared／Tiandi 版本鎖定與兩邊 CI 全綠，並確認 S8 未合併 PR 與 S9 基線的順序。
- 自動部署前要先查明每個 App 的正式部署目標、Secrets／最小權限、DB Migration 策略、備份與回退。
- 本輪沒有更改 workflow、repo 設定、Secrets、程式或 PR 狀態。

### 11.5 下一輪

- [ ] 檢查 S8 PR #20/#17 最新 CI 實際執行狀態與是否存在阻擋。
- [ ] 比對 Shared 與 Tiandi 主要 Controller/Service 是否漂移。
- [ ] 逐項對照 S9 M08/M09/M11 具體 Schema 草案與上述既有 Migration，產生增量欄位／表格提案。
- [ ] 查驗兩 Repo 其餘 workflows、部署／發布環境、分支保護設定（不讀取或公開 Secrets 值）。
- [ ] 檢查 M14/M15/M17 與 S8 文章分享／統計功能的介面責任。
