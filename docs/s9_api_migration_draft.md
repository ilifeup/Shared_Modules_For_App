# S9 九模組單一 API／Migration 增量設計草案

> 版本：draft v0.8｜2026-10-25｜狀態：**待使用者一次審查核准；非開發授權**
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

## 8. v0.2 候選資料字典與索引（未核准）

**統一慣例（候選）**：各表 `BIGINT UNSIGNED id`、`created_at/updated_at`；所有時間以 UTC 儲存，顯示依 App 設定時區；文字使用 `utf8mb4`。多型 `content_type` 以允許清單驗證，不接受任意 PHP class name。以下只列業務核心欄位，非完整 Laravel Migration。

| 候選表／增量 | 核心欄位（型別候選） | 主要約束／索引 |
|---|---|---|
| `event_series` | `id BIGINT`、`frequency ENUM(weekly,monthly)`、`timezone VARCHAR(64)`、`starts_at TIMESTAMP`、`until_at TIMESTAMP NULL` | `INDEX(frequency,starts_at)`；不得以系列 ID 直接當預約場次 |
| `event_occurrences` | `id BIGINT`、`series_id BIGINT NULL`、`calendar_event_id BIGINT NULL`、`starts_at/ends_at TIMESTAMP`、`status VARCHAR(20)`、`location/floor/studio VARCHAR(255) NULL`、`capacity INT UNSIGNED NULL`、`revision INT UNSIGNED` | `INDEX(series_id,starts_at)`、`INDEX(status,starts_at)`；舊 S5 event 映射策略待決 |
| `event_occurrence_exceptions` | `series_id BIGINT`、`occurrence_id BIGINT`、`exception_type VARCHAR(20)`、`changed_fields JSON` | `UNIQUE(series_id,occurrence_id)`；不覆蓋 L01 保護的單次例外 |
| `event_booking_groups` | `occurrence_id BIGINT`、`booker_user_id BIGINT NULL`、`guest_contact_id BIGINT NULL`、`status VARCHAR(20)`、`seat_count SMALLINT UNSIGNED`、`booked_at TIMESTAMP` | `INDEX(occurrence_id,status)`、`INDEX(booker_user_id,booked_at)` |
| `event_booking_attendees` | `group_id BIGINT`、`occurrence_id BIGINT`、`display_name VARCHAR(255)`、`member_user_id BIGINT NULL`、`status VARCHAR(20)` | `INDEX(occurrence_id,status)`、`INDEX(group_id,status)`；參加者個資需保存政策 |
| `event_waitlist_entries` | `group_id BIGINT`、`occurrence_id BIGINT`、`sequence BIGINT UNSIGNED`、`seat_count SMALLINT UNSIGNED`、`status VARCHAR(20)` | `UNIQUE(occurrence_id,sequence)`、`INDEX(occurrence_id,status,sequence)`；嚴格 FIFO |
| `guest_booking_contacts` | `id BIGINT`、`email VARCHAR(255) NULL`、`phone VARCHAR(40) NULL`、`verified_at TIMESTAMP NULL`、`claimed_user_id BIGINT NULL` | email/phone 至少一項；claim 須驗證原聯絡方式；隱私與限流 |
| `event_attendances` | `occurrence_id BIGINT`、`attendee_id BIGINT`、`checked_in_at TIMESTAMP NULL`、`checked_out_at TIMESTAMP NULL`、`status VARCHAR(20)` | `UNIQUE(occurrence_id,attendee_id)`；不允許候補簽到 |
| `attendance_audits` | `attendance_id BIGINT NULL`、`actor_id BIGINT NULL`、`action VARCHAR(40)`、`occurred_at TIMESTAMP`、`details JSON NULL` | `INDEX(attendance_id,occurred_at)`；保存期後去識別 |
| `verification_applications` | `user_id BIGINT`、`type_id BIGINT`、`status VARCHAR(20)`、`reviewed_by BIGINT NULL`、`expires_at TIMESTAMP NULL` | `INDEX(user_id,type_id,status)`；拒絕可重新申請 |
| `verification_evidence` | `application_id BIGINT`、`storage_key VARCHAR(512)`、`mime_type VARCHAR(120)` | `INDEX(application_id)`；私有儲存，後端授權下載 |
| `member_verifications` | `user_id BIGINT`、`type_id BIGINT`、`status VARCHAR(20)`、`public_badge BOOLEAN`、`expires_at TIMESTAMP NULL` | `INDEX(user_id,status)`；撤銷／到期狀態須一致 |
| `content_tags` | `name VARCHAR(120)`、`normalized_name VARCHAR(120)`、`color VARCHAR(16) NULL`、`enabled BOOLEAN` | `UNIQUE(normalized_name)`（各 App DB 獨立）；合併不重複 |
| `content_tag_assignments` | `tag_id BIGINT`、`content_type VARCHAR(40)`、`content_id BIGINT` | `UNIQUE(tag_id,content_type,content_id)`、`INDEX(content_type,content_id)`；每內容最多 10 需交易驗證 |
| `member_bookmarks` | `user_id BIGINT`、`content_type VARCHAR(40)`、`content_id BIGINT`、`created_at TIMESTAMP` | `UNIQUE(user_id,content_type,content_id)`、`INDEX(user_id,created_at)`；內容刪除不 cascade |
| `marketing_campaigns` | `title VARCHAR(255)`、`status VARCHAR(20)`、`starts_at/ends_at TIMESTAMP NULL`、`target_type VARCHAR(40)`、`target_id BIGINT NULL` | `INDEX(status,starts_at,ends_at)`；受限目標不公開 |
| `marketing_placements` | `campaign_id BIGINT`、`placement_key VARCHAR(80)`、`sort_order INT` | `INDEX(placement_key,sort_order)`；由各 App 配置 |
| `link_click_events` | `link_key VARCHAR(120)`、`occurred_at TIMESTAMP`、`source VARCHAR(40)`、`dedupe_token_hash VARCHAR(128) NULL`、`opened_at TIMESTAMP NULL` | `INDEX(link_key,occurred_at)`、`INDEX(occurred_at)`；不存完整 IP |
| `link_daily_aggregates` | `link_key VARCHAR(120)`、`date DATE`、`source VARCHAR(40)`、`total_clicks BIGINT`、`estimated_unique BIGINT` | `UNIQUE(link_key,date,source)`；App 固定統計時區 |

**重要待驗證**：`event_occurrences.calendar_event_id` 與既有 `event_bookings.calendar_event_id` 的實際映射；`event_booking_groups` 和既有 `event_bookings` 是一對一延伸或獨立新流程；單次例外與場次 materialization；刪除 FK 策略。這些不能僅憑本表直接產生 Migration。

## 9. v0.2 JSON API payload 範例（候選，非正式 OpenAPI）

### 9.1 場次多人預約

`POST /api/v1/occurrences/{id}/bookings`，**會員範例**：

```json
{
  "attendees": [
    {"display_name": "Attendee A"},
    {"display_name": "Attendee B"}
  ],
  "client_request_id": "b0c12e23-96c9-4b90-8df2-770cf0556200"
}
```

成功 `201` 範例（欄位候選）：

```json
{
  "data": {
    "booking_group_id": "123",
    "occurrence_id": "456",
    "status": "confirmed",
    "seat_count": 2,
    "attendees": [
      {"id": "901", "status": "confirmed"},
      {"id": "902", "status": "confirmed"}
    ]
  }
}
```

額滿且開放候補時，整組回傳 `waitlisted`，不可部分確認；是否以 `201` 表示候補建立仍待契約核准。訪客請求另加 `guest_contact`，聯絡方式須滿足姓名＋email 或 phone 的 M08-D06 規則；claim 不得僅憑猜測 email/phone。

### 9.2 重複活動修改

`PATCH /api/v1/admin/event-series/{id}` 候選：

```json
{
  "scope": "future",
  "effective_occurrence_id": "456",
  "changes": {
    "starts_at": "2026-11-03T10:00:00Z",
    "ends_at": "2026-11-03T12:00:00Z",
    "location": "Room A",
    "floor": null,
    "studio": "Studio 1"
  },
  "expected_revision": 4
}
```

`scope` 只允許 `single|future|all`；L01 A 保護已開始／結束場次與已建立單次例外；`expected_revision` 是候選樂觀鎖欄位，非已核准。

### 9.3 簽到與重複請求

`POST /api/v1/occurrences/{id}/checkins` 候選：

```json
{
  "attendee_id": "901",
  "qr_token": "short-lived-signed-token",
  "client_request_id": "7a029e22-8c7c-4c0e-97b7-a1cb3d1f9321"
}
```

回傳 `data.attendance_id`、`status`、`checked_in_at`；同參加者同場次重複請求應回傳已簽到狀態而非再新增有效紀錄。實際 QR 簽章、token 不可在日誌記明文。

### 9.4 搜尋與收藏

`GET /api/v1/search?q=example&type=article&tags[]=1&tags[]=2&page=1` 候選：回傳 `data[]` 各項含 `content_type`、`content_id`、`title`（僅授權可見）、`meta.current_page/last_page/total`；多標籤 AND。

`POST /api/v1/me/bookmarks` 候選：

```json
{"content_type":"article","content_id":"123"}
```

已失權／下架／永久刪除內容只能呈現不可用佔位，不回傳受限標題或封面；1,000 筆上限包含佔位，併發需在交易中檢查。

### 9.5 共通錯誤格式

```json
{
  "error": {
    "code": "BOOKING_FULL",
    "message": "名額已滿。",
    "request_id": "req-example"
  }
}
```

現有 `BOOKING_FULL` 應沿用；其他新錯誤碼待正式 OpenAPI 與 Laravel Exception 對照。

## 10. v0.2 FK、索引、刪除與回退檢查清單

- [ ] 既有 S6 `event_bookings.calendar_event_id` 是 `restrictOnDelete`；`user_id` 是 `cascadeOnDelete`；`cancelled_by` 是 `nullOnDelete`。新增群組／簽到稽核時必須明確處理會員刪除對個資與合法稽核的影響。
- [ ] 同場次 `event_waitlist_entries.sequence` 必須在鎖定下產生；不能用單純 `MAX(sequence)+1` 的無鎖查詢。
- [ ] `event_booking_attendees.occurrence_id` 與 `group_id` 必須避免跨場次錯誤關聯；可用複合 FK 或交易內驗證，具體策略待核准。
- [ ] `event_attendances` 的唯一約束阻止同人同場次重複有效簽到；管理員更正保留獨立稽核。
- [ ] `member_bookmarks` 不應以會隨內容永久刪除而 cascade 的 FK 指向原內容；會員帳號刪除時清除個人收藏。
- [ ] 90 天 M17 原始與 24 月彙總保存須依 App 固定統計時區正確清理。
- [ ] Migration 先 additive，舊資料 backfill 必須可重跑且驗證總數；不可在沒有備份／回退演練時直接切換正式資料。
- [ ] 以上候選 Schema 尚未對兩 Repo 全部 migration、Laravel models、routes、政策逐項校驗；**不符合直接開發 Gate**。

## 11. v0.2 尚缺的正式契約工作

1. 每條候選 API 的完整 OpenAPI 3.1 JSON Schema（required、nullable、min/max、enum、security、HTTP statuses、example）。
2. 實際場次與 S5/S6 既有表的精確資料遷移策略，以及現有 Flutter 端相容性。
3. M03、M14、M15、M16、M17、M19 管理 API 的完整 payload 與 Filament 權限。
4. Google 失敗重試的次數、間隔與人工處理門檻。
5. 完整 migration up/down、測試資料集、併發壓力測試及部署回退驗證。

**本節是 v0.2 候選資料字典與 JSON 範例，不是已核准 API/Migration，更不是開發授權。**

## 12. v0.3 S5／S6 原始碼核對與安全遷移提案（2026-10-25）

### 12.1 已從 Shared main 原始碼確認

| 檔案 | 現況 | 對 S9 的影響 |
|---|---|---|
| `examples/poc_backend/database/migrations/2026_10_29_000100_s5_calendar_events.php` | `calendar_events.id`、nullable `location`、UTC 起訖、draft/published/cancelled；取消保留原列與穩定 ID | 不得刪掉重建活動而造成舊預約失聯；floor/studio 只能增量新增 |
| `examples/poc_backend/database/migrations/2026_10_30_000100_s6_event_bookings.php` | `event_bookings.calendar_event_id` FK restrictOnDelete、`user_id` cascadeOnDelete、`active` 1/NULL、unique(event,user,active) | 不能直接把 FK 改指新場次；舊唯一索引保留，須兼顧舊會員單人預約 |
| `examples/poc_backend/app/Services/BookingService.php` | `book()` 鎖活動 row；`cancel()` 鎖 booking row；`confirmedCount()` 計 confirmed rows；稽核在 DB transaction 後 | S9 要統一鎖順序，容量按 attendee 席次，確保取消與候補遞補同一交易一致性 |

### 12.2 兩種映射方案比較（均未核准）

**方案 A（本輪建議，最小相容）**：保留 `calendar_events` 作為**每個實際場次的權威表**；新增 `event_series`（重複規則），對 `calendar_events` 增量 `series_id`、`original_start_at`（例外識別）、nullable `floor`、nullable `studio`、`revision` 等必要欄位。既有 `event_bookings.calendar_event_id` 完全不變；M08/M11 的新表同樣以 `calendar_event_id` 指向每次活動。這避免雙重場次權威及舊資料回填，但系列展開、未來場次生成與單次例外要以交易及唯一鍵防重複。

**方案 B（v0.2 原先假設）**：新增 `event_occurrences` 為權威場次，建立 `calendar_events` 與 occurrence 一對一映射，逐步讓新 M08/M11 指向 occurrence。此法引入雙 ID、同步與 backfill，須額外維護舊 booking 對應、API 相容與雙表一致性，風險較高。

**推薦 A 的理由**：既有 S5 註解已明確要求取消保留 ID 供 S6 關聯；保留舊 ID 可直接滿足 L01 歷史保護，降低 S9 一次整合時的 migration 風險。**但 A 尚未獲使用者核准，不能直接改程式。**

### 12.3 若採方案 A，v0.2 草案須同步修正的地方

- 第 1、8 節 `event_occurrences` 改成 `calendar_events` 增量；所有候選 `occurrence_id` FK 改為 `calendar_event_id`，對外可使用 `occurrence` 語意但需明確標示其 ID 即 `calendar_events.id`。
- M09 場地的 `location` 沿用現有欄位，只新增 `floor`、`studio` nullable；不得建立第二套場地主檔。
- `event_series` 需要週／月規則、時區、結束條件與可識別單次例外；展開場次的 `UNIQUE(series_id,original_start_at)` 是**候選**，避免重跑生成重複場次。
- 既有單次活動的 `series_id` 維持 NULL，無須大量 backfill；舊 booking 原 FK 完全不動。
- M08 新 group/attendee/waitlist 與舊 `event_bookings` 如何一對一或一對多對應，仍需設計，不得讓新舊預約流程各自計數造成超賣。
- API 候選 `/occurrences/{id}` 可在 Laravel route 解析至 `calendar_events.id`；既有 `/events/{id}` 路徑不得破壞。
- 不得在確認真實 DB、S8 合併狀態、回退策略前對任何 App 執行 Migration。

### 12.4 針對方案 A 的資料驗證 Gate

- [ ] S5 舊活動數與 S6 舊預約數在 migration 前後一致，原 FK 全數有效。
- [ ] 舊預約可繼續透過原 API 查詢、取消，並沿用唯一索引保護。
- [ ] 單次活動 `series_id=NULL`；週／月活動各場次 `calendar_events.id` 不變且生成不重複。
- [ ] 同場次多人報名按實際 attendee 計席次，舊單人報名與新群組報名合計不超容量。
- [ ] 同場次取消、遞補、活動取消與改期併發不超賣、不錯誤遞補。
- [ ] 原 `location` 值保留；`floor`、`studio` 均可空且不影響原預約唯一索引。
- [ ] 已開始／已結束場次與單次例外符合 L01 A，不因重新展開系列被覆寫。
- [ ] 測試 rollback 不刪舊活動、舊預約或已發生的出席稽核。

### 12.5 本輪待使用者決策

**S9-L05（尚未核准）**：A＝沿用 `calendar_events` 作為實際場次權威，新增 `event_series` 與必要欄位；B＝另建 `event_occurrences` 並處理雙 ID/backfill。建議 A。

本輪僅修改規劃文件；不修改 Laravel、Migration、OpenAPI、CI、PR 或部署。

## 13. v0.4 L05 A 核准後的場次模型收斂與 M08 席次整合規劃（2026-10-25）

### 13.1 決策優先序與草案修訂聲明
**S9-L05 A 已在 `docs/s9_decisions.md` 正式核准**：`calendar_events` 是每個實際場次的唯一權威，不另建 `event_occurrences`。因此本文件第 1、2、8、9 節先前提出的 `event_occurrences` 表、`occurrence_id` FK、`/occurrences/{id}` URI 均屬**歷史候選草案，不能直接實作**；本節為後續設計基準。對外 URI 是否仍使用 occurrences 語意須經正式 OpenAPI 核准，但 ID 必須映射既有 `calendar_events.id`。

### 13.2 確認／待審欄位分界

| 資料 | 已確認基線／業務規則 | 候選增量（待技術核准） |
|---|---|---|
| `calendar_events` | S5 的 `id`、`location NULL`、`starts_at/ends_at`、`status`、`audience`；S6 的 booking capacity/window | `event_series_id BIGINT NULL`、`series_original_start_at TIMESTAMP NULL`、`floor VARCHAR(255) NULL`、`studio VARCHAR(255) NULL`、`revision INT` |
| `event_series` | M09 週／月、單次／未來／全部、L01 歷史保護 | `frequency`、`timezone`、`local_start_time`、`recurrence_rule`、`until_at`、`status`；時區／DST 與月份不存在日期策略須測試 |
| `event_bookings` | S6 `calendar_event_id` FK、`user_id` FK、`active` 與唯一索引保持 | 以現有 booking 作為會員單人預約的相容入口；群組／訪客延伸如何掛接仍待核准 |
| `event_booking_groups`、`event_booking_attendees`、`event_waitlist_entries` | M08 多人按席次、整組 FIFO、每場次獨立 | 統一以 `calendar_event_id` 關聯；是否以 group FK 指向 legacy booking 待確定 |
| `event_attendances` | M11 每實際參加者獨立簽到 | `calendar_event_id` + `attendee_id` unique；legacy 單人 booking 的 attendee 映射待確定 |

### 13.3 避免雙計數／超賣的建議實作契約

1. **單一容量權威**：讀取同一 `calendar_events.booking_capacity`；每個有效 legacy 單人 booking 應視為 1 席，每個有效群組以有效 attendees 數計席次。
2. **互斥表示，禁止重複計數**：若某筆舊 `event_bookings` 被新群組模型「包裝」為 attendee，計數必須只計一次；建議用明確的 `legacy_booking_id` 參照或互斥來源標記，具體 unique/FK 待審。
3. **交易入口收斂**：既有 `BookingService.book/cancel` 與新群組／候補服務都必須先鎖同一 `calendar_events` row；確認容量、狀態、取消期限及 FIFO 後，在交易內提交席次變更。
4. **逐步回填**：優先以相容讀取 legacy booking + 新 attendees 的單一統計查詢，不預先假設必須回填全部舊資料；如為簽到需要補 attendee，必須採可重跑、unique 防重複且可驗證的資料修復流程。
5. **取消整場活動**：場次取消後終止所有有效報名／候補；不得因取消釋放席次觸發遞補。
6. **保護唯一索引**：不移除 `(calendar_event_id,user_id,active)`；群組代報名／訪客不得繞過同會員同場次重複預約的已核准規則，具體唯一性範圍待契約確認。

### 13.4 單次例外與系列生成的候選約束
- `calendar_events.id` 一旦產生即不因改期重建；單次例外只修改既有場次或保存例外覆寫資料。
- 對已展開場次，候選 `UNIQUE(event_series_id,series_original_start_at)` 防止重複產生；無系列的單次活動允許 NULL。
- 每週／每月依系列時區產生場次，UTC 儲存實際起訖；須測試 DST 與每月 29／30／31 日。
- L01 A：future/all 不覆寫已開始或已結束場次，也不覆蓋已建立單次例外。
- 取消單次場次保留 `calendar_events` row；已取消場次是否繼續占用 recurrence slot 須確保不被重新生成。
- 生成未來場次的期間上限、排程時機、例外資料表是否必要，尚未核准。

### 13.5 本輪追加的驗收測試
- [ ] Legacy 會員預約 1 席 + 新群組 2 席，容量 3 時剛好滿額；再報名 1 席不得超賣。
- [ ] 同一筆 legacy booking 經 attendee 映射後仍只占 1 席。
- [ ] Legacy 取消與群組報名同時進行，鎖定順序一致、容量正確。
- [ ] 群組取消與候補整組遞補同一交易，不發生插隊。
- [ ] 重複系列生成重跑，不產生相同原始場次的第二個 `calendar_events.id`。
- [ ] 單次例外改期後，既有 booking FK 與 check-in 關聯不變。
- [ ] 取消場次不被後續系列生成工作重新建立。
- [ ] 原 API、舊唯一索引及 nullable 場地欄位回歸測試通過。

### 13.6 下一步仍需技術核准
M08 legacy booking 與 group/attendee 的唯一表示方案、場次生成上限、系列時區規則、實際 API JSON Schema、Google 重試參數與部署回退，仍待完整整合草案一次審查；**L05 A 不等於授權開發**。

## 14. v0.5 M08 舊單人與新多人報名統一模型方案（2026-10-25）

### 14.1 原始碼與已核准邊界
- S6 現有 `event_bookings` 以 `calendar_event_id,user_id,active` 限制每會員同場次一筆有效 booking；`BookingService.confirmedCount()` 目前只計 confirmed booking 列數。
- S9-L05 A 已核准 `calendar_events` 為實際場次唯一權威；M08 已核准多人實際參加者、整組候補、嚴格 FIFO、訪客報名；本節僅提出**資料表示與遷移技術候選**。
- 必須避免兩套 booking 流程各自扣名額、同會員重複報名、候補插隊及資料雙重計數。

### 14.2 三種候選資料模型比較

| 方案 | 方式 | 優點 | 風險／代價 | 建議 |
|---|---|---|---|---|
| **A：現有 booking 作為會員訂單錨點** | `event_bookings` 保留單會員已確認報名及唯一索引；新增 `event_booking_groups` 作為多人／訪客／候補交易主體，`event_booking_attendees` 每人一列；會員群組透過可空 `event_booking_groups.legacy_booking_id` 對應既有 booking，計數以參加者為權威並對 legacy 未映射資料補計 | 舊 API／FK 保留，能逐步導入 | 一筆 booking 代表訂單或一位會員須明確界定；legacy 與 group 關係若不嚴謹可能雙計數 | **待審** |
| **B：現有 booking 擴成所有訂單主體** | 在 `event_bookings` 增加可空 user_id、guest、group、waitlist 欄位，attendees 為子表 | 單一訂單表 | 既有 user_id NOT NULL、confirmed/cancelled 與唯一索引語意須改；高相容風險 | 不建議 |
| **C：新 group 完全取代舊 booking** | 全量回填舊 booking，改所有服務與 API 指向 group | 新模型一致 | 高風險一次切換、雙 ID、回退困難 | 不建議 |

### 14.3 方案 A 仍需先解決的五項關鍵不變量
1. **單一有效報名來源**：同會員同場次若有 legacy booking 與 group，須確保是同一筆邏輯報名，不能形成兩筆有效席次；同一 legacy booking 最多連結一個 group（候選 `UNIQUE(legacy_booking_id)`）。
2. **席次單一計數**：`confirmed_seats = count(confirmed legacy bookings without mapped group) + sum(confirmed attendees of mapped/new groups)`；同一 attendee 不可重複占位，候補不計容量。此公式是候選，需在 DB transaction 下驗證。
3. **會員重複保護**：會員本人在 group 中擔任 booker 或 attendee 的重複資格如何處理，必須依既有 M08「一位會員一次活動」規則審查；不能只靠舊 `event_bookings` 唯一索引推定所有群組成員皆受保護。
4. **原子取消與候補**：取消 group、legacy booking 或活動時必須走同一場次鎖定及遞補引擎；活動取消不執行遞補。
5. **訪客與稽核**：訪客可以沒有 user_id，但應有受保護的聯絡資料與可追溯 group；claim 成為會員後不可讓同場次原有會員 booking 與 guest group 重複有效。

### 14.4 建議的無破壞遷移階段（僅規劃）
- **階段 0：核對**：確認 Shared/Tiandi 實際 HEAD、S8 是否合併、既有預約與會員關聯資料，建立筆數／FK／唯一性基線。
- **階段 1：加法**：新增 groups、attendees、waitlist 及必要索引，不改舊 `event_bookings.user_id NOT NULL` 或舊 unique。
- **階段 2：相容讀取**：以統一服務計算席次，識別 legacy-only booking；只在需要時建立其 attendee 映射，採唯一鍵冪等避免重複。
- **階段 3：統一寫入**：所有新舊 API 轉向同一場次鎖與容量／FIFO 服務，避免並行雙套寫入。
- **階段 4：驗收／切換**：在測試資料完成舊新並行／回退驗證；各 App 另行核准 migration 與部署，不得自行正式切換。

### 14.5 必須通過的情境
- [ ] 會員舊 booking 一席與兩位新 group attendees 合計三席，容量三時無法再確認第四席。
- [ ] 舊 booking 建立 attendee 映射後，席次仍是一席而非兩席。
- [ ] 同會員從舊 API 與新 group API 同時報名，最多一筆有效邏輯報名。
- [ ] 訪客報名後 claim，與既有會員 booking 撞號時安全拒絕／引導管理員處理，無重複占位。
- [ ] 舊 API 取消與新 group 取消同時進行，無重複釋位／遞補。
- [ ] 群組取消後嚴格 FIFO，前組未滿席時後組不得插隊。
- [ ] 管理員取消整場活動，所有報名／候補停止且無自動遞補。
- [ ] migration rollback 後舊 S6 API、FK、唯一索引仍可運作。

### 14.6 待使用者核准
**S9-L06：舊／新報名資料模型**。A＝保留 `event_bookings` 為既有會員報名錨點，新增 group＋attendee＋waitlist，以單一交易／席次引擎統一；B＝直接擴充 `event_bookings` 為所有報名訂單；C＝全量改為新 group。**建議 A**，但需先完成上述五項不變量的技術契約。使用者未確認前不得將 A 視為正式決策。

本輪只更新文件，未開發、合併或部署。

## 15. v0.6 L06 A 核准後：單一席次帳本與遷移相容規格（2026-10-25）

### 15.1 核准事項與待核准技術細節
S9-L06 A 已正式核准：`event_bookings` 作為既有會員報名錨點，新增 group／attendee／waitlist，且所有預約入口共用交易、席次與 FIFO 引擎。第 14.2 節 B/C 方案僅保留歷史比較，不得再當作同等候選方案。以下資料表結構、服務實作與索引仍為**技術候選**，尚待 L04 一次整合審查。

### 15.2 單一席次計算規則（候選）

在同一 `calendar_events.id` 下，所有有效席次只能屬於一種計數來源：

- **Legacy-only**：尚未建立 group/attendee 映射的既有 `event_bookings` confirmed 且 active=1，一筆占一席。
- **Mapped group**：已建立 group/attendee 映射的會員預約，以 confirmed attendees 計席次；對應 legacy booking **不再額外計一席**。
- **Group-only**：訪客／多人 group 未對應 legacy booking，按 confirmed attendees 計席次。
- **Waitlist**：尚未轉正的候補 group/attendee 不占席次。
- 不得將已取消、已候補、已撤銷或已失效的報名列入已確認席次。
- 計算與寫入在鎖定同一 `calendar_events` row 的 transaction 中進行；`confirmed_seats + requested_seats <= booking_capacity`（容量 nullable 的既有語意須先核對 `CalendarEvent` Model）。

### 15.3 重要候選欄位與完整性約束

| 資料 | 候選欄位／索引 | 目的 |
|---|---|---|
| `event_booking_groups` | `calendar_event_id FK`、`legacy_booking_id BIGINT NULL UNIQUE`、`booker_user_id BIGINT NULL`、`guest_contact_id BIGINT NULL`、`status`、`seat_count` | legacy booking 最多對應一組 group；多人／訪客共用主體 |
| `event_booking_attendees` | `group_id FK`、`calendar_event_id FK`、`member_user_id BIGINT NULL`、`status`、`display_name` | 一人一列、每場次獨立；跨 group 同會員重複有效席次需額外交易檢查／唯一約束 |
| `event_waitlist_entries` | `group_id FK`、`calendar_event_id FK`、`sequence BIGINT`、`status`、`UNIQUE(calendar_event_id,sequence)` | FIFO 排序穩定；不得跳過首組 |
| `event_attendances` | `calendar_event_id FK`、`attendee_id FK`、`UNIQUE(calendar_event_id,attendee_id)` | M11 每實際參加者唯一簽到紀錄 |

**資料庫限制注意**：`UNIQUE(calendar_event_id,member_user_id)` 若套在所有 attendee 歷史列，會阻止取消後重新報名；若允許多筆取消歷史，應採 active nullable 標記＋唯一索引或獨立有效會員佔位表，並對匿名／訪客重複驗證另訂規則。不可僅靠應用程式查詢來保證併發唯一性。

### 15.4 新舊 API 統一寫入路徑（候選）

1. 以 `calendar_event_id` 載入並鎖定 `calendar_events`。
2. 驗證活動狀態、開放時間、會員／訪客資格與重複報名；同時確認 FIFO 首組是否阻擋直接插隊。
3. 以同一席次帳本計算 legacy-only 與新 group confirmed attendees。
4. 全組可確認則原子建立／更新 group 與 attendees；不足則依已核准 M08 候補規則整組入隊。
5. 取消時先鎖場次，再鎖 group/booking/waitlist，釋放席次並同交易遞補首組；取消整場活動則**不遞補**。
6. 提交後以 outbox 或具冪等性的背景工作發通知與 Google 同步；失敗不回滾已成功預約。
7. 既有 S6 API request/response 應維持原有語意與狀態碼；新群組 API 的 JSON Schema 待審。

### 15.5 防止資料重複／不一致的驗收條件

- [ ] legacy booking 建立 group 映射前後，席次數完全相同。
- [ ] 同會員透過 legacy API 和新 group API 同時預約，最多一筆有效邏輯報名。
- [ ] 同一 group 重試不新增第二組／第二批 attendees；可用 request-id 冪等鍵，實際保存方式待審。
- [ ] 同一場次兩個並發 group 搶最後席次，confirmed 總席次不超容量。
- [ ] FIFO 首組不足席時後組不得遞補；候補順序不因 retry 改變。
- [ ] 取消單人 booking 與 group 取消不會雙重釋放同一席次。
- [ ] 取消整場活動後，不會遞補任何候補組。
- [ ] 訪客認領會員後，若同場次已有有效 booking，必須阻止重複占位並保留稽核。
- [ ] S6 `UNIQUE(calendar_event_id,user_id,active)`、原 FK、舊路徑回歸測試全部通過。

### 15.6 下一輪應核對的實際程式
- `CalendarEvent`、`EventBooking` Eloquent models 的 capacity、booking close time、關聯與 cast。
- S6 controller/routes/OpenAPI 的完整回傳狀態與錯誤碼。
- S8 Shared/Tiandi 的 HEAD 與未合併差異，避免在錯誤基線設計 API。
- Laravel 的現有審計／通知機制，避免新建第二套。
- 然後完成九模組 OpenAPI 3.1 完整 JSON Schema 與各表 up/down 草案，提交 L04 一次技術審查。

**本輪僅更新 GitHub 規劃文件，沒有開發、修改 Migration／OpenAPI、合併 PR 或部署。**

## 16. v0.7 S5／S6 Laravel Model、Controller、Routes 相容性核對（2026-10-25）

### 16.1 已核對實際來源（Shared main）

- `examples/poc_backend/app/Models/CalendarEvent.php`：`MAX_RANGE_DAYS=62`、`MAX_RESULTS=200`；`bookingClosesAt()` 為自訂截止或 `starts_at`；booking_enabled 時 capacity 至少 1；`listed()` 包含 published/cancelled；`cancel()` 保留 ID 與取消歷史。
- `examples/poc_backend/app/Models/EventBooking.php`：僅 confirmed/cancelled，`isConfirmed()`、`event()`、`user()`；沒有現成群組／候補語意。
- `examples/poc_backend/routes/api.php`：`/api/v1` 由 bootstrap 設定；會員事件及 S6 預約位於 `auth:sanctum` + `member.verified`；公開事件另外位於 `/public/events`。
- `examples/poc_backend/app/Http/Controllers/Api/CalendarEventController.php`：from/to 必填，ISO 8601 帶 offset 或日期，日期視 UTC 00:00；區間 `[from,to)`；最多 62 天，200 筆，超出以 `meta.truncated` 提示；公開查詢僅 public audience。
- `examples/poc_backend/app/Http/Controllers/Api/BookingController.php`：原 POST /events/{id}/bookings 無請求 body，201 回傳 booking info；GET /events/{id}/booking 同格式；GET /me/bookings 每頁 20；取消自己的 booking，非本人 404；info 中 `booked_count` 目前等於 confirmed booking row 數。

### 16.2 S9 API 相容契約（待完整 OpenAPI 定稿）

| 舊路徑 | 不得破壞的語意 | S9 擴充候選 |
|---|---|---|
| `GET /events/{id}/booking` | `data.event_id,enabled,capacity,booked_count,remaining,opens_at,closes_at,unavailable_reason,my_booking` | `booked_count` 將代表**實際確認席次**，以符合容量語意；此變更對既有客戶端是否視為行為變更須核對測試 |
| `POST /events/{id}/bookings` | 無 body 單會員預約、201、相同 booking info | 維持 legacy request；新多人流程另設 URI 或明確可選 body，待 OpenAPI 一次核准 |
| `GET /me/bookings` | 含 confirmed/cancelled 歷史，依 booked_at/id 倒序，每頁 20，meta pagination | 多人與候補是否另提供列表，不能默默改變舊 DTO |
| `POST /me/bookings/{id}/cancel` | 只允許本人，其他 404，成功回傳 booking info | 群組代報名取消另設權限規則；內部共用場次鎖與遞補 |
| `GET /events`、`GET /public/events` | from/to 必填、62 天、最多 200、meta.truncated、公開權限過濾 | 週／月重複活動應先 materialize 場次，再以既有區間查詢，不能無限制展開 |
| `GET /events/{id}`、`GET /public/events/{id}` | drafts 不可見，cancelled 可見；public 僅 public audience | VIP/private 規則須後端過濾，不得讓既有 public 查詢外洩 |

### 16.3 發現的兩項設計陷阱

**陷阱 1：`booked_count` 從筆數轉為席次**。S6 目前 `BookingController::info()` 以 `confirmedCount()` 的 booking row 數當 `booked_count`。S9 多人上線後，若保留筆數語意，`remaining` 會錯；若改為席次語意，原 DTO 欄位名稱不變但數值可能不同。建議明定 `booked_count` 是「已確認實際參加者席次」，並以回歸測試驗證舊單人活動數值不變；新欄位如 `booking_group_count` 應另行定義，不可混用。

**陷阱 2：L02 改期期限**。目前沒有「截止時間是相對／自訂」的明確旗標：`booking_closes_at=NULL` 表示跟隨 `starts_at`；非 NULL 為明確截止。若 S9 要支援「活動前 N 小時」相對期限，必須新增可識別的 deadline mode/offset；不能僅用時間差猜測管理員是否自訂。

### 16.4 建議驗收案例

- [ ] 舊無 body 預約 POST 仍回 201 與既有欄位。
- [ ] 舊單人活動 `booked_count` 與 `remaining` 值不變；多人活動則按實際席次一致計算。
- [ ] `/me/bookings` 舊 20 筆分頁、排序、取消歷史不變。
- [ ] 其他會員取消路徑仍為 404，不洩漏是否存在該 booking。
- [ ] 會員驗證不足時仍被 `member.verified` 拒絕；訪客另走受限驗證路徑。
- [ ] `/public/events` 不能取得 members/VIP/private 資料。
- [ ] 重複活動場次查詢 62 天／200 筆與 `meta.truncated` 保持一致。
- [ ] `booking_closes_at=NULL` 改期後自動跟隨新 `starts_at`；非 NULL 自訂截止保留並提醒。
- [ ] 若新增相對 offset 截止，改期後正確重算，與明確自訂期限分離。
- [ ] 活動取消保留原 `calendar_events.id`，不刪除已存在的 booking FK。

### 16.5 下一輪需要完成的契約審查

1. 對照 S8 尚未合併分支的 OpenAPI 與 Flutter DTO，避免只以 Shared main v0.6.0 為最終契約。
2. 明確定義 `booked_count` 席次語意及 `deadline_mode` 的 Schema。
3. 實作前核准新群組／訪客／候補 API 的完整 JSON Schema、Policy、錯誤碼與冪等性。
4. 再核對現有 AuditEvent、通知與測試架構，避免重複造輪子。

**本節為實際程式碼核對與待核准契約，未修改任何程式、Migration、OpenAPI、PR 或部署。**

## 17. v0.8 S8 未合併 OpenAPI 契約核對與 DTO 相容矩陣（2026-10-25）

### 17.1 實際核對來源與限制
- 已讀取 Shared `feat/s8-m05-articles:contracts/openapi.yaml`，OpenAPI 3.1.0，`info.version=0.7.0`。此分支尚未視為已合併正式主線。
- 舊 S6 四條路徑仍存在：`GET /events/{id}/booking`、`POST /events/{id}/bookings`、`GET /me/bookings`、`POST /me/bookings/{id}/cancel`。
- OpenAPI `x-dart` 明確標示 `BookingApi.info/book/cancel -> BookingInfo`、`BookingApi.mine -> List<Booking>`、`BookingInfo.fromJson`。**這是契約標記，不等於已核對 Flutter DTO 實際檔案**；本輪未定位到 DTO 原始碼，不能聲稱完成 Flutter 實作核對。
- S8 OpenAPI `POST /events/{id}/bookings` 沒有 `requestBody`；成功 201；四條預約 API 均標 `x-verification: required`。
- `GET /me/bookings` 保留 20/page、包含 confirmed/cancelled，meta `current_page,last_page,total`。

### 17.2 BookingInfo OpenAPI 既有 JSON 契約

| 欄位 | 目前 Schema | S9 保護要求 |
|---|---|---|
| `event_id` | required string | 保留；實際場次 ID 仍是 `calendar_events.id` |
| `enabled` | required boolean | 保留 |
| `booked_count` | required integer | 目前單人時為 confirmed booking 列數；S9 必須統一實際席次並核對 DTO |
| `unavailable_reason` | required nullable enum | 保留 `already_booked,event_not_published,event_cancelled,disabled,not_open,closed,full,null`；新狀態不得無審查直接加到舊 enum |
| `my_booking` | required Booking 或 null | 保留舊單會員預約型別；群組資料可另設 DTO |
| `capacity`、`remaining` | nullable integer | 保留 nullable 型別 |
| `opens_at`、`closes_at` | nullable date-time string | 保留 UTC 日期格式；close 預設 event start |
| `Booking.status` | `confirmed|cancelled` | 不把 `waitlisted` 直接塞入舊 enum；候補另用新 DTO |

### 17.3 兼容性裁決（本輪技術建議，非核准）
1. **舊 API 不擴張狀態 enum**：原 `Booking.status` 僅 confirmed/cancelled；S9 候補使用新 `BookingGroup`/`WaitlistEntry` DTO，避免舊 Flutter switch/fromJson 不識別。
2. **舊 POST 不改 request**：`POST /events/{id}/bookings` 保持空 body 單人報名；新群組另走候選 endpoint，避免無意改變舊行為。
3. **數量語意**：`booked_count` 與 `remaining` 應統一使用實際 confirmed seats；對舊純單人資料數值完全不變。需要明確回歸測試以判定是否構成既有客戶端行為變更。
4. **期限欄位**：舊 `closes_at` 保留；S9 新增管理端候選 `deadline_mode=event_start|relative_offset|fixed` 與 `offset_minutes`，不得在舊 BookingInfo 強加 required 欄位。
5. **公開／訪客**：S8 預約仍在 verified member middleware；M08 訪客報名使用獨立驗證流程，不能將舊 API 移出 middleware。
6. **身份與權限**：M03 業務驗證不等於可以繞過 S6 `member.verified`；如需其他認證狀態，另設 Policy。

### 17.4 下一輪必查原始碼與 Gate
- [ ] 定位並讀取 Flutter `BookingInfo`、`Booking`、`BookingApi` 原始碼（Shared/Tiandi 兩 Repo），驗證 `fromJson` 對未知欄位／enum 的處理。
- [ ] 讀取 Shared S8 分支與 Tiandi S8 分支的實際 HEAD、API／Flutter 客戶端相容測試。
- [ ] 明確審查 `booked_count` 轉席次語意的相容性與新群組 API 入口。
- [ ] 在完整 OpenAPI 3.1 Schema 中寫入 `deadline_mode`、group、attendee、waitlist 的欄位、錯誤碼與權限。
- [ ] 用測試驗證 legacy 單人 booking 原 JSON 不變、群組另用 DTO、訪客不能繞過驗證。

**注意**：本節只確認 S8 OpenAPI 契約，不代表 Flutter DTO 原始碼或 Tiandi 整合已審完；不修改功能程式、OpenAPI、Migration、PR 或部署。
