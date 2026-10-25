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

## 12. 第五輪：S8 CI 實際結果、跨 Repo 漂移與場地決策（2026-10-25）

### 12.1 S8 PR 指定 HEAD 的實際 GitHub Actions 執行結果

| Repository / PR | 查驗的 HEAD | 成功的 PR workflow |
|---|---|---|
| Shared [#20](https://github.com/ilifeup/Shared_Modules_For_App/pull/20) | `0e16f591ce17e2fc23ac8ff61061c012c4d15299` | Validate S0 Documentation、S1 PoC Backend、S1 Shared Core Checks、S1 PoC Mobile（均 completed/success） |
| Tiandi [#17](https://github.com/ilifeup/tiandi-app/pull/17) | `5b071aefd25459d9cad1b586cbc9374cc279dafd` | backend、mobile（均 completed/success） |

此結果來自 GitHub 指定 commit 的 PR workflow runs；並非重新執行測試，也**不等於**全部分支保護規則已符合、可自動合併或已部署。Combined commit status 回傳空陣列，不能把它解讀為「沒有 Actions」；以上以 workflow runs 為準。

### 12.2 Shared / Tiandi 精確內容比對

| 比對項目 | Shared | Tiandi | 結果 |
|---|---|---|---|
| 預約服務（main） | `examples/poc_backend/app/Services/BookingService.php` | `backend/app/Services/BookingService.php` | **內容完全一致**，blob `eb98eb936d01336b74efb057acc501d36dc8c961` |
| 活動 Model（main） | `examples/poc_backend/app/Models/CalendarEvent.php` | `backend/app/Models/CalendarEvent.php` | **內容完全一致**，blob `a7f4104358ddb05350eb06b2653bef376a534225` |
| S8 API routes（未合併功能分支） | `examples/poc_backend/routes/api.php` | `backend/routes/api.php` | **內容完全一致**，blob `bf127fd245a93d593afd9fe91cb446b25ed4ab3f` |

注意：這只涵蓋以上三組檔案，不代表兩個 Repository 全部程式碼完全一致。

### 12.3 M08 Location／樓層／Studio 補充決策（已核准 A）

依 `docs/s9_decisions.md` 於 2026-10-25 新增的使用者決策：

- **每個實際活動場次僅對應一組** `location`、`floor`、`studio`；三欄全部 nullable、可獨立填寫。
- **不支援同一場次多 Studio**；名額依活動場次，不依 Studio 分配。
- M09 是活動／場次資訊主責；M08 預約、M11 簽到讀取同一份場地資訊，不各自複製場地主資料。
- 既有 S5 `calendar_events.location` 已存在；增量 Migration **不得再次新增同名欄位**。
- 場地欄位不納入現有預約唯一索引；新增 `floor`／`studio` 及場次關聯時應與重複活動 Schema 一起規劃。
- 尚未核准建立獨立場地主檔或非活動型預約；本輪不自行擴大範圍。

### 12.4 本輪結論與後續

- **已降低的風險**：S8 指定 HEAD 有成功的 GitHub Actions 記錄；三組核心檔案無內容漂移。
- **仍存在的 P0**：S8 PR 尚未合併；S9 全自動合併部署尚未證明可用；M08 多人／訪客／候補及 M09 場次仍需增量 Schema。
- **下輪**：核對 S8 文章分享／可見性與 M14/M15/M17 的介面；補充九模組的 API 差距矩陣；審查其他 workflow、分支保護與部署前置條件。
- 本輪僅唯讀核對與文件更新；**未修改程式、CI 設定、PR 合併狀態或部署**。

## 13. 第六輪：S8 文章分享／權限／精選／瀏覽與 M14/M15/M17 邊界（2026-10-25）

### 13.1 已讀取的實際程式（Shared 未合併 S8 分支）

- `examples/poc_backend/app/Http/Controllers/Web/ArticleSharePageController.php`
- `examples/poc_backend/app/Services/ArticleAccess.php`
- `examples/poc_backend/app/Http/Controllers/Api/ArticleController.php`
- `examples/poc_backend/app/Models/Article.php`
- `docs/s8_m05_articles.md`

**注意：以上都來自 `feat/s8-m05-articles`，不表示 S8 已合併至 `main`。**

### 13.2 現有功能與 S9 所有權

| S8 已存在的行為 | S9 整合方向 | 具體證據／限制 |
|---|---|---|
| 分享頁 `/articles/{slug}` | M14 通用連結需重用 S8 文章網址／權限，不複製文章公開頁 | `ArticleSharePageController`：文章必須 live；僅 public 可傳入文章內容；members/restricted 頁不顯示標題及內文 |
| `share_url` 回應 | M14 可將它視為文章目的地，但不可把分享網址視為存取授權 | `ArticleController::show` 產生 `share_base_url/articles/{slug}` |
| `/articles/featured`、`is_featured`、`featured_order` | M15 不重做文章手動精選排序；另定行銷活動／CTA 的權威資料 | `ArticleController::featured` 限 10 筆並先套 `readable()` 權限；`Article` model 有精選欄位 |
| `view_count`、`like_count`、人氣排序 | M17 連結點擊與歸因不可直接覆寫或等同文章瀏覽／按讚 | `Article::scopeOrderByPopularity` 使用 view + like×weight；S8 文件描述同會員或 IP+UA 30 分鐘去重，具體實作仍待 `ArticleInteractionService` 全面核對 |
| `ArticleAccess::scope/denial` | M14 目的地、M15 曝光、M19 搜尋需各自保留後端可見性判斷 | public／members／restricted；restricted 以會員等級 OR 角色符合任一 access rule |
| `/articles/{id}/cover` | M14／M19 不能讓封面 URL 成為權限繞過 | `ArticleController::cover` 再次呼叫 `authorizeRead`；本地檔回應 private cache |
| 文章留言、按讚、檢舉 | M17 不將互動 API 誤認為點擊分析 | `ArticleController` 有 like/unlike、comments、report；非通用點擊事件 |

### 13.3 API／安全性具體風險

1. **受限文章 Deep Link**：登入前可見「登入提示」，但不可顯示受限文章標題、摘要、封面或內文；登入後仍須重新查核 `ArticleAccess`。
2. **M15 行銷曝光**：推廣位／CTA 若引用 restricted 文章，須先過內容權限；不得直接把 `is_featured` 當作可向所有使用者顯示。
3. **M17 事件語意**：`article.view`、`article.like`、`link.click` 是不同事件；M17 若記錄來源歸因應避免以文章計數欄位替代。
4. **M14 與 M17 分工**：M14 解決連結／導向；M17 解決點擊事件／分析；M15 消費分析而不重複記錄點擊。此為既有 INT-D03 分工的技術落實方向，尚未定稿具體 API。
5. **S8 上線依賴**：S8 文件明確要求 Queue worker、Mail、AI moderation、封面持久儲存；其 AI/Email 自動化測試使用 fake，**尚未以真實 AI 與郵件服務驗收**。即使 S8 PR CI 綠燈也不能據此宣稱可正式部署。

### 13.4 待核對事項

- [ ] `ArticleInteractionService` 的瀏覽去重、併發與稽核，判斷 M17 事件是否可重用其去重元件。
- [ ] S8 分享頁 Blade、Flutter 開啟／複製分享流程與 M14 路由整合。
- [ ] M14/M15/M17 既有規格中對應的 API、Migration 與事件名稱，避免重複實作。
- [ ] S8 PR 與 S9 的先後合併／部署相依與 Queue/Mail/AI/Storage 的實際可用性。
- [ ] 檢查 M16/M19 是否應重用 S8 文章分類、列表與存取控制。

本輪只更新審查文件，未修改任何功能程式或部署設定。

## 14. 第七輪：M16 標籤與 M19 搜尋收藏對 S8 M05 的整合（2026-10-25）

### 已核對決策
- S8 M05 `article_categories` 為最多三層文章分類樹；**不可**當成 M16 單層標籤。
- M16-D01/D04/D07/D22：每 App 獨立標籤資料、單層標籤、一內容可有多標籤、每內容最多 10 標籤。
- M16-D21：自動配色、管理員可覆蓋；D27 多標籤篩選使用 **AND**。
- M16-D23/D25：M16 提供標籤資料，M19 負責搜尋與標籤篩選結果，不另建標籤搜尋頁。
- M16-D06：第一階段不導入 AI 自動標籤。
- M19-D01/D02/D03：每 App 搜尋自己資料，內容類型由後台設定，篩選支援分類、標籤、日期、地點。
- M19-D05/D14：訪客僅可搜尋公開內容；失權收藏保留不可用佔位，恢復權限可再開啟。
- M19-D19/D24/D29：搜尋標題／摘要／內文；先採 Laravel/MySQL 一般關鍵字相關性，不使用 AI 語意搜尋。
- M19-D20/D25：每會員最多 1,000 筆收藏，**包含不可用佔位**。
- M19-D21/D22：內容永久刪除後仍保留不可用收藏佔位，會員帳號刪除時移除其個人收藏。
- M19-D27：按搜尋鍵才執行，非即時搜尋；D15 每頁 20 筆。

### 實作邊界（提案，非已核准 Schema）
1. **M05** 保留文章分類與 `ArticleAccess` 權限權威；**M16** 保留單層標籤及內容關聯；**M19** 統一搜尋、篩選、收藏，不重複建立文章分類或標籤系統。
2. 搜尋文章時沿用 S8 `ArticleAccess::scope` 或等效後端政策，不能先查出受限文章再靠 Flutter 隱藏。
3. M19 收藏需保存內容類型、穩定內容識別與不可用佔位所需的最小資訊；**不得對可永久刪除的內容使用導致收藏被連帶刪除的 cascade FK**。具體 Schema 待設計。
4. 內容失權、下架或永久刪除時，不得透過收藏 API 洩漏私人標題、摘要、封面；恢復可讀時重新檢查後端權限。
5. M16 的熱門標籤依公開可見內容數計算；不能把受限文章數量公開洩漏。AND 篩選、停用標籤、零使用標籤均依決策執行。
6. 先核對 S8 `articles` 既有索引、全文查詢可行性與 MySQL 中文搜尋實測，再確定 M19 搜尋 SQL，不預先引入 Elasticsearch/Meilisearch。

### 待驗證
- [ ] 文章分類／權限／搜尋的實際 SQL 與效能，尤其中文關鍵字。
- [ ] M16 標籤與多型內容關聯的 Migration/API 增量草案。
- [ ] M19 收藏上限併發、刪除佔位、權限恢復與跨裝置一致性的測試。
- [ ] M19 各內容類型（活動、文章、場所）的共通搜尋契約及 Policy 介面。

本輪只記錄技術審查，不修改功能程式、合併 PR 或部署。

## 15. 第八輪：OpenAPI 基線、Migration 相依與自動交付阻擋條件（2026-10-25）

### 15.1 已核對 API 契約
- 已讀 Shared S8 未合併分支 `contracts/openapi.yaml`：OpenAPI **3.1.0**、`info.version=0.7.0`，`paths` 下共有 **49 個路徑**（路徑不等於 HTTP operation 數）。
- 現有路徑涵蓋 auth、會員、公告、活動、基本預約、文章及互動；**不能將此等同 S9 九模組 API 已完成**。
- 現有預約路徑：`/events/{id}/booking`、`/events/{id}/bookings`、`/me/bookings`、`/me/bookings/{id}/cancel`。
- 現有活動路徑：`/events`、`/events/{id}`、`/public/events`、`/public/events/{id}`。
- 現有文章路徑：`/articles`、`/articles/featured`、`/articles/{id}`、`/articles/{id}/cover`、`/articles/{id}/likes`、`/articles/{id}/comments`、`/articles/{id}/like`、`/article-comments/{id}/reports`、`/article-categories`、`/article-report-reasons`。
- 以上路徑列表未涵蓋 S9 專用的候補／實際場次簽到／通用 Deep Link／標籤管理／通用搜尋收藏／連結點擊分析等正式 API；**具體新增路徑名稱尚未核准，不在此虛構**。

### 15.2 Schema／Migration 相依順序（技術建議，非實作核准）
1. **基線**：先解決 S8 PR #20（Shared）與 #17（Tiandi）的基線狀態；現有 S5 `calendar_events`、S6 `event_bookings` 不可重建覆寫。
2. **M09**：活動重複系列／實際場次／單一場次的 nullable `location`、`floor`、`studio`；現有 `calendar_events.location` 不得重複建立。
3. **M08**：在實際場次基礎上新增報名群組、參加者、候補及訪客驗證等增量資料；保留既有單會員預約保護。
4. **M11**：以實際參加者 × 實際場次作簽到／簽退及稽核；候補未轉正不可簽到。
5. **M03、M14、M16、M19、M15、M17**：各自維持資料所有權與獨立 App DB；其具體 Migration 可依介面相依關係排程，不以九模組數字順序作為 SQL 執行順序。
6. **INT-D10/D12**：既有 API 優先相容；Shared 提供方案但各 App 獨立審查與執行 Migration。

### 15.3 自動合併／部署前必須解決的阻擋條件
- **B01：S8 未合併**。S8 PR CI 綠燈是必要證據之一，並非已合併。
- **B02：S9 正式 API/Schema 未完成核准**。不能直接對現有 S6 預約索引進行破壞性替換。
- **B03：目前已核對 CI 只有測試／Debug APK**。未驗證完整自動合併／正式部署 workflow。
- **B04：先前 Repo 設定 `allow_auto_merge=false`**。需另查可行的受控合併策略與權限，不可直接假設自動合併可用。
- **B05：正式部署環境、Secrets、備份／回退及各 App 目標未核實**。各 App 獨立部署，不得共用管理員密碼。
- **B06：S8 Queue／Mail／AI／持久化封面等真實服務尚未驗收**。需與正式部署核對，不可將 fake 測試視為真實整合成功。
- **B07：全模組整合測試仍未建立**。須包含權限、跨模組相依、候補併發、場次名額、簽到、Deep Link、搜尋失權與分析失敗不阻斷核心流程。
- **授權邊界**：使用者已選未來「九模組一次開發並自動合併部署」，但目前明確要求暫不開發；上述均為規劃，不代表已取得實作／合併／部署授權。

### 15.4 待續
- [ ] 為九模組建立「現有路徑／建議新契約／主責模組／授權／測試」矩陣。
- [ ] 核對 `openapi.yaml` 的 request/response schemas 與 Laravel routes 是否存在破壞性風險。
- [ ] 逐項審查 S9 新增 Migration 的 FK、索引、資料保留及 rollback 策略。
- [ ] 查驗自動合併與部署環境的真實前置條件，不讀取 Secrets 值。

本輪未更改功能程式、OpenAPI、Migration、PR 狀態或部署。

## 16. 第九輪：九模組 API 契約／權限／測試責任矩陣（2026-10-25）

### 16.1 範圍及性質

核對 `docs/s9_decisions.md`：M03 有 12 項決策、M08 有 24 項、M09 有 25 項、M11/M14/M15/M16/M17/M19 各有 30 項，共 **241 項模組決策**（12+24+25+6×30=241）。以下為**待實作 API 能力矩陣，不是已核准的 endpoint 名稱或實際完成狀態**。具體路徑、request/response schema、HTTP code、Policy 名稱必須在開發前完成契約審查。

| 模組 | API 能力／資料責任（擬議） | 身分與授權必檢 | 最低跨模組／失敗測試 |
|---|---|---|---|
| M03 認證 | 申請、上傳／查看證據、審核／撤銷、徽章公開、有效期 | 本人可查看自己的證據；授權審核員可審核；徽章公開狀態由本人設定 | 拒絕重申、到期失效、撤銷、證據越權／檔案存取 |
| M08 預約 | 活動場次報名、取消、訪客報名／聯絡驗證後綁定、多人參加者、候補 FIFO、自動遞補 | 報名者／實際參加者／管理員分別授權；資格及名額由後端檢查 | 最後名額併發、候補前組不足不跳號、取消立即遞補、訪客驗證、代報名每人名額 |
| M09 日曆 | 活動系列、實際場次、單場次 nullable location/floor/studio、重複例外、VIP 私人行程、Google 單向同步 | 公開／會員／VIP／管理員依活動可見性；Google token 只在 App 後端 | 單次／未來／全部編輯、跨月時區、VIP 到期、Google 失敗不阻斷報名、每會員每場次僅一 Google 事件 |
| M11 簽到 | 每參加者×實際場次簽到／簽退、QR、工作人員操作、稽核與到期清理 | 本人、授權工作人員及管理員分權；候補不可簽到 | 60 秒 QR 輪替／10 秒寬限、重複簽到、代報名分人、未報名補登、保存期刪除個資 |
| M14 連結 | 內容目標解析、分享／QR、登入後安全回跳、Android/iOS App Links 與網頁備援 | 目標原有後端 Policy 為權威，禁止任意外部回跳 | 公開／受限文章、冷／熱啟動、LINE／瀏覽器／QR、無 App／離線／過期、無權限不洩漏 |
| M15 行銷 | 活動排程、素材／展示、CTA、來源參數及報表消費 | 獨立 marketing 管理權限；公開展示不能洩漏私人內容 | 排程上下架、無權限目標、素材失效不展示、M17 故障不阻斷核心流程 |
| M16 標籤 | 單層標籤 CRUD、合併、停用、顏色、CSV、內容關聯與篩選資料 | 僅授權管理員可管理；前台僅顯示公開可見的有效標籤 | 同名正規化、10 標籤上限、AND 篩選、合併保留關聯、批次刪除二次確認 |
| M17 分析 | 連結點擊、成功開啟回報、去重估計、來源歸因、報表／CSV、期限清理 | 分析專屬權限；不存完整 IP／長期裝置指紋 | 重複成功回報只計一次、機器人／高頻排除、90 天原始／24 月彙總、未確認開啟、統計服務失敗不阻斷導向 |
| M19 搜尋收藏 | 混合類型搜尋／篩選／分頁、收藏增刪／清空、不可用佔位 | 搜尋逐類型套後端 Policy；收藏需登入；管理員不得查看個人收藏明細 | 1,000 筆併發上限、失權／下架／永久刪除佔位、跨裝置同步、20 筆分頁、中文關鍵字 |

### 16.2 跨模組契約必須先明確定義的共通資料

1. **內容識別**：App 自有的 `content_type` + 穩定內容 ID；跨 App 不共享 DB／會員 ID，不用跨 App FK。
2. **活動場次識別**：M09 主責，M08 報名及 M11 簽到指向**實際場次**；不得以重複系列 ID 代替場次 ID。
3. **參加者識別**：M08 主責，M11 每位實際參加者獨立簽到；Google 同步則依 M09-D25 每報名會員每實際場次一筆，不能按參加者重複。
4. **場地**：每實際場次一組可空 `location/floor/studio`；M08/M11 讀取權威場地，不自行建重複場地資料。
5. **可見性**：各原始內容模組後端 Policy 為權威；M14、M15、M16、M19 不得自行授權讀取受限內容。
6. **事件／分析**：M14 導向、M17 記錄點擊與成功開啟；M15 消費來源與分析；失敗不得無條件阻斷核心操作。
7. **變更相容**：保留現有 `/api/v1` 基線，必要破壞性變更須另行核准（INT-D10）。

### 16.3 合約落地與驗收門檻（待開發核准）

- 每個新增 API 應有：owner、method/path、請求／回應 schema、auth/Policy、錯誤碼、idempotency/併發、資料保存、至少一項成功及失敗測試。
- `contracts/openapi.yaml` 應先定義新增契約並保持與各 App 真實 Laravel routes 一致；Shared/各 App 的 migration、測試與權限分別驗證。
- 合併／部署前須完成 INT-D15 的測試及安全門檻；M15-D30、M17-D30 的「上線後觀察」不等於免除全域 CI 安全門檻。
- **尚未核准**：以上擬議 API 的確切 URI、DB 表名、跨模組 service interface 名稱；不得視為已完成實作。

本輪只更新 GitHub 技術審查文件，沒有修改功能程式、合併 PR 或部署。

## 17. 第十輪：S5/S6 Migration、預約 API 與交易併發風險（2026-10-25）

### 17.1 實際讀取的基線
- Shared main `examples/poc_backend/database/migrations/2026_10_29_000100_s5_calendar_events.php`：`calendar_events` 有 nullable `location`、`starts_at/ends_at`（UTC）、`status`、`audience`，尚無 `floor/studio`。
- Shared main `examples/poc_backend/database/migrations/2026_10_30_000100_s6_event_bookings.php`：`calendar_events` 增 `booking_enabled/capacity/opens_at/closes_at`；`event_bookings` 有 `calendar_event_id/user_id/status/active/booked_at/cancelled_at/cancelled_by`。
- Shared main `examples/poc_backend/app/Services/BookingService.php`：報名以 `CalendarEvent::lockForUpdate` 交易保護最後名額；取消以 `EventBooking::lockForUpdate` 保護取消狀態；兩者目前未共用同一把活動場次鎖。
- Shared S8 未合併分支 `contracts/openapi.yaml`：現有 `GET /events/{id}/booking`、`POST /events/{id}/bookings`、`GET /me/bookings`、`POST /me/bookings/{id}/cancel`；驗證要求 `x-verification: required`；預約成功 201，衝突 409，個人清單 20 筆/頁。

### 17.2 已確認的整合風險

| 風險 | 既有程式證據 | S9 增量設計要求（技術建議） |
|---|---|---|
| 多人代報名超賣 | `confirmedCount()` 使用 confirmed **booking rows count** | M08 應以實際占用名額（參加者席次）計算，避免一筆多人報名只占一席 |
| 取消與候補遞補競爭 | `book()` 鎖 `calendar_events`，`cancel()` 鎖 `event_bookings` | 設計單一、固定的場次／預約鎖定順序；名額釋放與 FIFO 遞補在一致交易邊界內執行 |
| 舊唯一索引無法表達多人參加者 | `unique(calendar_event_id,user_id,active)` | 保留防止單會員重複有效報名的保護意圖；另建立參加者與候補模型，不將 location/floor/studio 納入索引 |
| 既有預約 API 不接受訪客或參加者資料 | `POST /events/{id}/bookings` 目前描述建立單會員 confirmed booking | 另規劃相容的訪客／多人／候補 request/response，不擅自破壞舊 Flutter client 契約 |
| S5 只有一個活動表 | `calendar_events` 的單筆時間欄位 | M09 每週／每月重複活動需區分系列與**實際場次**，M08/M11 應對實際場次關聯 |
| 場地資訊與預約資料所有權 | S5 已有 `calendar_events.location` | 單一實際場次 nullable `location/floor/studio`；M09 為權威，M08/M11 讀取，不重複建 `location` |

### 17.3 必備測試案例（待實作）
1. 同場次只剩 2 席時，兩筆各 2 人的併發報名，最多成功一組。
2. 已額滿且 FIFO 候補首組 3 人時，即使後組 1 人可填補剩餘 1 席，也**不得跳號**。
3. 取消與新報名同時發生時，不可繞過既有候補隊列；不可超賣、重複遞補或重複取消。
4. 同會員重複送出、網路重試及同時兩裝置報名，不得重複產生有效預約。
5. 訪客報名不能未驗證聯絡方式就綁定會員並自行管理。
6. M11 對每位實際參加者獨立簽到；尚未轉正的候補不可簽到。
7. 每週／每月重複活動中，同一會員於不同實際場次可分別預約；場地 nullable 且每場次僅一組。
8. 舊單會員 API 與 Flutter 呼叫保持相容；若須破壞性修改，先另行核准。

### 17.4 待確定而不可自行視為已核准
- 實際場次、預約群組、參加者、候補隊列的具體表名／FK／索引／交易實作。
- 既有 S6 單筆 booking 對 S9 多人群組的轉換或相容策略。
- 新增 API 的路徑、payload、錯誤碼及 migration rollback 行為。

本輪僅核對現有程式及更新審查文件，**未修改功能程式、Migration、OpenAPI、合併 PR 或部署**。

## 18. 第十一輪：M09 重複活動、M08 報名與 M11 簽到資料生命週期（2026-10-25）

### 18.1 核對來源與權威
本輪核對 `docs/s9_decisions.md` 的 M09-D03/D09/D12/D19/D25、M08-D11/D12/D16/D17/D19/D20/D22、M11-D01–D30。S5 `calendar_events` 目前只有單筆 `starts_at/ends_at`，並無可直接支持重複系列／例外的完整 Schema。以下生命週期為**整合技術規劃**，不自行視為新業務決策。

### 18.2 生命週期影響矩陣

| 事件 | M09 活動／場次 | M08 報名／候補 | M11 簽到／簽退 | Google 同步 |
|---|---|---|---|---|
| 新增每週／每月重複活動 | 系列與實際場次可識別；場次 ID 穩定 | 各實際場次獨立名額與報名 | 各實際場次獨立出席資料 | 每報名會員每場次一筆 |
| 只修改某一次日期／時間／場地 | 僅該場次套用例外，保留穩定 ID | 既有報名與候補不因改期自行消失；重要異動依 M08-D11 通知 | 原場次出席仍關聯原場次 ID | M09-D12 更新相應 Google 事件 |
| 修改此次及未來 | 以有效日期界線處理未來場次，不覆寫過去場次歷史 | 已報名場次應保留可追溯關聯；具體遷移與通知策略待確認 | 不應改寫已完成場次簽到 | 更新受影響的未來 Google 事件 |
| 修改全部場次 | 支援 M09-D19 的全部範圍 | 必須定義對既有報名的影響；不得默默丟失報名 | 已發生出席歷史不得被無聲改寫 | 更新受影響 Google 事件 |
| 取消單次活動 | 場次標記取消，保留追溯資訊 | M08-D12：取消相關報名與候補、通知相關參加者 | 簽到歷史保留或清理由既定保存政策處理，不以刪除場次硬刪 | M09-D12 移除／更新對應 Google 行程 |
| 報名取消釋出名額 | 場次仍有效 | M08-D16/D17/D22：立即、整組、嚴格 FIFO 遞補 | 取消者不得再以有效報名簽到；歷史更正另循管理員流程 | 取消與遞補分別更新對應會員行程 |
| 管理員縮減容量 | 保留場次及容量調整紀錄 | M08-D20：可選取消部分報名，需稽核；不得自動任意踢人 | 既有出席紀錄不得被隱性刪除 | 同步受影響會員行程 |
| 會員認證資格到期 | 不變 | M08-D19：不自動取消已成立報名 | 是否可簽到仍需按 M11 既有規則及實際報名狀態判斷，不得假定取消 | 不因資格到期自動刪除原有效行程 |
| 到達簽到資料保存期限 | 活動與場次可維持 | 報名保留策略與簽到個資清理分開處理 | M11-D24/D28：刪除簽到個資，必要稽核僅留去識別資料 | 不因簽到個資清理自動更改 Google 行程 |

### 18.3 特別注意的契約與交易風險

1. **場次穩定 ID**：改期／場地異動不可因重新產生重複活動而讓 M08、M11 外鍵失效；如何實作例外表與系列展開仍待 Schema 審查。
2. **M08 取消與候補原子性**：活動取消是整場次報名／候補取消，不能被一般會員取消觸發的自動遞補重新補滿；需有明確活動狀態與交易鎖定順序。
3. **M11 出席歷史**：已簽到／簽退／管理員更正的稽核不能因改期、刪除系列或縮減名額被靜默覆寫；但到期必須依政策清除個資。
4. **Location QR ≠ Studio 預約**：M11-D19/D22 允許固定張貼的 Location QR 對應多場活動，掃碼後選實際場次；這**不違反** M08 已核准 A「每實際場次僅一組 Location／floor／studio」。
5. **動態活動 QR**：M11-D21/D25 的 60 秒更新及 10 秒寬限應與場次 ID、伺服器時間及簽到資格驗證相容；固定 Location QR 不等於可無條件簽到。
6. **Google 去重**：M09-D25 以會員×實際場次維持一筆同步事件，不能按代報名參加者重複建 Google 事件；解除連結及 VIP 到期保留既有行程，停止後續同步。
7. **非核心服務故障**：Google 同步、通知或分析故障不可無條件阻斷核心報名／取消／簽到；失敗需可觀測與安全重試，避免重複通知／事件。

### 18.4 尚待明確的設計點（不擅自決策）
- [ ] 修改「此次及未來／全部」時，對已開始或已完成場次的具體範圍及歷史保護。
- [ ] 改期後是否重開報名窗口、取消期限如何計算、已報名者通知節奏。
- [ ] 場次取消後簽到歷史在尚未到保存期限前的查詢呈現與更正限制。
- [ ] Google 同步失敗的重試次數、冪等鍵及異動合併策略。
- [ ] 重複系列例外／實際場次／參加者／簽到的具體 FK 與 Migration 順序。

本輪僅更新 GitHub 審查文件；未修改程式、合併 PR 或部署。

## 19. 第十二輪：活動異動狀態機、取消遞補隔離與 Google 同步冪等規格草案（2026-10-25）

### 19.1 決策依據與本輪界線
核對 `docs/s9_decisions.md`：M09-D03/D09/D11/D12/D19/D24/D25、M08-D04/D11/D12/D16/D20、M11-D12/D24/D28。以下為**技術草案**，只落實既有決策；具體 Schema/API/重試參數尚未核准。

### 19.2 建議的異動處理矩陣

| 操作 | 核心交易 | 關聯紀錄處理 | 交易提交後附屬動作 | 不得發生 |
|---|---|---|---|---|
| 單次改期／改場地 | 鎖定實際場次，更新單次例外及異動版本 | M08 報名／候補維持指向同場次 ID；M11 已完成出席保持歷史 | 通知受影響者；更新對應 Google 事件 | 刪除再建場次導致失聯 |
| 此次及未來 | 依系列與切點選取受影響的實際場次；記錄操作範圍 | 已完成場次的歷史保護規則仍待核准 | 只對確實異動的場次發送通知與同步 | 無條件重寫歷史出席 |
| 全部場次 | 明確區分系列規則與已展開場次；記錄變更範圍 | 既有報名、簽到不能被批次硬刪 | 對異動的行程更新同步 | 隱性重設報名／簽到 |
| 單次／整場活動取消 | 在場次鎖定下標記取消，終止該場次報名與候補 | 取消相關報名／候補，保留必要稽核 | 通知所有相關參加者；移除／更新 Google 行程 | 取消釋位觸發候補自動遞補 |
| 個別報名取消 | 場次狀態確認有效，鎖定名額與隊列 | 取消有效報名、釋出席次，依嚴格 FIFO 整組遞補 | 對取消／轉正者通知與同步 | 後組插隊、超賣、重複遞補 |
| 管理員縮減容量 | 在場次鎖定下檢查已占用席次與新上限 | 依 M08-D20 由管理員決定是否取消部分報名並稽核 | 通知受影響者，更新相關行程 | 自動任意取消既有報名 |
| 簽到更正 | 驗證工作人員／管理員權限與參加者場次 | 更正保留 M11-D12 稽核，受保存期限限制 | 更新出席統計 | 更正導致重複有效簽到 |

### 19.3 建議狀態／鎖定順序（尚未核准實作）
1. **同一實際場次**的容量、報名、候補與取消操作採統一序列化入口；先取得場次層級鎖，再依固定順序處理報名／候補／參加者，降低死鎖與競爭。
2. 活動取消的交易必須先確定場次不可報名／遞補，再取消相關有效報名與候補；一般報名取消的 FIFO 遞補必須再次檢查場次未取消、仍在可報名條件內。
3. 已完成的取消／遞補與異動通知應可辨識同一業務操作，避免網路重試造成重複取消、遞補或通知；具體 idempotency key 與 DB unique constraint 待 Schema 審查。
4. 對「此次及未來／全部」操作，須在 API request 明示 scope 與生效場次／時間；**已開始／已結束場次能否被修改**仍屬待決，不得默認。

### 19.4 Google 單向同步建議契約
- 由 M09 管理每會員×實際場次的外部 Google event 對應；M09-D25 要求多人代報名不得建立多筆。
- 同步以 App 內部資料為權威；Google 修改不反向覆寫（M09-D06）。
- 背景同步應在核心交易**成功提交後**才排程；Google API 失敗不能回滾已成功的報名／取消／改期。
- 相同場次連續改期時，應以最新版本決定 Google 目標狀態，避免較舊背景工作覆寫新內容。
- Google event 建立／更新／移除須可重試且避免重複建立；具體重試次數、排程與去重鍵待核准。
- 解除 Google 連結時保留既有 Google 行程並停止後續同步（M09-D13）；VIP 到期則保留既有私人行程並停止後續私人行程同步（M09-D18）。

### 19.5 驗收情境
- [ ] 取消活動與會員取消報名併發：最終所有相關報名／候補取消，無候補轉正。
- [ ] 最後一席報名與管理員縮減容量併發：不超賣且有可追溯結果。
- [ ] 同一系列連續兩次改期，背景工作亂序完成：Google 最終時間等於 App 最新版本。
- [ ] 同會員代報名多人，同場次僅一筆 Google 事件。
- [ ] Google 暫時離線／逾時，核心報名成功且同步可安全重試。
- [ ] 單次例外改期後，M08/M11 關聯仍指向相同實際場次。
- [ ] 已簽到場次被批次修改時，原出席與更正稽核不被靜默清除。
- [ ] 到達簽到資料保存期限，個資刪除且必要稽核去識別。

### 19.6 尚待使用者決定／核准的具體項目
- 修改「此次及未來／全部」是否可改已開始／已結束場次，以及例外場次被批次修改時的優先規則。
- 改期後報名／取消期限的重算政策、通知發送時機。
- Google 背景同步重試上限與人工處理門檻。
- Migration 實際表名、外鍵、交易實作、API payload。

本輪只更新 GitHub 審查文件，不修改程式、Migration、OpenAPI、PR 或部署。

## 20. 第十三輪：四項跨模組待決設計收斂（2026-10-25）

### 20.1 待使用者選擇（全部為建議，尚未核准）
| ID | 問題 | A（建議，MVP） | B（較複雜） |
|---|---|---|---|
| S9-L01 | 重複活動批次修改與歷史場次 | 已開始／已結束場次不受「此次及未來」批次修改；「全部」僅修改未開始場次，歷史保留；單次例外不被批次覆蓋 | 允許全部範圍改寫歷史場次及例外，須另做稽核與衝突處理 |
| S9-L02 | 改期後報名／取消期限 | 原有已確認報名／候補保留；若期限原本跟隨場次起始時間（未自訂），隨新時間計算；有自訂期限則保留並要求管理員檢查，通知受影響者 | 每次改期都要求管理員逐項重新設定期限並人工處理已報名者 |
| S9-L03 | Google 同步失敗 | 交易提交後排程，有限次自動重試＋後台顯示失敗供管理員手動重試；同會員×場次避免重複事件 | 無自動重試，全部交由管理員手動處理 |
| S9-L04 | S9 API／Migration 契約批准方式 | 先由 Claude 依已核准業務規則提出**完整單一增量草案**（URI、Schema、FK、Policy、錯誤碼、測試），再一次核准；未核准不開發 | 逐模組逐 endpoint 反覆核准，增加溝通成本 |

### 20.2 與既有決策一致的必要界線
- M09-D19 仍支援單次／此次及未來／全部三種操作；L01 A 只限制歷史覆寫，不移除操作模式。
- M08-D11/D12/D16/D22 仍要求重要異動通知、取消場次取消報名與候補、一般取消嚴格 FIFO 遞補。
- M09-D06/D11/D12/D25 仍要求 Google 單向背景同步、異動更新、每會員每實際場次一筆。
- INT-D06/13–18 的未來一次整合、自動合併部署與測試安全門檻不變；目前仍**不得開始程式實作、合併或部署**。

### 20.3 下一個可執行步驟
等待使用者以 `S9-L01 A、L02 A、L03 A、L04 A`（或逐項 B）確認後，更新 `docs/s9_decisions.md`，再產出完整增量 API/Migration 草案。未經使用者選擇不得將本節建議升格為決策。

本輪只更新審查文件。
