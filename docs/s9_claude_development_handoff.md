# S9 Claude 整合開發交接指令｜S1–S9 基線與自主設計邊界

> 2026-10-25。狀態：交付 Claude 的開發指令草案；**使用者將此指令交給 Claude 並明確要求開始時，才構成 Claude 的開發授權**。本文件建立本身不啟動開發。GitHub 文件分支 `docs/s9-approved-decisions-20261024`，非 main。

## 0. 任務與權責

你是 Claude，負責 **在現有 S1–S8 已開發成果上，整合實作 S9 九模組**，不是重新做一套 App 或平台。

- **使用者**：最終產品、已確認業務決策、實際設備測試驗收的唯一裁決者。
- **Claude**：有權自主選擇 S1–S9 內部架構、Schema、API、Flutter、Laravel、Filament、測試策略、實作順序與必要重構；可反駁 ChatGPT 的技術草案，需寫下理由、風險及替代方案。
- **ChatGPT**：提供參考規格，最後審查 GitHub PR、CI、差異、相容性與已確認決策；ChatGPT 的未核准建議**不是**必須照做的技術指令。
- **使用者實測**：ChatGPT 審查後，由使用者進行實際操作與最終驗收。Claude 的 CI PASS 不代表使用者驗收 PASS。

**禁止**：擅自變更已確認 S9 決策、過度設計、另建第二套既有核心、擅自 merge PR、擅自正式部署、聲稱未執行的測試已通過。

## 1. 讀取順序：GitHub S1–S9 是主要事實來源

先讀並對照兩 Repo 的當前 main、開放 PR、實際 HEAD 與 CI；文件不等於程式已完成。

1. Shared：https://github.com/ilifeup/Shared_Modules_For_App
2. Tiandi：https://github.com/ilifeup/tiandi-app
3. **S9 正式業務決策**：`docs/s9_decisions.md`（此文件分支），以使用者最後確認的 D/L/INT 決策為最高優先。
4. **S9 已有功能與差異證據**：`docs/s9_feature_inventory.md`（此文件分支）。
5. **S9 ChatGPT 參考技術方案**：`docs/s9_api_migration_draft.md`（此文件分支；包含過時候選，依後續核准決策與最新章節覆蓋）。
6. **S1–S8 真實成果**：在 Shared/Tiandi 中逐一找到 README、各階段 docs、contracts/openapi.yaml、Laravel migrations/models/services/controllers/policies/routes、Flutter shared_core/mobile、Filament、tests、.github/workflows 與 Tiandi `shared/SHARED_VERSION`；以實際 commit 與測試結果確認。不得假設路徑或功能已存在。
7. S8 PR：Shared #20、Tiandi #17。先核對是否已合併；如仍未合併，S9 可基於已確認 S8 HEAD 開發，但**不得自行合併 S8**。先在 Shared 整合，再保持 Tiandi 釘版同步。

已核對參考基線（開始時重查）：Shared S8 HEAD `0e16f591ce17e2fc23ac8ff61061c012c4d15299`，Tiandi S8 HEAD `5b071aefd25459d9cad1b586cbc9374cc279dafd`；兩者對 main 各 ahead 4/2、behind 0，72 files changed；對應 CI 曾成功。這不是 S9 CI，也不是當前 PR 即時狀態。

## 2. S9 九模組完整交付範圍

| 模組 | 交付目標 | 必須沿用或整合 |
|---|---|---|
| M03 身分認證 | 可設定認證類型、證據、多人審核、徽章、撤銷/到期 | 既有會員、Sanctum、OTP、member.verified、稽核、通知 |
| M08 活動報名 | 單人、多人代報名、訪客、容量、嚴格 FIFO 整組候補、取消/遞補 | S6 event_bookings、既有 BookingService、API、Flutter DTO |
| M09 活動行事曆 | 週/月/列表、週/月重複、single/future/all、VIP 私人、Google 單向同步、場地欄位 | S5 calendar_events、既有事件 ID/查詢 |
| M11 簽到 | 每實際參加者/場次簽到、簽退、稽核修正、60 秒 QR＋10 秒寬限 | M08 attendees、M09 場次、現有稽核 |
| M14 Deep Link | 內容連結、App/網頁回退、權限重驗 | S8 文章分享與 ArticleAccess，不重造 |
| M15 Marketing | 活動/內容行銷版位、CTA、排程、與點擊統計連動 | M14/M17；上線後 D30 觀察不免除安全 Gate |
| M16 Tag Intelligence | 一層標籤、可編輯顏色、每內容最多 10 | 既有文章/活動資料，不把 category 直接當 tag |
| M17 URL Clicks & Analytics | 點擊事件、彙總、隱私/留存、防重複 | M14 單一 link 主責，M15 報表 |
| M19 Search & Bookmark | 跨內容 AND 搜尋、20/page、每 App 1000 收藏、失效 placeholder | S8 ArticleAccess、S5 events、既有搜尋/公告 |

**精確規則以 `s9_decisions.md` 為準**；上表只是摘要，不能取代每個 D01–D30、INT-D01–D18、S9-L01–L06。必須實作已確認決策，而非只交付九個空殼頁面。

### 已核准不可更改的跨模組重點
- **L05 A**：`calendar_events` 是每個實際場次唯一權威；保留 `calendar_events.id` 與 S6 FK。可增加 `event_series`，**不可另建平行的權威 occurrence 表**。每場次 `location`、`floor`、`studio` 可各自為空，場次一次只屬於一個場地組合。
- **L06 A**：保留 `event_bookings` 舊會員預約相容錨點與 `UNIQUE(calendar_event_id,user_id,active)` 語意；新增 group、attendee、waitlist；**只有一套席次、鎖定、FIFO 遞補引擎**，不得雙計數。Claude 可自主決定具體表名、欄位及遷移方式，只要符合決策。
- **L01 A**：重複活動 single/future/all；保護歷史及個別例外。
- **L02 A**：改期保留已確認/候補；相對期限重算，固定期限保留並警示。
- **L03 A**：Google 單向同步，會員×實際場次一個事件，有限重試、管理員重試、冪等。
- **L04 A**：九模組共用一份整合 API/Migration/Policy/錯誤碼/測試契約，實作前先定一致性。
- **M08**：每位實際參加者占一席；整組 FIFO，首組不足席不可跳後組；取消即時遞補（整場取消例外）；訪客姓名＋Email/電話，註冊並驗證原聯絡方式才能自行管理。
- **M11**：每 attendee×occurrence 唯一，QR 60 秒＋10 秒，簽退/修正稽核、留存後去識別。
- **M19**：AND 搜尋、20/page、最多 1000 收藏、刪除內容保留失效 placeholder；後端內容權限。
- Shared 共用核心；每 App 自有 DB/會員/憑證/部署，不得做跨 App SSO 或共用正式營運資料庫。

## 3. 六項技術問題：授權 Claude 自主裁決，但須守住底線

這六項是**技術設計授權**，不是新業務決策。請 Claude 在開始修改功能碼前，以 `docs/s9_technical_decisions.md` 記錄每項選擇、取捨、回退、驗收，並一次完成，不要逐項反問使用者；遇到與已核准業務規則衝突時才停止請示。

1. **訪客驗證與認領**：選擇安全、低維護的 contact verification/claim 機制、有效期、限流與個資保存；不得僅靠姓名/預約編號認領，不得未經授權使用付費簡訊服務，未註冊通知僅記錄待綁定。
2. **席次唯一性與舊資料回填**：由 Claude 決定 group/attendee/waitlist 表及唯一索引、legacy-only→mapped 的冪等轉換；必須能證明不超賣、不雙計數、同會員不可重複占位、回退保護舊 FK/唯一索引。
3. **改期期限**：Claude 選擇 deadline mode/offset Schema；`booking_closes_at=NULL` 原語意保持跟隨活動開始；已存在非 NULL 的固定時間不可猜成相對時間；改期按 L02 處理。
4. **Google 同步**：Claude 決定 OAuth、同步佇列、有限重試與密鑰接法，僅整合真正可取得的部署資源；沒有 Google credentials 時功能要清楚標記 BLOCKED 並可在測試環境以 mock 測試，不能假裝已完成真實 OAuth。
5. **多型內容與失效收藏**：Claude 決定 allowlist、FK 可行性、刪文/軟刪、placeholder、ArticleAccess 權限；不引入重型搜尋平台，除非現有資料量/需求證明必要。
6. **CI、部署、回退**：Claude 選擇最小可行 GitHub Actions、Secrets 與 rollback；未驗證真實部署目標/權限前，只能完成可審查 PR 與 CI，不得聲稱可安全自動部署，也不得使用生產憑證測試。

### Claude 技術自主權範圍
**可自主決定**：技術表名/欄位、Service 邊界、DTO、API 新路徑、測試組織、UI 元件重用、必要的相容性重構、技術順序、較簡單的替代方案。對 ChatGPT 草案如有更好的設計，請採用並在技術決策文件寫明原因。

**必須先問使用者**：變更任何已確認業務決策、擴大九模組以外的產品範圍、破壞性刪除既有資料、導入付費/外部服務、需新的長期維運基礎設施、無法回退的 DB 操作、要求合併 PR 或正式部署。

## 4. 防止過度開發的硬性規則

1. **重用優先**：先找到 S1–S8 已有功能；只補缺口，不重寫已通過測試的認證、通知、日曆、預約、文章、稽核。
2. **一套權威**：活動只用 calendar_events；報名只有一套計數/鎖/FIFO；內容權限只由後端 Policy/ArticleAccess 決定；M14/M15/M17 各有清楚單一主責。
3. **MVP 足夠**：不建微服務、跨 App SSO、獨立資料倉儲、Kafka/Redis/Elasticsearch/向量資料庫、通用工作流引擎、無關抽象層，除非現有程式已依賴且有實際必要。
4. **不做順手加功能**：不要添加與 S9 無關的 dashboard、通用 CMS、複雜權限編排、AI 功能、重複管理 API、第二套前端元件庫。
5. **保留既有相容性**：舊 S6 無 body `POST /events/{id}/bookings`、`BookingInfo`、`BookingApi`、`GET /me/bookings` 20/page、`CalendarEvent` 62 天/200 筆、舊狀態碼/錯誤碼與 FK。新增群組／候補另用 DTO，不能破壞舊 Flutter。
6. **一次整合交付，內部小步提交**：九模組一個整合開發任務，允許分模組 commit/測試；不得以九個模組各自未整合的空殼 PR 冒充完成。
7. **遇到非必要重構就停止擴張**：如非安全/相容性/明確業務需求，列為後續建議，不納入本次 S9。

## 5. 實際工作流程

### Phase A｜快速基線與技術決策
- 核對 Shared/Tiandi main、S8 PR/HEAD/CI、現有 S1–S8 文件與程式。
- 產出一份 `docs/s9_technical_decisions.md`，記錄六項自主裁決及跨模組資料主責。
- 產出/更新一份 **可檢查的 S9 API + Migration + Policy + Error + Test Contract**；不必為每個瑣碎欄位等待使用者再次回覆。
- 若存在無法在不違反使用者決策下解決的重大衝突，列出最少必要問題，先停下該部分。

### Phase B｜實作
- 於 Shared 建立 S9 功能分支；基於核實的 S8 整合基線，不在 main 直接開發。
- 先資料與服務／權限，再 API/Filament，最後 Flutter 共用核心與 Tiandi UI；同步 Tiandi `shared/SHARED_VERSION`。
- M08/M09/M11 的共享 ID、交易、鎖與權限先整合；M14/M15/M17 共用 link/click；M16/M19 共用 tag/內容權限。
- 每個模組均須有可實際操作的完整路徑與測試，不只 mock/UI。

### Phase C｜測試與 GitHub
- 執行既有 S1–S8 回歸及 S9 單元/整合/權限/併發測試；至少覆蓋 100 並行搶席、嚴格 FIFO、訪客認領、改期、Google 冪等、QR 重放、越權分享、搜尋/收藏。
- PHP/Laravel、Dart/Flutter、OpenAPI 路由檢查與 GitHub Actions 都要留下可核對的 PASS/FAIL/BLOCKED 證據；缺環境的測試不可冒稱 PASS。
- **將程式 commit 並 push 到 GitHub，分別建立 Shared/Tiandi PR（Base 以當時核准基線為準），不要自行 merge**。如 PR 需要先合併 Shared 才能核對 Tiandi，記錄依賴關係。
- PR 附：變更摘要、決策符合性表、Migration/rollback、API 差異、CI 連結、測試截圖/操作證據、風險與 BLOCKED 項目、兩 Repo HEAD SHA、下一步人工驗收步驟。

### Phase D｜ChatGPT 審核與使用者實測
- Claude 完成後先回報 GitHub **兩個 PR URL、HEAD SHA、CI、未完成/阻礙**，停止繼續擴張或部署。
- ChatGPT 審核：是否遵守 S9 決策、S1–S8 相容、資料安全、測試完整、範圍與過度設計。
- 若 ChatGPT 提出缺陷，Claude 僅修正經核實的缺陷並更新同一 PR；再次 CI，回報新 SHA。
- **ChatGPT 審核通過 ≠ 自動合併／部署**。須使用者另外明確批准 merge/deploy；部署後由使用者實際測試驗收，未通過則記錄問題再修。

## 6. S9 完成的證據門檻

- 九模組的已確認 D/INT/L 決策有對應實作與驗收矩陣，無重大缺漏。
- S1–S8 舊 API/DTO/資料庫/會員權限回歸通過；每 App 資料隔離。
- 容量/併發/FIFO/取消、重複活動/Google、QR/簽到、內容權限/個資等高風險測試有可核對證據。
- Shared 與 Tiandi 的 commit、PR、CI、釘版一致，Migration/rollback 在測試 DB 驗證。
- 未完成外部依賴（Google credentials、正式部署、實機驗收）明確標 BLOCKED，不得寫「全部完成」。
- **最終交付狀態為「PR 待 ChatGPT 審查」，不是已合併、已部署、已驗收。**

## 7. Claude 收到此指令後第一個回覆

請直接簡述：
1. 你已讀到哪些 S1–S9 文件及兩 Repo 的實際基線（列 SHA）。
2. S9 九模組重用與新增的分界。
3. 六項技術裁決採用什麼最小設計，哪些是使用者已核准、哪些是你自行選擇。
4. 主要 P0 風險及如何驗證。
5. 開始 S9 整合開發的工作順序。

**不要重新逐題詢問已確認決策；不要只寫計畫不做開發。使用者正式把本指令交給你並要求開始後，依本文件進入實作與 GitHub PR，停在待 ChatGPT 審查狀態。**

## 8. 2026-10-25 第二輪審查修正指令（ChatGPT 審查，使用者轉交）

範圍：只修正原有 Shared PR #21、Tiandi PR #18；維持 Draft，不合併、不部署。

1. 以 `docs/s9_compliance_matrix.md` 的 265 項為基準，逐項重新核對 12 項「部分完成」。已核准且可在現有開發環境完成的功能，以最小方式補齊，不新增其他功能。
2. 優先完成：
   - M09 週檢視
   - M11 工作人員手動簽到
   - M19 日期與地點搜尋篩選
   - M14 連結失效／無權限／離線提示與返回首頁
3. M11 訪客核對、M14 場所與會員公開頁、M17 UTM 選項：先核對正式決策與現有資料模型，不為了消除「部分」狀態而建立不必要的第二套系統。
4. iOS Universal Links：先完成不依賴真實 Team ID 的設定模板；正式簽章與實機驗證維持 BLOCKED。
5. M11 稽核維持 append-only（M11-D24-A），不得再直接修改既有 AuditEvent；簽到個資依保存期限處理，補充管理員操作責任與去識別測試。
6. 針對「符合但無自動測試」項目，優先補高風險的權限、資料一致性與重要使用流程測試，不要求每項機械式各寫一個測試。
7. 保留 S1～S8 相容性、現有 API、Migration 防護與六個 Flutter 套件；不加入 Laravel Boost 或其他非必要套件。
8. 更新符合性矩陣，清楚區分 PASS、PARTIAL、BLOCKED、NOT TESTED；未實機測試的項目不得寫成完成驗收。

完成後推送兩個 PR，回報新 HEAD、CI、修正摘要與仍待人工驗收的項目，再交回 ChatGPT 審查。

