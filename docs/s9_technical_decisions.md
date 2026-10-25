# S9 技術裁決紀錄（Claude）｜九模組整合開發

> 2026-10-25｜依 `docs/s9_claude_development_handoff.md` §3 授權，由 Claude 裁決技術設計。**業務決策以 `docs/s9_decisions.md` 為準**（D／INT／L 決策不更動）。本文件記錄六項技術問題的選擇、資料主責、回退與驗收方式；完整 API 見 `contracts/openapi.yaml`（0.8.0），資料表見 `database/migrations/2026_11_01_*`。

## 0. 基線（開始時查核）

| 項目 | 值 |
|---|---|
| Shared main | `b0808af`（S7 合併） |
| Shared S8 | PR #20 `0e16f59`，**未合併**；S9 分支 `feat/s9-nine-modules` 由此建立（stacked） |
| Tiandi main | `198d1fb`（S7 合併） |
| Tiandi S8 | PR #17 `5b071ae`，**未合併**；釘選 Shared `0e16f59` |
| S9 決策文件 | `docs/s9-approved-decisions-20261024` 分支（`d2e1e93`），已併入 S9 分支 |

合併順序：Shared #20 → Tiandi #17 → Shared S9 → Tiandi S9。S9 PR 在 S8 合併前的 diff 會包含 S8 commit。

## 1. 共通原則（實作採用）

- 新增全部為 **additive migration**：只新增表與 nullable／有預設值的欄位，不改 S5/S6/S8 既有欄位、FK、唯一索引；`down()` 只移除本批新增物件。
- 單一權威：場次＝`calendar_events`（L05）；席次＝`event_booking_attendees`（L06，見 §3）；內容權限＝既有 `ArticleAccess`／活動 audience 規則，透過 `ContentRegistry` 統一呼叫；連結＝M14、統計＝M17、行銷＝M15。
- 跨模組只用「內容類型 allowlist + ID」（INT-D07）：`article`、`event`、`notice`；不存 PHP class 名、不建跨 App FK。
- 稽核沿用 M07 `AuditEvent`；站內通知沿用 M04 `notifications` 表；Email 沿用 Laravel Mail；背景作業沿用 Laravel Queue（INT-D08）。
- 附屬功能失敗不阻斷主流程（INT-D11）：通知、Google 同步、點擊統計在交易提交後執行，失敗只記錄。
- 不新增外部服務／基礎設施。Flutter 新增套件（掃碼、QR 顯示、分享、定位、外部連結、App Links）皆為開源套件，無付費服務。

## 2. 六項技術裁決

### T1 訪客驗證與認領（M08-D06/D18/D23/D24）

- 訪客由網頁 `/events/{id}` 的報名表單（M14 Web fallback 頁）報名：姓名＋Email 或手機（至少一項），不需要先驗證，也**不發任何 Email／簡訊**（避免付費簡訊與未授權寄信）。
- 認領：會員登入後，`GET /me/guest-bookings` 只列出「報名聯絡 Email／手機」與**本人帳號中已驗證**的 Email／手機完全相同的訪客報名；`POST /me/guest-bookings/{id}/claim` 再次在交易內比對後綁定。原聯絡方式的所有權驗證＝既有 S1/S3 的 Email 驗證連結或手機 OTP（無新成本）。**不接受姓名或報名編號認領**。
- 防冒領：只比對已驗證聯絡方式；比對以正規化（Email 小寫、手機 E.164）完成；認領端點 `throttle:auth-10`；所有認領寫入稽核。
- 未註冊時的通知：寫入同一 `notifications` 表，`notifiable` 為報名群組（morph），**不寄送**；認領時轉移給會員（D23）。
- 個資保存：訪客聯絡資料存於報名群組，隨報名紀錄保存；M11 保存期限清理時不刪報名，只刪簽到資料（M11-D28 的範圍是簽到個資）。

### T2 席次唯一性與舊資料回填（L06 A）

- 保留 `event_bookings` 與 `UNIQUE(calendar_event_id,user_id,active)`。
- 新增 `event_booking_groups`（一次報名／一組）、`event_booking_attendees`（每位實際參加者一列）、`event_waitlist_entries`（候補佇列）。
- **唯一席次帳本＝`event_booking_attendees.status='confirmed'` 的筆數**。舊 API 報名也同時建立 group（`legacy_booking_id` UNIQUE）＋1 位 attendee，因此新舊入口只有一套計數。
- 回填：migration 對每筆既有 confirmed `event_bookings` 建立 group＋attendee（以 `legacy_booking_id` UNIQUE 冪等，可重跑），執行後驗證「confirmed bookings 數 = 對應 attendee 數」，不一致即中止。
- 會員唯一性：`event_booking_attendees` 有 `UNIQUE(calendar_event_id,member_user_id,active)`（active=1 表示確認或候補中，取消後 NULL），舊、新、候補三種入口都由資料庫保證同會員同場次只占一個位置。
- 鎖定順序統一：所有寫入先 `SELECT … FOR UPDATE` 鎖 `calendar_events` 列，再鎖 group／booking（修正 S6 取消時只鎖 booking 的順序）。
- FIFO：候補序號在場次鎖內以 `MAX(sequence)+1` 產生，並有 `UNIQUE(calendar_event_id,sequence)`。遞補只看佇列首組；首組席次不足即停止（D17/D22）；永久性失敗（帳號停用／刪除）才標記 `skipped` 並記錄原因後看下一組（D09）；任何例外整筆回滾，不跳號。
- 冪等：新報名 API 需 `client_request_id`（UUID），`UNIQUE(client_request_id)`；重送回傳原結果。
- 回退：`down()` 刪除三張新表；舊 `event_bookings` 從未改寫，舊 API 立即恢復 S6 行為。

### T3 改期期限（L02 A）

- `booking_closes_at`：NULL＝跟隨開始時間（原語意不變）；非 NULL＝固定時間（**不猜成相對**）。
- 新增 `booking_close_offset_minutes`（相對：開始前 N 分鐘）、`cancel_closes_at`（固定取消期限）、`cancel_offset_minutes`（相對取消期限）。優先序：相對 offset > 固定時間 > 跟隨開始。期限一律在讀取時計算，改期後相對期限自動重算。
- 改期時若存在固定期限，Filament 顯示警示並寫入稽核 `event.rescheduled`（含 `fixed_deadline_kept`）；同時依 M08-D11 通知已報名與候補聯絡人。

### T4 Google 日曆同步（M09-D04/D06/D07/D11–D18/D24/D25、L03 A）

- 無新套件：以 Laravel HTTP client 呼叫 Google OAuth 2.0 與 Calendar API v3；scope 僅 `https://www.googleapis.com/auth/calendar.app.created`（只能存取本 App 建立的日曆）。
- 連線：`POST /me/google-calendar/connect` 回傳授權網址（`state` 為 10 分鐘一次性快取值）；回呼 `/oauth/google/callback` 交換 token，refresh token 以 Laravel `encrypted` cast 存放，建立 App 專用日曆（D17），每會員一個連線（`UNIQUE(user_id)`，D16）。token 不回傳給 App、不寫日誌。
- 同步內容：我的確認報名（每會員×每場次一筆，D25）及有效 VIP 私人行程（D07）；首次連線只同步現在與未來（D24）。私人行程 `visibility=private`（D15）。
- 冪等：Google event id 由 (`connection`, 類型, 來源 id) 決定性產生（base32hex），重試遇 409 改為更新；`google_calendar_event_mappings` 以 `UNIQUE(connection_id,source_type,source_id)` 保證一筆。作業執行時讀取**最新**資料決定建立／更新／刪除，舊作業不會覆寫新改期。
- 重試：Queue job `tries=5`、backoff 1/5/15/60 分鐘；仍失敗標記 `failed` 顯示於 Filament，管理員可手動重試（L03）。同步失敗不影響報名（INT-D11）。
- 解除連結（D13）：刪除本地 token、停止同步，保留 Google 事件。VIP 失效（D18）：作業執行前重查資格，私人行程不再同步，既有 Google 事件保留。
- **BLOCKED**：未設定 `GOOGLE_CALENDAR_CLIENT_ID/SECRET/REDIRECT_URI` 時連線 API 回 503 `GOOGLE_NOT_CONFIGURED`；自動化測試僅以 `Http::fake` 驗證，**未做真實 OAuth**。

### T5 多型內容與失效收藏（M16/M19/M14/M17）

- `App\Support\ContentRegistry`：`article`、`event`、`notice` 三種類型的 allowlist；提供「是否存在／訪客或會員可否看見／摘要／網址」，內部呼叫 `ArticleAccess`、活動 audience、公告規則，權限只在後端判斷。
- `bookmarks`、`taggables`、`link_*`、`marketing_campaigns.target_*` 對內容**不建 FK**（內容刪除後收藏保留不可用佔位，M19-D21），以 `content_type + content_id` 關聯；會員 FK `cascadeOnDelete`（M19-D22 帳號刪除清收藏）。
- 不可用佔位只回傳 `{content_type, content_id, available:false}`，不回傳標題、封面、地點。
- 搜尋使用 MySQL `LIKE`＋關鍵字加權（標題 3／摘要 2／內文 1），多關鍵字 AND，不引入搜尋引擎（M19-D24/D29）。
- 1,000 筆上限（含佔位，D25）：新增收藏時在交易內 `SELECT users … FOR UPDATE` 後計數。

### T6 CI、部署與回退

- 沿用既有 GitHub Actions（PR 觸發，`contents: read`），新增：S9 併發腳本（100 並行搶席、舊＋新入口混合、FIFO）、migration rollback 演練（`migrate:rollback` 本批後再 `migrate`）。
- **不新增部署 workflow、不使用任何正式憑證**。INT-D06 B（自動合併部署）需要的部署目標、Secrets、分支保護尚未核實，因此本批只交付 PR＋CI；部署與合併由使用者決定。
- 回退：各 App 以 `php artisan migrate:rollback --step=1`（只回退 S9 migration）回到 S8 結構；新表資料會被刪除，舊 S5/S6/S8 資料不受影響。正式環境執行前需備份。

## 3. 資料主責（INT-D09）

| 主責模組 | 資料 |
|---|---|
| M09 | `event_categories`、`event_series`、`calendar_events` 新欄位、`private_calendar_events`、`google_calendar_connections`、`google_calendar_event_mappings` |
| M08 | `event_booking_groups`、`event_booking_attendees`、`event_waitlist_entries`（`event_bookings` 舊錨點） |
| M11 | `checkin_locations`、`checkin_location_events`、`event_attendances` |
| M03 | `verification_types`、`verification_requests`、`verification_evidence`、`verification_reviews`、`member_verifications` |
| M16 | `tags`、`taggables` |
| M19 | `bookmarks` |
| M17 | `link_clicks`、`link_daily_stats` |
| M15 | `marketing_placements`、`marketing_campaigns`、`marketing_campaign_placement` |
| M14 | 無新表：沿用內容固定 ID、Web route、`config/deeplinks.php` |

## 4. 已知不做／BLOCKED

- 真實 Google OAuth、Android App Links（`assetlinks.json` 需正式網域與簽章）、iOS Universal Links（需 Team ID）、實機掃碼／GPS：程式與設定位置已完成，**實機與真實憑證驗收 BLOCKED**，需使用者提供網域／憑證後驗收。
- 重複活動「改變週期本身」（例如每週改每月）不在 MVP：需結束系列再建立新系列；單次／此次及未來／全部可修改時間、地點、內容、報名設定。
- M15/M17 依 D30 上線後觀察驗證；CI 仍跑自動化測試。

## 5. 實作時追加的技術裁決

- **Flutter 套件（新增 6 個，皆為 S9 決策必要功能）**：`mobile_scanner`（M11 掃 QR）、`qr_flutter`（M11 會員入場碼／工作人員活動碼）、`geolocator`（M11 選用 GPS）、`share_plus` ^13（M14 分享；^11 與 flutter_secure_storage 11 的 win32 版本衝突）、`app_links`（M14 App Links 冷／熱啟動）、`url_launcher`（M09 Google 授權頁、M15 外部 https 連結）。全部透過 provider 包裝（`lib/core/device/device_services.dart`），Widget 測試以假實作覆寫，不需實機。
- **App Links 網域**：以編譯參數 `APP_LINK_HOST`（Dart）與 Gradle `-PappLinkHost=`（Manifest placeholder）設定；未設定時 App 不監聽外部連結、Manifest 使用 `app-links.invalid`，不會誤認領他人網域。
- **登入後返回（M14-D20）**：只允許 App 內固定路徑（`/articles|events|notices/{id}`、`/checkin/location/{token}`），以 `safeReturnPath()` 檢查，外部網址一律忽略。
- **併發重送冪等**：同一 `client_request_id` 的兩個請求同時抵達時，第二個在取得活動列鎖後重新查詢並回傳同一報名群組（不會出現 ALREADY_BOOKED）。CI 腳本以 10 組重送驗證。
- **稽核去識別（M11-D28）**：稽核資料表維持 append-only；保留期清除是唯一例外，以 query builder 移除 `attendee_id`、`reason`、`actor_id`、`subject_id` 並標記 `deidentified`。
- **MySQL 索引名稱**：超過 64 字元的複合索引／外鍵改用明確短名稱（`gcal_mappings_*`、`booking_attendees_member_active_unique`、`event_attendances_attendee_unique`）。

## 6. 資料庫遷移與回退方案（交付給使用者）

1. **遷移**：單一 migration `2026_11_01_000100_s9_modules.php`，只新增資料表與 `calendar_events` 可為 NULL／有預設值的欄位；不改名、不刪既有欄位、不改既有資料語意。
2. **舊資料回填**：同一 migration 內呼叫 `LegacyBookingBackfill::run()`，把每筆 S6 `event_bookings` 建成一個 1 人報名群組＋參加者席次（以 `legacy_booking_id` 唯一鍵保證可重跑、不重複），並核對筆數；不一致即中止。
3. **部署前**：備份資料庫 → `php artisan migrate --force`（已核准的 migration）→ 確認 `event_bookings` 筆數＝`event_booking_groups.legacy_booking_id` 筆數。
4. **回退**：`php artisan migrate:rollback --step=1 --force` 刪除 S9 新表與 `calendar_events` 新欄位，S1–S8 資料不受影響；S9 期間新增的團體報名、候補、簽到、收藏、標籤、統計、行銷資料會一併刪除（需先備份）。回退後部署 S8 版本程式。
5. **CI 演練**：`S1 PoC Backend` workflow 的「S9 migration rollback drill」在拋棄式 MySQL 8.4 上實際執行 rollback → 結構檢查 → 重新 migrate → 回填筆數檢查。
