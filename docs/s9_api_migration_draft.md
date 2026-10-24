# S9 九模組單一 API／Migration 增量設計草案

> 版本：draft v0.1｜2026-10-25｜狀態：**待使用者一次審查核准；非開發授權**
>
> 依據：`docs/s9_decisions.md`（S9-L01～L04 全 A）、`docs/s9_feature_inventory.md`（第 1–20 節）。
>
> 此文件中的新表名、URI、索引、錯誤碼、服務介面與任務參數均為**候選方案**，不得視為既有實作或已核准技術決策。

## 0. 核對基線與不可破壞事項

- Shared main：S5 `calendar_events`，包含 nullable `location`、UTC 起訖時間；S6 `event_bookings`、`booking_enabled/capacity/opens_at/closes_at`。
- S6 唯一索引 `(calendar_event_id,user_id,active)`；`active=1` 表示有效預約，取消時為 NULL。保留現有保護，不直接刪除或替換。
- S6 `BookingService.book()` 鎖定 `calendar_events`；`cancel()` 鎖定 `event_bookings`，目前取消與報名的鎖定順序不同，須於 M08 擴充時統一。
- Shared S8 未合併分支 OpenAPI 3.1.0 / info.version 0.7.0，有 49 個 paths；既有活動與預約路徑應優先向後相容。
- S8 Shared #20、Tiandi #17 未合併；不得把 S8 測試成功等同正式基線已合併／部署。
- 各 App 獨立 DB、憑證、會員與部署；Shared 提供共用 Laravel／Flutter 契約及可重用 Migration 方案。
- 不新增跨 App FK、SSO、跨 App 搜尋／分析資料庫或大型事件平台。
- 所有權限由後端 Policy/服務判斷，Flutter 隱藏 UI 不構成授權。

## 1. 資料所有權及候選 Migration 矩陣

| 模組 | 主責資料（候選表／增量） | 關聯及不可違反條件 |
|---|---|---|
| M09 | `event_series`、`event_occurrences`、`event_occurrence_exceptions`、`member_private_events`、`google_calendar_links`、`google_event_mappings`、`calendar_sync_jobs` | 實際場次為預約與簽到權威；每場次一組 nullable location/floor/studio；重複活動週／月、單次例外；已開始／結束場次保護 |
| M08 | `event_booking_groups`、`event_booking_attendees`、`event_waitlist_entries`、`guest_booking_contacts`；既有 `event_bookings` 增量欄位 | 席次按參加者計算；嚴格 FIFO、整組遞補；保留舊單會員唯一索引；訪客聯絡驗證後才能綁定 |
| M11 | `event_attendances`、`attendance_audits`、`checkin_qr_sessions`（若確需持久化） | 每位實際參加者×實際場次；候補不得簽到；QR 60 秒輪替、10 秒寬限；到期清除個資 |
| M03 | `verification_types`、`verification_applications`、`verification_evidence`、`member_verifications` | 證據本人／審核員可見；核准、拒絕、撤銷與有效期分離；不直接複製各 App 既有驗證結構 |
| M14 | `deep_link_targets`（僅確有必要時）；優先使用現有內容固定 ID 與路由 | Android App Links／iOS Universal Links；目標 Policy 為權威；不得重建 S8 文章分享頁 |
| M16 | `content_tags`、`content_tag_assignments` | 單層標籤、正規化名稱去重、每內容最多 10、停用與合併保留規則；內容型別＋ID，無跨 App FK |
| M19 | `member_bookmarks`；搜尋優先現有表查詢與必要索引 | 每會員最多 1,000 筆（含不可用佔位）；內容刪除仍留佔位；會員刪除才清個人收藏；權限檢查不洩漏內容 |
| M15 | `marketing_campaigns`、`marketing_placements`、`marketing_assets`（必要時） | 排程、權限、素材／目標有效性；不複製 M14 導向與 M17 分析 |
| M17 | `link_click_events`、`link_daily_aggregates`、`link_tracking_settings`（必要時） | 總點擊與估計去重分開；隱私最小化；90 天原始／24 月彙總；分析失敗不阻斷連結 |

**命名提醒**：以上皆為候選表名；在確認 Laravel 現有 Models/Migrations 與 S8 合併基線後，應優先增量沿用既有表，避免平行建立重複實體。實際場次與現有 `calendar_events` 的映射須提供 migration/backfill 與 rollback 方案。

## 2. 九模組候選 API 契約矩陣

以下皆以 `/api/v1` 為前綴，**新 URI 尚待一次核准**。既有 URI 原則上保留，新的 schema 欄位採相容增量。

| 模組 | 候選 Method + URI | 主要 request / response | 身分／Policy 與錯誤 |
|---|---|---|---|
| M03 | `GET /verification/types`；`POST /me/verifications`；`GET /me/verifications`；`POST /admin/verifications/{id}/review` | type_id、證據引用、審核 action；回傳申請 ID／狀態／有效期 | 本人／授權審核員；401/403/404/409/422 |
| M08 | **保留** `GET /events/{id}/booking`、`POST /events/{id}/bookings`、`GET /me/bookings`、`POST /me/bookings/{id}/cancel`；**候選新增** `POST /occurrences/{id}/bookings`、`GET /me/waitlist`、`POST /me/waitlist/{id}/cancel`、`POST /guest-bookings/claim` | occurrence_id、attendees[]、guest_contact、group_size；回傳 booking/group/attendee IDs、confirmed/waitlisted、remaining seats | 訪客流程獨立驗證；401/403/404/409 FULL/WAITLIST/ALREADY_BOOKED、422 |
| M09 | `GET /calendar`、`GET /occurrences/{id}`、`POST /admin/event-series`、`PATCH /admin/event-series/{id}`、`POST /me/google-calendar/connect`、`DELETE /me/google-calendar/connect`、`GET /me/google-calendar/status` | from/to、scope=single/future/all、effective_occurrence_id、location/floor/studio nullable；回傳 series/occurrence/version/sync_status | 管理員／本人／VIP；401/403/404/409/422 |
| M11 | `GET /occurrences/{id}/checkin`、`POST /occurrences/{id}/checkins`、`POST /attendances/{id}/checkout`、`POST /admin/attendances/{id}/correct` | attendee_id、QR token、入口與操作；回傳 attendance ID/status/time | 本人／工作人員／管理員；候補 409；過期 QR 422/409 待定 |
| M14 | `GET /links/{type}/{id}`（或直接網頁 route）、`POST /links/resolve`（必要時） | content_type、content_id、return target；回傳授權可見的網頁／App 目的地 | 公開／登入／原內容 Policy；無權限不可洩漏 |
| M15 | `GET /marketing/placements`、`GET /admin/marketing/campaigns`、`POST /admin/marketing/campaigns`、`PATCH /admin/marketing/campaigns/{id}` | placement、時間、素材、CTA、狀態；回傳有效展示內容 | 前台公開可見過濾／行銷管理權限；403/409/422 |
| M16 | `GET /tags`、`GET /admin/tags`、`POST /admin/tags`、`PATCH /admin/tags/{id}`、`POST /admin/tags/merge` | normalized_name、color、enabled、target_type/id；回傳 tag ID、關聯數 | 管理員寫入；重複名稱 409、超過 10 標籤 422 |
| M17 | `POST /links/{id}/clicks`、`POST /links/{id}/opens`、`GET /admin/analytics/links` | click/open event ID、來源 UTM、有限裝置分類、日期；回傳接收狀態與彙總 | 公開入口限流／管理員報表；不儲存完整 IP |
| M19 | `GET /search`、`GET /me/bookmarks`、`POST /me/bookmarks`、`DELETE /me/bookmarks/{id}` | q、type、category、tags[]、date/location、page；bookmark type+id；回傳可見結果／不可用佔位 | 搜尋套內容 Policy；收藏需登入；1,000 上限 409 |

### 2.1 統一成功／失敗格式

- 成功：沿用 `{data,meta?}`；分頁 meta 保留 `current_page/last_page/total` 既有語意。
- 失敗：沿用 `{error:{code,message,fields?,request_id}}`。
- 新錯誤碼（例如 `WAITLIST_BLOCKED`、`ATTENDEE_NOT_ELIGIBLE`、`SYNC_RETRY_EXHAUSTED`）**只是候選**；須先與既有 ApiException / OpenAPI 校對。
- 每條 API 都應列出 request/response JSON Schema、認證、角色、可見性、狀態轉移、限流、冪等性與至少一個負面測試；此 v0.1 為能力／URI 草案，**不是可直接產碼的完成契約**。

## 3. M09 × M08 × M11 交易與資料生命週期

1. **場次 ID 穩定**：重複系列與實際場次分離；M08/M11 指向實際場次。每實際場次僅一組 nullable `location`、`floor`、`studio`，不得將場地納入 S6 booking 唯一索引。
2. **席次以實際參加者計**：群組報名全部席次同時成功或同時進候補，不做部分確認。
3. **嚴格 FIFO**：候補首組需要 3 席而只剩 2 席時，後面 1 人不得插隊；暫時性錯誤不能當成跳號理由。
4. **固定鎖定順序**：所有場次名額、報名取消、候補遞補與管理員縮減容量先鎖實際場次，再處理 booking/group/waitlist/attendee，避免 S6 目前鎖定順序不一致。
5. **活動取消優先**：在同一交易中阻止新報名與遞補，取消相關報名及候補；不得將取消釋出的席次再遞補。
6. **L01 A**：已開始／已結束場次不受 future/all 批次修改；單次例外不被批次覆蓋。過去簽到稽核不得因重複系列重新展開而消失。
7. **L02 A**：改期保留報名與候補；跟隨場次的相對期限重算，管理員自訂絕對期限保留並提示檢查。
8. **M11**：每位實際參加者×實際場次獨立簽到；候補未轉正不可簽到；QR 60 秒輪替與 10 秒寬限；管理員更正留稽核；依 App 保存期限清除簽到個資。

## 4. Google Calendar 背景同步（L03 A）

- M09 單向 App → Google，會員每人只連一個 Google 帳號，使用 App 專用日曆；初次只同步現在與未來。
- 對應鍵：App + member_id + occurrence_id（VIP 私人行程用對應的 private event ID），保證同會員同實際場次只有一個 Google event；代報名多人不重複建立。
- 核心交易提交後排程；有限次自動重試，超過上限在管理後台顯示並允許手動重試。**次數、退避間隔、死信保存期限尚未核准**。
- 任務帶內容版本，執行前確認最新 App 版本；舊任務不得覆蓋新改期結果。Google timeout 後重試應先查既有對應，避免重複建立。
- 解除連結保留 Google 既有事件、停止後續同步；VIP 到期保留私人行程與 Google 已有事件，但不再新增／修改／刪除私人行程。
- Google、通知與 M17 統計故障不得無條件阻斷核心預約／取消／簽到。

## 5. Migration 執行／回退策略（候選）

1. 先核對 S8 Shared/Tiandi PR 是否合併、兩 App 目前 migrations 與 schema dump；不得對未知正式資料庫直接跑。
2. 依相依順序：場次權威與 nullable 場地增量 → 報名群組／參加者／候補 → 簽到 → 其他獨立模組資料表與索引。
3. 優先 additive migration：新增 nullable 欄位與新表；保留 S5/S6 既有欄位、唯一索引與 API。
4. 若要回填既有 S6 單人預約，須先提出可重跑、可核對筆數、可停止的 backfill；在資料驗證前不可切換讀寫來源。
5. FK 刪除策略：活動／場次／出席稽核不得因一般內容刪除被連帶抹除；M19 收藏佔位不能被內容 cascade FK 刪掉。
6. 上線前須備份並實測 rollback；涉及不可逆資料刪除（M11 到期個資清理）要分開處理，不當作 schema rollback。
7. Shared 只提供可重用方案，各 App 獨立審查與執行，不共用 DB 或部署 Secrets。

## 6. 必須交付的自動測試／安全 Gate

| Gate | 最低測試 |
|---|---|
| G01 契約相容 | 現有 S5/S6/S8 API、Flutter 呼叫、OpenAPI 與 Laravel route 對齊；破壞性變更另行核准 |
| G02 名額併發 | 多人群組最後席次併發不超賣；重試不重複預約 |
| G03 FIFO | 首組席次不足不得跳號；取消立即整組遞補；暫時錯誤不跳號 |
| G04 取消競爭 | 活動取消與會員取消併發後，不得重新遞補已取消場次 |
| G05 重複活動 | 週／月、單次例外、future/all、L01 歷史保護與穩定場次 ID |
| G06 場地 | nullable location/floor/studio；每場次單一組；不影響 booking unique |
| G07 簽到 | 每參加者獨立、候補禁止、QR 輪替寬限、重複保護、稽核及保存期限 |
| G08 Google | 單向同步、每會員每場次一筆、亂序任務、逾時重試、解除連結與 VIP 到期 |
| G09 權限 | M03 證據、S8 ArticleAccess、M14 導向、M15 私人內容、M19 搜尋收藏不越權 |
| G10 搜尋／標籤 | AND 篩選、10 標籤上限、中文關鍵字效能、失權收藏佔位、1,000 上限併發 |
| G11 隱私／統計 | M17 去重為估計、來源歸因、資料清理、不得完整 IP 或跨站指紋 |
| G12 部署阻擋 | CI 與安全測試失敗即停止；Secrets 分 App；核對自動合併能力、部署目標與回退；不得因 M15/M17 上線後觀察免除全域 Gate |

## 7. 尚待一次審查核准清單

- [ ] 確定場次與既有 `calendar_events` 的實際映射（含舊 booking backfill）。
- [ ] 核准九模組實際表名、欄位、FK、unique/index、刪除策略。
- [ ] 核准所有新增 URI 的 method、request/response JSON Schema、HTTP/error codes。
- [ ] 核准後端 Policy/角色／VIP 與訪客流程。
- [ ] 核准 Google retry 次數、間隔、逾時／人工處理門檻。
- [ ] 確認單次例外與批次變更衝突的具體實作，符合 L01。
- [ ] 核對實際 App CI、Secrets 權限、PR 自動合併限制及部署回退。
- [ ] 由使用者一次確認完整技術契約後，**另行取得實作授權**。

**執行界線：目前只編寫 GitHub 草案；不修改程式、Migration、OpenAPI、PR、CI 或部署。**
