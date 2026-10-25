# S9 九模組決策符合性矩陣

> 2026-10-25 第二輪複審版。逐項對照 `docs/s9_decisions.md` 的 265 項已確認決策（含 M11-D24 裁決 A）與 GitHub 上的程式和測試。**CI 全綠只代表標示 PASS 的項目在 CI 環境的自動測試通過，不等於使用者驗收**：真實 Google、App Links／Universal Links、iOS、實機 QR／GPS、正式部署都還沒有驗收。

**狀態說明**
- **PASS**：已實作，且有自動測試在 CI 實際執行通過（Mock 外部服務的測試另外註明）。
- **NOT TESTED**：已實作或屬於設計約束，但沒有專屬自動測試，或必須實機驗收。需人工審查或實測。
- **PARTIAL**：只完成一部分，缺口寫在「實作」欄。
- **BLOCKED**：需真實憑證、網域、簽章、實機或部署環境。
- **N/A**：交付或驗收策略，不是程式功能（例如自動合併部署，目前未授權）。

**路徑縮寫**
- `BE/`＝`examples/poc_backend/app/`
- `FL/`＝`examples/poc_flutter/lib/`
- `T/`＝`examples/poc_backend/tests/Feature/S9/`
- `Flutter（s9_screens_test）`＝`examples/poc_flutter/test/s9_screens_test.dart`
- `Dart smoke`＝`packages/flutter/shared_core/test/s9_api_smoke.dart`

Tiandi 以 `shared/SHARED_VERSION` 鎖定相同 Shared commit，並同步相同程式與測試。

## 總覽

| 模組 | 項數 | PASS | NOT TESTED | BLOCKED | N/A |
|---|---|---|---|---|---|
| S9 共通決策 L01–L06 | 6 | 5 | 1 | 0 | 0 |
| S9 整合決策 INT | 18 | 3 | 10 | 1 | 4 |
| M03 身分認證 | 12 | 12 | 0 | 0 | 0 |
| M08 活動報名 | 24 | 24 | 0 | 0 | 0 |
| M09 行事曆 | 25 | 23 | 1 | 1 | 0 |
| M11 簽到 | 30 | 29 | 1 | 0 | 0 |
| M14 Deep Link | 30 | 16 | 11 | 3 | 0 |
| M15 行銷 | 30 | 20 | 9 | 0 | 1 |
| M16 標籤 | 30 | 21 | 9 | 0 | 0 |
| M17 連結統計 | 30 | 20 | 9 | 0 | 1 |
| M19 搜尋與收藏 | 30 | 19 | 11 | 0 | 0 |
| **合計** | **265** | **192** | **62** | **5** | **6** |

## PARTIAL／BLOCKED（尚未完成或需外部條件）

- **M09-D04**（BLOCKED）外部日曆：GoogleCalendarClient（直接 API）
- **M14-D01**（BLOCKED）支援平台：Android intent-filter＋assetlinks 已就緒；iOS 提供 `Runner.entitlements.example` 模板與 `docs/s9_ios_universal_links.md`；正式 Team ID、簽章與實機未做
- **M14-D17**（BLOCKED）平台驗證：assetlinks／AASA 只在設定真實值後提供
- **M14-D24**（BLOCKED）驗收範圍：實機、LINE、瀏覽器、冷熱啟動驗收
- **S9-INT-D18**（BLOCKED）部署目標（A）：兩 repo CI 已核對；部署目標與執行環境需使用者提供存取後核實

## NOT TESTED（已實作，但需人工審查或實機驗收）

- S9 共通決策 L01–L06：S9-L04
- S9 整合決策 INT：S9-INT-D01、S9-INT-D02、S9-INT-D03、S9-INT-D05、S9-INT-D07、S9-INT-D08、S9-INT-D09、S9-INT-D12、S9-INT-D14、S9-INT-D17
- M09 行事曆：M09-D20
- M11 簽到：M11-D04
- M14 Deep Link：M14-D03、M14-D04、M14-D06、M14-D12、M14-D13、M14-D14、M14-D16、M14-D22、M14-D25、M14-D26、M14-D27
- M15 行銷：M15-D01、M15-D02、M15-D03、M15-D06、M15-D14、M15-D15、M15-D18、M15-D22、M15-D24
- M16 標籤：M16-D01、M16-D03、M16-D04、M16-D06、M16-D08、M16-D19、M16-D24、M16-D25、M16-D28
- M17 連結統計：M17-D01、M17-D02、M17-D12、M17-D14、M17-D15、M17-D16、M17-D17、M17-D18、M17-D26
- M19 搜尋與收藏：M19-D01、M19-D02、M19-D04、M19-D06、M19-D09、M19-D12、M19-D18、M19-D23、M19-D24、M19-D27、M19-D29

## M11-D24 正式裁決 A（2026-10-25）的落實

- 稽核紀錄 append-only：既有 AuditEvent 不刪除、不改寫（Model 禁止更新與刪除；清除作業只刪簽到資料表）。
- 寫入時去識別：簽到稽核不含參加者 ID、姓名、聯絡方式或更正原因；會員本人簽到不記帳號；工作人員與管理員記錄帳號，保留操作責任。
- 簽到個資（`event_attendances`）與管理員更正原因（`event_attendance_corrections`）依 `checkin_retention_days` 清除。
- 清除作業另寫一筆 `checkin.retention_purge`，內容為筆數、保存天數與截止時間。
- 測試 `T/Round2Test: keeps the audit log append-only, purges check-in data and leaves no way to re-identify attendees` 驗證四件事：
  - 清除前後既有稽核列逐欄相同；
  - 稽核內容不含任何姓名、Email、電話或原因字串；
  - 清除後 subject 與 correction 都無法再查回；
  - 清除事件為新增的一筆。

## S9 共通決策 L01–L06

| ID | 決策 | 狀態 | 實作 | 測試證據 |
|---|---|---|---|---|
| S9-L01 | 重複活動批次修改與歷史場次：已開始／已結束場次不受「此次及未來」批次修改；「全部」僅修改未開始場次，歷史保留；已建立的單次例外不被批次覆蓋。仍提供 M09-D19 … | PASS | BE/Services/Calendar/EventSeriesService.php（update scope future/all 排除已開始與例外） | T/M09CalendarTest: applies future and all changes only to not-started… |
| S9-L02 | 改期後報名／取消期限：保留原有已確認報名與候補；若期限原本是跟隨場次起始時間的相對期限（未自訂），隨新場次時間重新計算；若管理員自訂了絕對期限，則保留原設定並… | PASS | BE/Models/CalendarEvent.php bookingClosesAt/cancelClosesAt/fixedDeadlines；EventSeriesService 提醒 | T/M09CalendarTest: reschedules future occurrences…；M08BookingTest: enforces … cancel deadline |
| S9-L03 | Google 同步失敗：核心交易提交後進行背景同步；失敗時有限次自動重試，仍失敗則在後台顯示並允許管理員手動重試；同會員×同實際場次不得建立重複 Google … | PASS | BE/Services/Booking/BookingSideEffects.php（afterCommit）；Jobs/SyncGoogleCalendarItem.php tries 5；Filament GoogleSyncItemResource retry；固定 event id | T/M09GoogleSyncTest: marks failed items…；syncs a booking once…（Mock） |
| S9-L04 | API／Migration 草案核准方式：Claude 依已核准業務規則，先提出九模組**單一完整增量草案**，包含 URI、request/response Schema、資料… | NOT TESTED | docs/s9_api_migration_draft.md、contracts/openapi.yaml 0.8.0、單一 migration | CI: OpenAPI = routes 檢查 |
| S9-L05 | 實際活動場次權威資料表：**A：沿用既有 `calendar_events` 作為每個實際活動場次的權威資料表**；新增 `event_series`（重複系列… | PASS | migration：calendar_events 增量欄位＋event_series；無 event_occurrences | T/M09CalendarTest: generates weekly occurrences as real calendar events… |
| S9-L06 | M08 舊／新預約整合資料模型：**A：保留既有 `event_bookings` 作為會員預約相容錨點；新增報名群組、實際參加者及候補資料表，並由同一套交易鎖定、席次… | PASS | BE/Services/Booking/BookingEngine.php（唯一席次引擎）、LegacyBookingBackfill.php | T/M08BookingTest: counts S6 single bookings and S9 group attendees in one ledger；backfills…；CI S9 100 並行；T/Round2Test: keeps the S6 booking row and the S9 ledger consistent… |

## S9 整合決策 INT

| ID | 決策 | 狀態 | 實作 | 測試證據 |
|---|---|---|---|---|
| S9-INT-D01 | Shared 架構：Shared 僅共用可重用程式與契約，各 App 自主整合；Shared 不成為集中執行所有業務的獨立服務 | NOT TESTED | Shared 只提供程式與契約；Tiandi 以 shared/ 鎖定版本整合 | Tiandi CI（verify_shared.sh） |
| S9-INT-D02 | 資料庫隔離：各 App 各自管理資料庫、Migration 與會員資料；不共用跨 App 業務資料庫 | NOT TESTED | 各 App 自己的 migration 與資料庫；無跨 App 連線 | — |
| S9-INT-D03 | 模組相依：M14 負責連結、M15 負責行銷、M17 負責分析；其他模組透過明確介面整合，不重複建立連結或統計引擎 | NOT TESTED | M14 ContentRegistry::webUrl、M15 MarketingService 使用 M14 URL＋M17 LinkAnalytics | T/M14M15M17LinksTest: lists active campaigns…（utm＋M14 URL） |
| S9-INT-D04 | 權限管理：各 App 後端 Policy 為權威，模組權限獨立；不能僅靠 Flutter 隱藏操作按鈕 | PASS | 所有 API 在後端檢查權限（Policy／permission）；Flutter 隱藏只是輔助 | T 多處 403/404 測試（attendance、qr、bookmarks、verification evidence） |
| S9-INT-D05 | API 契約：先核對 GitHub 現有 HEAD 與 API 契約，再核准 Schema 與實作順序；不得直接將文件草案視為現有實作 | NOT TESTED | docs/s9_technical_decisions.md §0 基線核對 | — |
| S9-INT-D06 | 交付方式（使用者修訂為 B）：未來九模組全部一次開發，並採自動合併與部署；屬未來交付策略，**現在尚未授權開始開發、合併或部署** | N/A | 未授權自動合併／部署；PR 維持 Draft | — |
| S9-INT-D07 | 跨模組關聯：使用明確內容類型與 ID 或既有關聯契約，不新增跨 App 資料庫外鍵 | NOT TESTED | BE/Support/ContentRegistry.php（type＋id，無跨模組 FK） | — |
| S9-INT-D08 | 事件整合：優先使用 Laravel 既有事件、服務介面及必要同步 API，不導入大型訊息平台 | NOT TESTED | Laravel Queue／afterCommit／Notifications；無訊息平台 | — |
| S9-INT-D09 | 資料所有權：每類業務資料由單一主責模組管理，其他模組透過明確介面存取，不任意改寫彼此資料表 | NOT TESTED | docs/s9_technical_decisions.md §3 資料主責表 | — |
| S9-INT-D10 | API 版本相容：優先保持既有 API 契約相容，必要破壞性變更另行核准 | PASS | S6 API 保留原錯誤碼與回應；CalendarEventResource 只加欄位 | 既有 S6／S8 Pest 全數仍通過 |
| S9-INT-D11 | 相依服務失敗：優先保留可安全執行的核心操作，明確處理非核心功能故障；不讓附屬統計等功能無條件阻斷主流程 | PASS | LinkAnalytics 例外吞掉；Google 同步 commit 後背景；行銷失敗只隱藏 | T/M09GoogleSyncTest: marks failed items… without touching the booking；Flutter: M15 banner renders nothing when the placement fails |
| S9-INT-D12 | Migration 管理：Shared 提供可重用 Migration 方案，各 App 自行審查與執行，不由 Shared 直接修改所有 App 資料庫 | NOT TESTED | Shared migration；Tiandi 自行審查執行 | — |
| S9-INT-D13 | 開發啟動（B）：確認本輪後立即啟動一次性整合開發與自動部署流程；但同一指示明確要求「不要開發」，故目前僅啟動現有 HEAD/API/CI/部署環境之唯讀… | N/A | 本輪依使用者 2026-10-25 指示開發；不部署 | — |
| S9-INT-D14 | 一次開發範圍（A）：九模組同一整合交付，維持模組邊界與各 App 獨立部署，不合併為巨型服務 | NOT TESTED | 九模組同一 PR，模組各自 Service／資料表 | — |
| S9-INT-D15 | 自動測試（A）：自動合併與部署前執行測試及安全檢查，失敗即停止 | N/A | CI 在每次 PR 執行測試與檢查，失敗即不通過；自動合併與部署**未授權**（INT-D06），不在本 PR 範圍 | CI |
| S9-INT-D16 | 部署失敗處理（A）：部署失敗時停止後續部署，保留回退方案與錯誤紀錄 | N/A | 無部署流程；回退方案見 docs §6 | — |
| S9-INT-D17 | 部署憑證（A）：各 App 獨立保管 Secrets，採最小權限，不共用管理員密碼 | NOT TESTED | 無任何 Secrets 進 repo；.env.example 全空 | — |
| S9-INT-D18 | 部署目標（A）：先核對各 App 現有 CI、執行環境及部署目標，不預設各 App 環境相同 | BLOCKED | 兩 repo CI 已核對；部署目標與執行環境需使用者提供存取後核實 | — |

## M03 身分認證

| ID | 決策 | 狀態 | 實作 | 測試證據 |
|---|---|---|---|---|
| M03-D01 | 審核制度：各 App 後台可設定，預設一位授權管理員即可審核 | PASS | verification_types.required_approvals 預設 1；Filament VerificationTypeResource | T/M03VerificationTest: needs the configured approvals… |
| M03-D02 | 證明文件：依認證類型設定是否需要 | PASS | requires_evidence；VerificationService::apply | T/M03VerificationTest: applies with private evidence… |
| M03-D03 | 徽章公開：會員可自行切換公開／隱藏 | PASS | PATCH /me/verifications/{id} | T/M03VerificationTest: shows public badges…；Flutter: M03 applies without evidence and hides a badge |
| M03-D04 | 有效期：預設永久有效，可設定到期日 | PASS | validity_days（null＝永久）；MemberVerification::state | T/M03VerificationTest: expires badges… |
| M03-D05 | 拒絕重申：遭拒絕可重新申請 | PASS | 拒絕後可再申請 | T/M03VerificationTest: lets members withdraw and reapply after a rejection |
| M03-D06 | 多人審核：達到指定同意人數才通過 | PASS | verification_reviews unique(request, reviewer)；禁止自審 | T/M03VerificationTest: needs the configured approvals… |
| M03-D07 | 撤銷：授權管理員可撤銷，立即生效並保留紀錄 | PASS | VerificationService::revoke（立即、保留紀錄、服務層權限檢查） | T/M03VerificationTest: expires badges and revokes…；ReviewFixesTest: refuses to revoke… without the review permission |
| M03-D08 | 徽章預設：預設公開，會員可關閉 | PASS | member_verifications.is_public 預設 true | T/M03VerificationTest: needs the configured approvals…（is_public） |
| M03-D09 | 同類多重認證：各 App 後台設定，預設允許多個有效認證 | PASS | allow_multiple 預設 true | T/Round2Test: allows several valid verifications of the same type by default |
| M03-D10 | 撤銷通知：各 App 後台可選 App／Email／不通知 | PASS | revoke_notify none/app/email | T/M03VerificationTest: expires badges and revokes with the configured notification channel |
| M03-D11 | 到期提醒：暫不提醒，到期自動失效 | PASS | scopeValid 依 expires_at；無提醒排程 | T/M03VerificationTest: expires badges… |
| M03-D12 | 證據存取：本人及授權審核員可查看 | PASS | canReadEvidence；API 證據下載＋後台簽章網址 | T/M03VerificationTest: applies with private evidence readable only by the owner and reviewers |

## M08 活動報名

| ID | 決策 | 狀態 | 實作 | 測試證據 |
|---|---|---|---|---|
| M08-D01 | 報名對象：各 App 後台依活動類別設定 | PASS | event_categories（allow_guest_booking、required_verification_type_id） | T/M08GuestBookingTest: requires an email or phone and refuses guests where the category does not allow them |
| M08-D02 | 代報名：各 App 後台依活動類別設定 | PASS | event_categories.allow_proxy_booking | T/M08BookingTest: enforces the seat limit, the proxy setting… |
| M08-D03 | 額滿候補：開放候補、自動遞補 | PASS | waitlist_enabled；BookingEngine::promote | T/M08BookingTest: waitlists whole groups… |
| M08-D04 | 取消期限：各活動自行設定 | PASS | cancel_closes_at／cancel_offset_minutes | T/M08BookingTest: enforces … cancel deadline |
| M08-D05 | 每人名額：各活動自行設定 | PASS | booking_max_seats；**複審修正：每人（或訪客聯絡方式）跨多筆報名合計** | T/ReviewFixesTest: caps the seats one member books across several bookings／one guest contact… |
| M08-D06 | 訪客報名：姓名＋Email 或手機聯絡資訊 | PASS | Web /events/{id}/guest-booking（姓名＋Email 或手機） | T/M08GuestBookingTest: lets guests book on the web page… |
| M08-D07 | 代報名資料：每位實際參加者記錄個別資料 | PASS | event_booking_attendees 每位一筆 | T/M08BookingTest: counts … one ledger |
| M08-D08 | 候補順序：依先後順序自動確認 | PASS | event_waitlist_entries.sequence | T/M08BookingTest: waitlists whole groups… |
| M08-D09 | 遞補失敗：自動嘗試下一位候補者；不得將暫時性系統錯誤當作可跳號理由 | PASS | permanentFailure 只跳過停用／刪除帳號；暫時錯誤整筆交易失敗不跳號 | T/M08BookingTest: skips a waiting group whose member account was disabled… |
| M08-D10 | 資格驗證：報名當下驗證 | PASS | assertEligible（報名當下） | T/M08BookingTest: requires the category verification when booking… |
| M08-D11 | 重要異動：通知已報名及候補者 | PASS | notifyChange（正式＋候補） | T/M09CalendarTest: keeps an occurrence edited in the admin as an exception and notifies bookings |
| M08-D12 | 活動取消：取消相關報名及候補，通知所有相關參加者 | PASS | cancelEvent | T/M08BookingTest: cancels the whole occurrence… |
| M08-D13 | 候補成功通知：App 站內通知 | PASS | promote → 站內通知 | T/M08BookingTest: lets members withdraw from the waitlist… promotes |
| M08-D14 | 管理員代操作：可代報名、取消、調整候補並留稽核 | PASS | Filament BookingGroupsRelationManager（adminBook／cancel／moveWaitlist）；稽核記錄管理員帳號並通知會員 | T/M08BookingTest: lets admins reorder the waitlist…；T/Round2Test: attributes admin bookings and cancellations to the admin… |
| M08-D15 | 取消候補：會員可自行取消候補 | PASS | 候補可隨時取消 | T/M08BookingTest: lets members withdraw from the waitlist at any time… |
| M08-D16 | 取消釋出名額：立即自動遞補 | PASS | 取消後同交易內 promote | 同上＋CI S9 併發 FIFO 遞補；T/Round2Test: keeps the S6 booking row and the S9 ledger consistent… |
| M08-D17 | 多人候補：全部名額足夠才整組遞補 | PASS | 整組遞補 | T/M08BookingTest: waitlists whole groups… |
| M08-D18 | 訪客管理：訪客須先註冊會員才能自行管理報名 | PASS | GuestClaimService（需註冊並驗證） | T/M08GuestBookingTest: keeps guest notifications… claims |
| M08-D19 | 資格失效：報名後認證資格失效，不自動取消既有報名 | PASS | 資格只在報名時檢查 | T/M08BookingTest: … keeps bookings after it ends |
| M08-D20 | 縮減容量：管理員可選擇取消部分報名 | PASS | changeCapacity（低於已確認需選擇取消對象） | T/M08BookingTest: lets admins reduce capacity only by choosing whom to cancel |
| M08-D21 | 重要通知對象：報名聯絡人及候補聯絡人 | PASS | 通知寄給群組聯絡人（會員或訪客 notifiable） | T/M08BookingTest: cancels the whole occurrence… notifies every contact |
| M08-D22 | 候補跳號：嚴格 FIFO；前組名額不足時，後組不得插隊 | PASS | 嚴格 FIFO（head 擋住後組） | T/M08BookingTest: … never lets a later group jump the head group；CI 100 並行 |
| M08-D23 | 訪客通知：僅保留通知紀錄，待註冊後顯示；不自動改寄 Email/簡訊 | PASS | 訪客通知存在群組上，不寄 Email／簡訊 | T/M08GuestBookingTest: lets guests book … without sending any email or SMS |
| M08-D24 | 訪客綁定：註冊後須驗證原報名聯絡方式，才能綁定並自行管理 | PASS | 需本人已驗證的同 Email／手機；**複審修正：先鎖場次再認領、認領後同步 Google** | T/M08GuestBookingTest: matches guests by verified phone…；M09GoogleSyncTest: syncs a guest booking to Google once the member claims it |

## M09 行事曆

| ID | 決策 | 狀態 | 實作 | 測試證據 |
|---|---|---|---|---|
| M09-D01 | 日曆檢視：月曆、週曆、列表三種模式 | PASS | FL/features/s9/my_calendar_screen.dart：月格／週／列表三種檢視（文字標示筆數與已報名，非僅顏色） | Flutter（s9_screens_test）: M09 shows my calendar as month grid, week and list |
| M09-D02 | 顯示內容：公開活動＋我的報名 | PASS | GET /me/calendar（公開與會員活動＋我的報名狀態） | T/M09CalendarTest: returns my calendar with category colours, venue and my booking state |
| M09-D03 | 重複活動：支援每週、每月重複活動 | PASS | EventSeriesService weekly/monthly | T/M09CalendarTest: generates weekly…；keeps local time across DST… |
| M09-D04 | 外部日曆：直接整合 Google 日曆（不是僅匯出 .ics） | BLOCKED | GoogleCalendarClient（直接 API） | T/M09GoogleSyncTest（Mock）；真實 OAuth 待憑證 |
| M09-D05 | 私人行程：只有高級 VIP 會員開放 | PASS | CalendarVip＋PrivateCalendarService | T/M09CalendarTest: allows private entries only for the configured VIP levels… |
| M09-D06 | Google 同步方向：App 單向同步到 Google 日曆；Google 修改不反向覆寫 App | PASS | 只呼叫 PUT／DELETE，不讀回 Google | T/M09GoogleSyncTest（Mock） |
| M09-D07 | Google 同步內容：我的報名及 VIP 私人行程 | PASS | 同步報名與私人行程 | T/M09GoogleSyncTest: syncs a booking…；syncs VIP private entries… |
| M09-D08 | VIP 到期或降級：原私人行程保留、可查看，但不可新增或修改 | PASS | assertVip（新增／修改） | T/M09CalendarTest: … keeps them read-only after |
| M09-D09 | 重複活動單次例外：可單獨修改或取消某一次活動 | PASS | is_series_exception；Filament 單次修改 | T/M09CalendarTest: keeps an occurrence edited in the admin as an exception… |
| M09-D10 | 日曆顏色：依活動類別設定顏色 | PASS | event_categories.color | T/M09CalendarTest: returns my calendar with category colours… |
| M09-D11 | Google 同步時機：自動背景同步 | PASS | Queue 背景同步 | T/M09GoogleSyncTest（sync queue） |
| M09-D12 | 活動取消或異動：自動同步更新或移除 Google 對應行程 | PASS | notifyChange／endGroup 排入同步，沿用同一 Google 事件 ID | T/M09GoogleSyncTest: updates the Google event when the occurrence changes；… removes it after cancelling |
| M09-D13 | 解除 Google 連結：保留 Google 既有行程，停止後續同步 | PASS | disconnect（保留 Google 事件） | T/M09GoogleSyncTest: keeps existing Google events and stops syncing after disconnecting |
| M09-D14 | VIP 到期刪除權限：原私人行程完全唯讀，不可刪除；亦不可新增或修改 | PASS | delete 也需 VIP；**複審修正：契約描述與 App 唯讀顯示** | T/M09CalendarTest: … read-only after（DELETE 403） |
| M09-D15 | Google 私人行程隱私：同步到 Google 的 VIP 私人行程預設私人、不公開 | PASS | visibility 'private' | T/M09GoogleSyncTest: syncs VIP private entries as private… |
| M09-D16 | Google 帳號數：每位會員連結一個 Google 帳號 | PASS | google_calendar_connections.user_id unique | T/M09GoogleSyncTest: connects with a one-time state… |
| M09-D17 | Google 目標日曆：建立 App 專用 Google 日曆 | PASS | createCalendar（App 專用日曆） | T/M09GoogleSyncTest: connects… creates the App calendar |
| M09-D18 | VIP 失效同步：停止私人行程後續同步，保留 Google 現有行程 | PASS | VIP 失效回傳 'keep' | T/M09GoogleSyncTest: … stops when VIP ends |
| M09-D19 | 重複活動修改：可選單次、此次及未來、全部場次 | PASS | single / this_and_future / all | T/M09CalendarTest: applies future and all changes…；cancels this and future… |
| M09-D20 | 活動提醒：沿用 Google 日曆提醒設定；不另建 App 提醒引擎 | NOT TESTED | reminders useDefault；無 App 提醒引擎 | — |
| M09-D21 | 時區：預設 Asia/Taipei，支援其他時區 | PASS | timezone 欄位（預設 Asia/Taipei） | T/M09CalendarTest: keeps local time across DST… |
| M09-D22 | 高級 VIP 定義：各 App 後台指定符合資格的會員等級；由 M02 等級判定，不可硬編碼等級 ID | PASS | Settings calendar_vip_level_codes（等級代碼，非 ID） | T/M09CalendarTest: allows private entries only for the configured VIP levels… |
| M09-D23 | VIP 私人行程重複：支援每週、每月重複私人行程 | PASS | 私人行程 weekly/monthly | T/M09GoogleSyncTest: syncs VIP private entries…（RRULE） |
| M09-D24 | Google 首次同步：只同步當下及未來行程，不自動回填過去 | PASS | queueAll 只排入 ends_at >= now | T/M09GoogleSyncTest: syncs only current and future bookings when a member connects（Mock） |
| M09-D25 | 多人代報名同步：每個報名會員、每個實際活動場次只同步一筆 Google 事件；多人參加者不重複建立 | PASS | 每連結×場次一筆 mapping（unique） | T/M09GoogleSyncTest: syncs a booking once per member and occurrence… |

## M11 簽到

| ID | 決策 | 狀態 | 實作 | 測試證據 |
|---|---|---|---|---|
| M11-D01 | 簽到入口：會員、工作人員、Location QR Code、管理員四種入口；各入口具體流程待下一輪確認 | PASS | 會員 event QR、工作人員掃 pass、Location QR、Filament 管理員 | T/M11CheckinTest 全部 |
| M11-D02 | 訪客簽到：允許未註冊訪客依 M08 訪客報名資料簽到 | PASS | 工作人員手動簽到訪客（核對報名 Email／手機） | T/Round2Test: lets staff check in by hand and requires the booking contact for guests |
| M11-D03 | 多人代報名：每位實際參加者分別簽到，不以整組代替 | PASS | 逐人簽到 | T/M11CheckinTest: checks attendees in one by one… |
| M11-D04 | 未預約現場參加：管理員可現場補報名，再進行簽到；需遵守 M08 名額/資格規則 | NOT TESTED | Filament adminBook 後簽到 | — |
| M11-D05 | 重複活動：每個實際活動場次獨立簽到 | PASS | 每場次獨立 | T/M11CheckinTest: keeps attendance per occurrence |
| M11-D06 | 簽到時間：各活動可分別設定簽到開始及截止時間 | PASS | checkin_open_before／close_after | T/M11CheckinTest: refuses check-in outside the window… |
| M11-D07 | 會員簽到入口：會員掃描活動 QR Code 自行簽到 | PASS | POST /events/{id}/checkin event_token | T/M11CheckinTest；Flutter: M11 scans the event QR… |
| M11-D08 | 工作人員簽到入口：工作人員掃描會員或參加者 QR Code | PASS | passes＋/checkin/staff | T/M11CheckinTest: lets authorised staff scan attendee passes… |
| M11-D09 | Location QR Code：現場張貼地點 QR Code；是否要求 GPS 由各 App 設定 | PASS | checkin_locations＋gps 設定 | T/M11CheckinTest: uses a fixed Location QR… |
| M11-D10 | QR 防轉傳：活動簽到 QR Code 定期更新 | PASS | HMAC 60 秒輪替 | T/M11CheckinTest: accepts the current event token… |
| M11-D11 | 訪客核對：核對報名聯絡資料及實際參加者資料 | PASS | 手動簽到未認領訪客時，伺服器比對訪客說出的報名 Email／手機（工作人員只看遮罩資料，D29）；有 QR 時以 pass 核對 | T/Round2Test: lets staff check in by hand…；Flutter（s9_screens_test）: M11 staff check a guest in by hand after the contact check |
| M11-D12 | 管理員操作：可手動簽到、撤銷或更正；所有操作保留稽核紀錄 | PASS | adminSet＋稽核（記錄管理員帳號，原因存更正表） | T/M11CheckinTest: requires a reason…；T/Round2Test: keeps the audit log…（actor 依序 null／staff／staff／admin） |
| M11-D13 | 離線簽到：必須有網路連線才能完成簽到；不提供離線暫存 | PASS | 無離線佇列 | —（設計） |
| M11-D14 | 重複簽到：顯示「已簽到」，不得重複新增有效簽到紀錄 | PASS | unique＋already_checked_in | T/M11CheckinTest: … never twice |
| M11-D15 | 簽退：各活動自行設定是否啟用簽退 | PASS | checkout_enabled | T/M11CheckinTest: checks out with the same QR… |
| M11-D16 | 逾時補登：僅管理員可補登，必填原因 | PASS | REASON_REQUIRED | T/M11CheckinTest: requires a reason… |
| M11-D17 | GPS 定位失敗：工作人員或管理員可人工核實後協助簽到 | PASS | API /checkin/staff attendee_id＋App 工作人員畫面手動簽到清單 | T/Round2Test: lets staff check in by hand…；Flutter（s9_screens_test）: M11 staff check a guest in by hand… |
| M11-D18 | 出席統計：各場次提供報名、已簽到、未到、簽退人數及明細名單 | PASS | summary＋名單 | T/M11CheckinTest: lets authorised staff … attendance list |
| M11-D19 | Location QR 適用範圍：一個地點 QR 可供多場活動使用，掃描後選擇實際活動場次 | PASS | GET /checkin/locations/{token} | T/M11CheckinTest: uses a fixed Location QR… |
| M11-D20 | GPS 允許距離：各 App 或活動可設定允許簽到距離 | PASS | checkin_gps_radius_m；**複審修正：距離即半徑、精確度不足拒絕** | T/ReviewFixesTest: refuses GPS check-in when the fix is less precise… |
| M11-D21 | 活動 QR 更新週期：每 60 秒更新一次 | PASS | WINDOW_SECONDS 60 | T/M11CheckinTest: accepts the current event token… |
| M11-D22 | 地點 QR 與活動 QR：Location QR 可固定張貼；活動簽到 QR 定期動態更新 | PASS | Location token 固定；event token 輪替 | T/M11CheckinTest |
| M11-D23 | 簽退操作：沿用簽到 QR 入口，依狀態提供簽退 | PASS | /checkout 同 QR | T/M11CheckinTest: checks out with the same QR… |
| M11-D24 | 紀錄保存期限（2026-10-25 正式裁決 A）：各 App 後台可設定簽到個資保存期限；到期清除可識別簽到個資與管理員更正原因。既有稽核事件嚴格 append-only：不刪除、不改寫… | PASS | **裁決 A 已實作**：checkin_retention_days 只清簽到個資與更正原因；稽核 append-only 不刪不改；寫入時去識別；清理另寫 checkin.retention_purge | T/Round2Test: keeps the audit log append-only, purges check-in data and leaves no way to re-identify attendees；T/M11CheckinTest: purges old check-ins without rewriting… |
| M11-D25 | 動態活動 QR 寬限：每 60 秒更新；過期後寬限 10 秒 | PASS | GRACE_SECONDS 10 | T/M11CheckinTest: accepts … only within 10 seconds |
| M11-D26 | GPS 設定優先：活動設定優先於各 App 預設，活動未設定才使用 App 預設 | PASS | 活動設定優先，否則 App 預設 | T/M11CheckinTest: uses a fixed Location QR with optional GPS and the event setting first |
| M11-D27 | 簽退後重新簽到：不可自行重新簽到，僅管理員可以更正並保留稽核 | PASS | already_checked_out；僅 adminSet 可改 | T/M11CheckinTest: checks out … does not let members check in again |
| M11-D28 | 保存期限到期：刪除簽到個資；必要稽核僅保留去識別資訊 | PASS | 刪除簽到與更正原因；稽核寫入時即去識別；清除後稽核無法連回參加者（subject／correction 皆已刪除） | T/Round2Test: keeps the audit log append-only…（逐欄不變、無個資字串、無可回查關聯） |
| M11-D29 | 出席明細權限：僅授權工作人員及管理員可查看明細 | PASS | assertStaff；名單只給遮罩聯絡資料 | T/M11CheckinTest；T/Round2Test: lets staff check in by hand…（無完整聯絡資料） |
| M11-D30 | 候補簽到：候補未轉正前不可簽到，必須先成為正式報名 | PASS | WAITLISTED | T/M11CheckinTest: refuses … for waitlisted attendees |

## M14 Deep Link

| ID | 決策 | 狀態 | 實作 | 測試證據 |
|---|---|---|---|---|
| M14-D01 | 支援平台：同時支援 Android App Links 與 iOS Universal Links | BLOCKED | Android intent-filter＋assetlinks 已就緒；iOS 提供 `Runner.entitlements.example` 模板與 `docs/s9_ios_universal_links.md`；正式 Team ID、簽章與實機未做 | T/M14M15M17LinksTest: serves App Links and Universal Links files only when configured |
| M14-D02 | 連結內容：文章、公告、活動、場所、會員公開頁等可作為 Deep Link 目標；實際類型由各 App 自行設定 | PASS | 依決策「實際類型由各 App 設定」：現有內容類型為文章／活動／公告（ContentRegistry＋設定）。目前 S1–S9 沒有場所或會員公開頁的資料模型，**未為此另建第二套內容系統**；日後有該內容時加入 ContentRegistry 即可 | T/M14M15M17LinksTest: serves web fallback pages… |
| M14-D03 | 未安裝 App：開啟對應網頁，並提供 App 安裝入口；不直接強制導向商店 | NOT TESTED | links/layout 安裝入口（store_url 設定） | — |
| M14-D04 | 登入處理：需要登入時，登入成功後回到原本指定內容頁面 | NOT TESTED | router safeReturnPath（/login?from=） | Dart smoke: safeReturnPath |
| M14-D05 | 無效或無權限連結：顯示適當的連結失效／無權限提示，提供返回首頁 | PASS | Web：links/unavailable（404＋回首頁）；App：ContentUnavailable「連結已失效／沒有權限／請先登入」＋返回首頁 | T/M14M15M17LinksTest；Flutter（s9_screens_test）: M14 explains gone, forbidden and offline links and goes home |
| M14-D06 | 網域策略：各 App 使用自己的網域及連結設定；不要求所有 App 共用單一網址網域 | NOT TESTED | config/deeplinks.php base_url／APP_LINK_HOST 各 App 自設 | — |
| M14-D07 | 網址格式：使用各 App 網域的標準 HTTPS 網址，例如 `https://app.example.com/events/123`；不以自訂 … | PASS | 固定 ID HTTPS 網址 | T/M14M15M17LinksTest: returns share URLs… |
| M14-D08 | QR Code：每個可分享內容都可以產生 QR Code | PASS | **複審修正**：內容頁 QR Code 對話框 | Flutter: M14 shows share and QR only for shareable content |
| M14-D09 | 社群分享預覽：公開內容可顯示標題、摘要與圖片；私人內容不得洩漏 | PASS | OG 只給公開內容 | T/M14M15M17LinksTest: serves web fallback pages with OG for public content only |
| M14-D10 | 連結永久性：使用固定內容 ID，修改標題不改變網址 | PASS | /articles/{id}（舊 slug 仍可） | T/M14M15M17LinksTest；S8 ArticleReadTest |
| M14-D11 | 網址參數：只接受預先定義的安全參數，不允許任意跳轉網址參數 | PASS | DeepLinkParser 只接受固定路徑與合法 utm | Dart smoke: deep link／unsafe utm dropped |
| M14-D12 | 分享方式：使用手機原生分享功能，不另外開發完整社群平台整合 | NOT TESTED | share_plus 原生分享 | —（實機） |
| M14-D13 | 冷啟動：App 完全關閉時，啟動後直接導航至連結指定內容，而非只進首頁 | NOT TESTED | app_links uriLinkStream（冷啟動） | **NOT TESTED（需實機）** |
| M14-D14 | 熱啟動：App 已執行時直接導航至指定內容，不要求重新啟動 | NOT TESTED | 熱啟動 push | **NOT TESTED（需實機）** |
| M14-D15 | 重複點擊：短時間重複點擊相同連結時避免重複建立相同頁面 | PASS | DeepLinkDeduper | Dart smoke: dedupe |
| M14-D16 | 連結有效期：一般內容連結原則上永久有效，不設統一 30 天到期；實際能否開啟仍依內容存在、發布狀態及權限 | NOT TESTED | 無統一到期 | — |
| M14-D17 | 平台驗證：正式設定 Android `assetlinks.json` 與 iOS AASA（apple-app-site-association… | BLOCKED | assetlinks／AASA 只在設定真實值後提供 | 需正式網域、簽章、Team ID 與實機 |
| M14-D18 | 開啟失敗：顯示錯誤狀態，提供重試及返回首頁，不直接關閉頁面 | PASS | App：錯誤狀態＋重試＋返回首頁，不關閉頁面 | Flutter（s9_screens_test）: M14 explains gone, forbidden and offline links… |
| M14-D19 | 登入回跳：只保存本 App 安全路由，登入後重新驗證內容權限，不接受任意外部網址回跳 | PASS | safeReturnPath 只收 App 內固定路由 | Dart smoke: return path／external return |
| M14-D20 | 跨 App 連結：每個 App 只處理自己的網域及內容，不在目前 App 直接開啟其他 App 的內容 | PASS | DeepLinkParser 只認本 App 網域 | Dart smoke: deep link host |
| M14-D21 | 網頁備援：App 無法開啟時，仍可使用 HTTPS 網頁查看有權限的內容，而非只顯示安裝頁 | PASS | ContentPageController 網頁備援（有權限內容） | T/M14M15M17LinksTest: serves web fallback pages… |
| M14-D22 | 特殊連結：邀請、驗證、重設密碼等特殊連結依用途獨立設定期限，不適用一般內容永久連結規則 | NOT TESTED | 既有 S1/S3 驗證、重設連結各自期限（未更動） | 既有 S1/S3 測試 |
| M14-D23 | 分享權限：只有允許分享的內容顯示分享與 QR Code 按鈕 | PASS | share API；**複審修正：App 只在 shareable 時顯示分享與 QR** | T/M14M15M17LinksTest: returns share URLs…；Flutter: M14 shows share and QR only… |
| M14-D24 | 驗收範圍：Android 與 iOS 實機、LINE、瀏覽器、QR Code、App 冷啟動與熱啟動測試 | BLOCKED | 實機、LINE、瀏覽器、冷熱啟動驗收 | 需實機 |
| M14-D25 | 導航返回：由 Deep Link 進入後，熱啟動返回 App 原本頁面；冷啟動返回 App 首頁，不直接結束 App | NOT TESTED | **複審修正**：冷啟動先回首頁再開內容；熱啟動 push | **NOT TESTED（需實機）** |
| M14-D26 | 登入待處理連結：僅保留至本次登入流程完成或取消，不跨多次重啟長期保存 | NOT TESTED | 回跳只放在本次登入的 query 參數，不落地保存 | — |
| M14-D27 | QR Code 輸出：畫面顯示 QR Code，且可分享 QR Code 圖片 | NOT TESTED | **複審修正**：QR 圖片分享（PNG） | 畫面有 Widget 測試；圖片分享 **NOT TESTED（需實機）** |
| M14-D28 | 分享預覽圖片：使用內容既有封面圖；無封面時使用 App 預設圖 | PASS | og:image＝封面或 DEEPLINK_DEFAULT_IMAGE | T/Round2Test: uses the article cover for the preview image and the App default otherwise |
| M14-D29 | 離線處理：顯示離線提示，並提供重試，不直接丟回首頁 | PASS | App：NETWORK_ERROR 顯示「目前離線」＋重試＋返回首頁 | Flutter（s9_screens_test）: M14 explains … offline… |
| M14-D30 | 連結統計：M14 不重複開發完整點擊統計，交由 M17 URL Clicks & Analytics 規劃 | PASS | 統計交給 M17 | T/M14M15M17LinksTest（web 點擊由 M17 記錄） |

## M15 行銷

| ID | 決策 | 狀態 | 實作 | 測試證據 |
|---|---|---|---|---|
| M15-D01 | 資料隔離：各 App 行銷資料獨立，只共用 Shared 程式；不共用跨 App 行銷資料庫 | NOT TESTED | 各 App 自己的 marketing_* 資料表 | — |
| M15-D02 | 活動類型：各 App 自行設定可推廣的活動、文章、課程、商品等內容類型 | NOT TESTED | Settings marketing_target_types | — |
| M15-D03 | 行銷管道：第一階段支援 App 內展示，以及 LINE、社群分享連結；不直接整合付費廣告投放 API | NOT TESTED | App 內展示＋M14 分享連結；無廣告 API | — |
| M15-D04 | 管理權限：僅具行銷管理權限的管理員可建立、修改、發布行銷活動；一般會員不得管理 | PASS | Filament marketing.manage | T/Round2Test: closes each S9 admin screen without its own permission |
| M15-D05 | 活動排程：可設定活動開始與結束時間，系統自動控制展示 | PASS | starts_at／ends_at 由伺服器判定 | T/M14M15M17LinksTest: derives the five campaign states… |
| M15-D06 | MVP 範圍：第一階段只做活動內容管理、分享連結及 M17 成效分析，不建立複雜自動化分眾行銷漏斗 | NOT TESTED | 無分眾漏斗 | — |
| M15-D07 | 行銷素材：活動支援標題、摘要、圖片、按鈕文字與連結；不限制為純文字 | PASS | 標題、摘要、圖片、按鈕、連結 | T/M14M15M17LinksTest: lists active campaigns… |
| M15-D08 | 展示位置：各 App 自行設定首頁橫幅或指定頁面區塊；不強制所有 App 使用同一固定位置 | PASS | marketing_placements | T/M14M15M17LinksTest: lists active campaigns… |
| M15-D09 | 排序優先：管理員可設定活動展示優先順序，且必須遵守活動開始及結束排程 | PASS | priority 排序＋排程 | T/M14M15M17LinksTest: lists active campaigns by priority… |
| M15-D10 | 活動狀態：支援草稿、已排程、進行中、已結束、停用五種狀態 | PASS | state()：五種狀態 | T/M14M15M17LinksTest: derives the five campaign states… |
| M15-D11 | 到期處理：活動到期自動停止展示，保留活動紀錄及統計；統計資料仍須遵守 M17 的保存期限 | PASS | 到期不展示；資料保留 | T/M14M15M17LinksTest: derives … ended |
| M15-D12 | 點擊目標：行銷內容可導向各 App 既有內容的 M14 Deep Link，或合法 HTTPS 網址；不限於 App 首頁 | PASS | M14 URL 或 https 外部網址 | T/M14M15M17LinksTest: validates campaigns… |
| M15-D13 | 同區塊展示數量：各 App 可設定每個展示區塊最多顯示的活動數量，不無限制展示 | PASS | max_items | T/M14M15M17LinksTest: … within the placement limit… |
| M15-D14 | 圖片處理：沿用各 App 既有圖片上傳與管理功能，提供圖片尺寸與比例提示，不另建 M15 獨立儲存系統 | NOT TESTED | FileUpload（建議 16:9 提示），沿用 local disk | — |
| M15-D15 | 發布前預覽：管理員可預覽活動素材與連結後再發布 | NOT TESTED | Filament 預覽動作 | — |
| M15-D16 | 連結開啟方式：App 內內容沿用 M14 Deep Link 既有導向規則，外部合法 HTTPS 網址安全開啟，不強制全部 WebView | PASS | App 內走 M14 路由；外部 https 以系統瀏覽器開 | Flutter: M15 banner counts the tap and opens the campaign |
| M15-D17 | 展示對象：依各 App 現有公開、登入與內容權限規則決定展示，第一階段不另建完整會員分眾引擎 | PASS | canView 檢查 | T/M14M15M17LinksTest: … and the viewer permission |
| M15-D18 | 進行中修改：授權管理員可修改進行中活動，修改於下一次載入生效 | NOT TESTED | 每次載入即時讀取 | — |
| M15-D19 | 活動複製：可複製既有行銷活動為新草稿，須重新設定排程，不直接發布 | PASS | duplicate → 新草稿 | T/M14M15M17LinksTest: duplicates as a draft… |
| M15-D20 | 手動停用：具行銷管理權限的管理員可隨時停用進行中活動，立即停止後續展示 | PASS | disable 立即停止展示 | T/Round2Test: sorts popular tags … audits tag and campaign operations |
| M15-D21 | 活動刪除：刪除活動前必須確認；不連帶刪除 M17 保存期限內合法的去識別化彙總統計 | PASS | 刪除需確認；M17 彙總保留 | T/M14M15M17LinksTest: duplicates … delete |
| M15-D22 | 成效分析：活動後台連結 M17 查看點擊、來源與成功開啟成效；M15 不另建統計引擎 | NOT TESTED | 後台連到 M17 報表 | — |
| M15-D23 | 操作紀錄：記錄建立、修改、發布、停用、刪除等重要管理員操作 | PASS | marketing.* 稽核（建立、修改、發布、停用、複製、刪除） | T/Round2Test: … audits tag and campaign operations |
| M15-D24 | 批次管理：第一階段僅支援單筆操作，不加入批次發布、停用或刪除 | NOT TESTED | 無批次動作 | — |
| M15-D25 | 發布驗證：發布前由後端驗證必要欄位、排程、展示區與連結，驗證不通過不得發布 | PASS | problems()／publish | T/M14M15M17LinksTest: validates campaigns on the server before publishing |
| M15-D26 | 無效連結：無效或不安全連結禁止發布，並向管理員顯示原因 | PASS | 不安全連結拒絕 | T/M14M15M17LinksTest: validates campaigns… |
| M15-D27 | 排程衝突：允許多個活動排程重疊，依管理員設定的優先順序及展示區塊上限決定展示 | PASS | 重疊時依優先序與上限 | T/M14M15M17LinksTest: lists active campaigns by priority… |
| M15-D28 | 私人內容保護：公開行銷不得洩漏私人內容資訊；點擊目標時必須重新驗證存取權限 | PASS | 公開展示不洩漏會員內容 | T/M14M15M17LinksTest: … viewer permission |
| M15-D29 | 展示失敗處理：素材失效或目標不可用時不展示該活動，並記錄簡要錯誤 | PASS | 素材失效隱藏並記錄 last_error | T/M14M15M17LinksTest: … hides campaigns whose image is missing |
| M15-D30 | 驗收方式（2026-10-25 使用者修訂為 B）：直接上線後再觀察驗證；不要求先以測試活動及實機驗證作為正式驗收前置條件；本決策不代表現在授權開發、合併或部署 | N/A | 上線後觀察（未授權部署） | — |

## M16 標籤

| ID | 決策 | 狀態 | 實作 | 測試證據 |
|---|---|---|---|---|
| M16-D01 | 標籤資料範圍：各 App 獨立標籤資料庫，只共用 Shared 程式；不共用跨 App 標籤資料 | NOT TESTED | 各 App 自己的 tags／taggables | — |
| M16-D02 | 建立權限：由管理員透過 Filament 後台建立及管理標籤；會員不可自由新增 | PASS | Filament TagResource（tags.manage）；無會員 API | T/Round2Test: closes each S9 admin screen without its own permission |
| M16-D03 | 適用內容：各 App 自行設定哪些內容類型可使用標籤，例如活動、文章、課程或商品 | NOT TESTED | Settings content_tag_types | T/M16M19DiscoveryTest: lists only active tags…（停用類型不計） |
| M16-D04 | 標籤結構：第一階段採單層標籤，可搭配分類；不建立多層父子樹 | NOT TESTED | 單層標籤 | — |
| M16-D05 | 重複標籤：同一 App 內依正規化後的標籤名稱避免重複建立 | PASS | Tag::normalize（NFKC、小寫、空白）＋unique | T/M16M19DiscoveryTest: normalises tag names, refuses duplicates… |
| M16-D06 | AI 功能：第一階段使用一般規則式標籤管理，不導入 AI 自動分析或產生標籤 | NOT TESTED | 規則式，無 AI | — |
| M16-D07 | 多標籤：一筆內容可以設定多個標籤 | PASS | taggables 多對多 | T/M16M19DiscoveryTest: limits a content item to ten tags |
| M16-D08 | 重新命名：標籤更名後，所有既有內容關聯自動保留，不要求重新關聯 | NOT TESTED | 關聯以 tag_id 為準，更名不影響 | — |
| M16-D09 | 刪除標籤：管理員確認後可刪除標籤及其關聯，不刪除原始內容 | PASS | TagService::delete 只刪標籤與關聯 | T/M16M19DiscoveryTest: merges tags … deletes tags but never content |
| M16-D10 | 合併標籤：管理員可將來源標籤的內容關聯轉移至目標標籤，完成後移除來源標籤 | PASS | TagService::merge | T/M16M19DiscoveryTest: merges tags without duplicate relations… |
| M16-D11 | 顯示排序：後台可設定標籤排序，前台依設定順序顯示 | PASS | tags.sort | T/M16M19DiscoveryTest: lists only active tags…（排序） |
| M16-D12 | 使用次數：顯示標籤目前關聯的內容數量 | PASS | usageCount＋公開數量 | T/M16M19DiscoveryTest: lists only active tags … counts public items only |
| M16-D13 | 標籤搜尋：支援以標籤名稱進行關鍵字搜尋，不限於完整列表選擇 | PASS | GET /tags?q= | T/M16M19DiscoveryTest: lists only active tags…（q=） |
| M16-D14 | 熱門標籤：依目前關聯的公開可見內容數量排序，不以管理員手動指定熱門為主要規則 | PASS | GET /tags?sort=popular（依公開內容數） | T/Round2Test: sorts popular tags by public usage… |
| M16-D15 | 前台篩選：使用者點選標籤可篩選相關內容，並與 M19 Search & Bookmark 搜尋整合 | PASS | 搜尋頁標籤篩選 | T/M16M19DiscoveryTest: searches with AND keywords…（tags[]）；Flutter: M19 searches… |
| M16-D16 | 停用狀態：管理員可停用標籤，既有內容關聯仍保留 | PASS | is_active，關聯保留 | T/M16M19DiscoveryTest: lists only active tags… |
| M16-D17 | 前台可見性：僅顯示啟用且有公開可見內容的標籤；不公開展示所有標籤 | PASS | 只顯示啟用且有公開內容的標籤 | T/M16M19DiscoveryTest: lists only active tags with public content… |
| M16-D18 | 管理權限：只有具標籤管理權限的管理員可新增、修改、合併與刪除標籤；一般登入會員不得管理 | PASS | tags.manage | T/Round2Test: closes each S9 admin screen without its own permission |
| M16-D19 | 批次管理：Filament 後台支援批次啟用、停用、刪除標籤；刪除前須確認 | NOT TESTED | Filament 批次啟用／停用／刪除 | — |
| M16-D20 | 匯入匯出：支援標籤 CSV 匯入與匯出 | PASS | TagCsv import／export | T/M16M19DiscoveryTest: imports tags from CSV… |
| M16-D21 | 標籤顏色（2026-10-25 修訂，取代原決策）：系統自動為不同標籤配置不同顏色，管理員亦可在後台手動修改；未手動設定時使用自動配色 | PASS | 自動配色＋可手動修改 | T/M16M19DiscoveryTest: normalises … automatic colours |
| M16-D22 | 內容標籤上限：每筆內容最多 10 個標籤 | PASS | TAG_LIMIT_EXCEEDED | T/M16M19DiscoveryTest: limits a content item to ten tags |
| M16-D23 | 搜尋整合：M16 提供標籤資料及篩選條件，由 M19 執行內容搜尋；M16 不另建獨立搜尋系統 | PASS | SearchService 使用標籤條件 | T/M16M19DiscoveryTest: searches with AND keywords… |
| M16-D24 | 資料更新：標籤修改後，前台搜尋結果及數量在下一次請求反映最新資料，不採每日批次更新 | NOT TESTED | 每次請求即時計算 | — |
| M16-D25 | 標籤公開頁：點選標籤直接進入 M19 標籤篩選結果，不另建標籤專屬頁 | NOT TESTED | App /search?tag=（無標籤專屬頁） | — |
| M16-D26 | 零使用標籤：後台保留零使用標籤，前台不顯示 | PASS | 零使用標籤前台不顯示 | T/M16M19DiscoveryTest: lists only active tags… |
| M16-D27 | 多標籤篩選：多個標籤使用 AND 交集，內容必須符合全部選定標籤 | PASS | 多標籤 AND | T/M16M19DiscoveryTest: searches with AND keywords…（兩個標籤） |
| M16-D28 | 批次刪除確認：顯示受影響的標籤與關聯數，經二次確認才刪除 | NOT TESTED | 顯示影響數＋輸入「刪除」二次確認 | — |
| M16-D29 | CSV 匯入重複名稱：遇同 App 既有的正規化標籤名稱時跳過，不覆蓋既有設定，並回報匯入結果 | PASS | 重複名稱略過並回報 | T/M16M19DiscoveryTest: imports tags from CSV skipping duplicates… |
| M16-D30 | 管理操作紀錄：記錄重要標籤新增、更名、合併、刪除及批次操作 | PASS | tag.* 稽核 | T/Round2Test: … audits tag and campaign operations |

## M17 連結統計

| ID | 決策 | 狀態 | 實作 | 測試證據 |
|---|---|---|---|---|
| M17-D01 | 資料範圍：各 App 獨立保存統計資料，只共用 Shared 程式；不使用跨 App 集中共用統計資料庫 | NOT TESTED | 各 App 自己的 link_clicks／link_daily_stats | — |
| M17-D02 | 統計對象：各 App 自行設定可統計的內容類型，包括文章、活動、課程、商品等 | NOT TESTED | Settings content_analytics_types | — |
| M17-D03 | 點擊定義：分別統計總點擊及去重後的估計點擊；不得將估計值宣稱為精確的不重複人數 | PASS | 總點擊＋估計去重（報表標示估計值） | T/M14M15M17LinksTest: records clicks … dedupes per day… |
| M17-D04 | 流量來源：區分可辨識的 LINE、社群、搜尋引擎、直接流量等；無法辨識的歸類為其他或未知 | PASS | classify：line／social／search／direct／other／unknown | T/M14M15M17LinksTest: records clicks…（line） |
| M17-D05 | 隱私原則：資料最小化，不建立跨站個人追蹤，不儲存完整 IP；不採長期裝置指紋追蹤 | PASS | 不存 IP；每日 hash | T/M14M15M17LinksTest: records clicks without IP… |
| M17-D06 | 報表權限：僅具分析權限的管理員可查看完整統計報表；一般會員不得查看 | PASS | analytics.view | T/M14M15M17LinksTest: shows the report only with analytics.view… |
| M17-D07 | 統計期間：報表支援今日、昨天、近 7 天、近 30 天及自訂日期區間 | PASS | LinkReport::range | T/Round2Test: uses fixed report periods in the statistics time zone |
| M17-D08 | 來源判斷：優先使用合法有效的 UTM 參數，再參考 Referrer；無法可靠判定則標示未知 | PASS | UTM 優先，再看 Referrer；格式不符的 UTM 忽略 | T/Round2Test: prefers a valid UTM source over the referrer… |
| M17-D09 | 機器人流量：辨識已知機器人，從主要人類點擊報表排除，另記錄排除數量 | PASS | 已知機器人排除並計數 | T/M14M15M17LinksTest: records clicks … excludes bots… |
| M17-D10 | 去重規則：以每個連結、單日為範圍，使用短期且隱私友善的識別值估算去重點擊；不儲存完整 IP 或長期裝置指紋 | PASS | 每連結×每日 hash | T/M14M15M17LinksTest: … hash changes the next statistics day |
| M17-D11 | 資料保存：原始點擊事件保留 90 天，彙總統計保留 24 個月，不永久保存原始事件 | PASS | 90 天／24 個月 | T/M14M15M17LinksTest: purges raw clicks after 90 days… |
| M17-D12 | 報表更新：下一次請求顯示最新已處理的統計資料，不要求即時推播，不以每日固定批次更新為唯一方式 | NOT TESTED | 每次點擊即時 upsert 彙總 | — |
| M17-D13 | 異常點擊防護：對短時間異常高頻點擊標記，從主要報表排除，另外記錄排除數量 | PASS | 異常高頻排除並計數 | T/M14M15M17LinksTest: excludes abnormal bursts… |
| M17-D14 | 統計事件：第一階段僅統計連結點擊與成功開啟；不追蹤頁面停留、捲動等完整行為 | NOT TESTED | 只記點擊與成功開啟 | — |
| M17-D15 | 分析維度：報表可依內容類型、單筆內容、流量來源與日期篩選 | NOT TESTED | 報表篩選：類型、內容、來源、日期 | — |
| M17-D16 | 熱門排行：依選定期間總點擊排行，並可切換估計去重點擊作為排行依據 | NOT TESTED | 排行可切換總點擊／估計去重 | — |
| M17-D17 | 裝置分類：僅彙總手機、桌面、平板及未知四類，不建立或儲存詳細裝置指紋 | NOT TESTED | mobile／tablet／desktop／unknown | T/M14M15M17LinksTest: records clicks…（mobile） |
| M17-D18 | 報表呈現：管理員 Filament 後台提供摘要數字、趨勢圖與明細表，不僅提供原始事件列表 | NOT TESTED | Filament 摘要、趨勢長條（HTML）、明細 | — |
| M17-D19 | 報表匯出：依目前報表篩選條件匯出 CSV 彙總統計，不匯出可識別個人的原始事件資料 | PASS | CSV 只含彙總 | T/M14M15M17LinksTest: … exports aggregates as CSV |
| M17-D20 | 活動來源標記：M14 分享連結可選擇附加 UTM 活動來源參數；不要求所有分享連結都附加 | PASS | 分享 API 可選擇附加 utm_source／utm_medium／utm_campaign（格式驗證）；預設不附加 | T/Round2Test: adds UTM parameters to a share link only when asked… |
| M17-D21 | 成功開啟判定：僅在收到 App 或網頁的明確成功開啟回報時，才計入成功開啟；單純跳轉不算 | PASS | 只有明確回報才算開啟 | T/M14M15M17LinksTest: … counts an open once |
| M17-D22 | 到期資料清理：原始點擊事件保留 90 天、彙總統計保留 24 個月，到期自動定期清理並記錄執行結果 | PASS | s9:purge-link-stats 排程＋稽核紀錄 | T/M14M15M17LinksTest: purges raw clicks… |
| M17-D23 | 分析權限：沿用各 App 管理員 Policy，獨立授予分析權限；不是所有管理員都自動可看完整報表 | PASS | analytics.view 不自動給 admin | T/M14M15M17LinksTest: shows the report only with analytics.view… |
| M17-D24 | 無法確認開啟：未取得成功開啟回報時，狀態為「未確認」；保留點擊數，不算成功也不直接視為失敗 | PASS | unconfirmed＝總點擊－開啟 | T/M14M15M17LinksTest: shows the report…（summary） |
| M17-D25 | 重複成功回報：同一次點擊的重複成功回報僅計一次，不因多次回報增加成功數 | PASS | 同一 click_id 只算一次 | T/M14M15M17LinksTest: … counts an open once |
| M17-D26 | 內容刪除後統計：保留保存期限內的去識別化彙總統計，但不揭露已刪除內容的私人資訊 | NOT TESTED | label 對已刪除內容只顯示類型與 ID | — |
| M17-D27 | 活動參數命名：使用標準 `utm_source`、`utm_medium`、`utm_campaign`，並驗證長度與格式 | PASS | utm_* 格式與長度驗證 | Dart smoke: unsafe utm dropped；LinkAnalytics::classify |
| M17-D28 | 排除流量透明度：報表分別顯示已知機器人與異常高頻點擊的排除數，不只顯示扣除後總數 | PASS | 報表分別顯示機器人與異常排除數 | T/M14M15M17LinksTest: excludes abnormal bursts… |
| M17-D29 | 統計時區：各 App 設定固定統計時區，日期與報表採一致規則，不隨查看者裝置時區切換 | PASS | Settings analytics_timezone（Asia/Taipei） | T/Round2Test: uses fixed report periods in the statistics time zone |
| M17-D30 | 驗收方式（2026-10-25 使用者修訂）：直接上線後再觀察驗證；此為未來驗收策略，不代表現在授權開發、合併或部署 | N/A | 上線後觀察（未授權部署） | — |

## M19 搜尋與收藏

| ID | 決策 | 狀態 | 實作 | 測試證據 |
|---|---|---|---|---|
| M19-D01 | 搜尋範圍：各 App 只搜尋自己的資料，不跨 App 統一搜尋 | NOT TESTED | 只查本 App 資料表 | — |
| M19-D02 | 可搜尋內容：活動、文章、場所等內容類型，由各 App 設定 | NOT TESTED | Settings content_search_types | — |
| M19-D03 | 搜尋篩選：支援類別、標籤、日期、地點及結果排序 | PASS | API 與 App 皆有類別、標籤、日期、地點、排序篩選 | T/M16M19DiscoveryTest: searches…（location、tags）；Flutter（s9_screens_test）: M19 filters by category and place（日期區間挑選器無 Widget 測試） |
| M19-D04 | 可收藏內容：活動、文章、場所等內容類型，由各 App 設定 | NOT TESTED | Settings content_bookmark_types | T/M16M19DiscoveryTest: bookmarks idempotently…（BOOKMARK_TYPE_DISABLED） |
| M19-D05 | 未登入訪客：可搜尋公開內容，必須登入後才能收藏 | PASS | 搜尋不需登入；收藏需登入 | T/M16M19DiscoveryTest: requires login for bookmarks |
| M19-D06 | 個人搜尋歷史：不儲存個人搜尋歷史 | NOT TESTED | 不保存搜尋字詞 | —（設計） |
| M19-D07 | 搜尋排序：預設依相關性排序，可切換最新／最舊 | PASS | relevance／newest／oldest | T/M16M19DiscoveryTest: pages search results … sorts by date |
| M19-D08 | 收藏分類：依活動、文章、場所等內容類型自動分組，不要求自建收藏資料夾 | PASS | **複審修正**：收藏依類型切換 | Flutter: M19 shows placeholders…（type=article） |
| M19-D09 | 收藏同步：同一 App 帳號跨裝置同步收藏；不跨 App | NOT TESTED | 伺服器端收藏（跨裝置） | — |
| M19-D10 | 下架內容收藏：保留收藏紀錄，顯示不可用且不得開啟下架內容 | PASS | 下架顯示佔位且不可開啟 | T/M16M19DiscoveryTest: bookmarks idempotently and keeps a safe placeholder… |
| M19-D11 | 地點搜尋：依行政區或場所名稱篩選，不採 GPS 附近距離搜尋 | PASS | location 文字比對，無 GPS | T/M16M19DiscoveryTest: searches…（location） |
| M19-D12 | 搜尋/收藏類型設定：各 App 後台設定可搜尋及可收藏的內容類型，不要求逐類型自訂搜尋欄位 | NOT TESTED | Settings content_search_types／content_bookmark_types | — |
| M19-D13 | 取消收藏：使用者可隨時取消收藏，日後可重新收藏 | PASS | 取消後可重新收藏 | T/M16M19DiscoveryTest: bookmarks idempotently… |
| M19-D14 | 內容權限變更：失去存取權限時保留不可用收藏佔位，權限恢復後可再次開啟 | PASS | 失權顯示佔位 | T/M16M19DiscoveryTest: bookmarks … placeholder |
| M19-D15 | 搜尋分頁：每次載入 20 筆，可繼續載入 | PASS | 每頁 20 筆 | T/M16M19DiscoveryTest: pages search results by 20…；Flutter: M19 searches … loads the next page |
| M19-D16 | 篩選與排序：篩選條件與排序可以同時使用 | PASS | 篩選＋排序同時 | T/M16M19DiscoveryTest: pages search results…（q＋sort） |
| M19-D17 | 收藏清單：可依內容類型切換，並按收藏時間排序 | PASS | 依收藏時間；**複審修正：可依類型切換** | Flutter: M19 shows placeholders… |
| M19-D18 | 後台管理：管理員只設定可搜尋/可收藏內容類型，不查看會員個人收藏明細 | NOT TESTED | 後台無會員收藏明細 | —（設計） |
| M19-D19 | 關鍵字比對：搜尋標題、摘要及內容關鍵字 | PASS | 標題、摘要、內文 | T/M16M19DiscoveryTest: searches with AND keywords… |
| M19-D20 | 收藏數量上限：**每位會員最多 1,000 筆收藏**（使用者明確選 B，不得改為無上限） | PASS | 1,000 筆上限（使用者列鎖） | T/M16M19DiscoveryTest: stops at 1,000 bookmarks… |
| M19-D21 | 原始內容永久刪除：保留不可用收藏佔位，直到會員自行取消收藏 | PASS | 永久刪除後佔位保留 | T/M16M19DiscoveryTest: bookmarks … content is gone |
| M19-D22 | 會員帳號刪除：刪除該帳號的個人收藏資料 | PASS | user FK cascade | T/M16M19DiscoveryTest: … cascades with the account |
| M19-D23 | 跨裝置操作衝突：以伺服器最新有效操作為準 | NOT TESTED | 伺服器狀態為準（冪等） | — |
| M19-D24 | 搜尋效能策略：初期使用現有 Laravel/MySQL，實測不足再評估升級搜尋引擎 | NOT TESTED | MySQL LIKE；每類型最多 2,000 筆候選 | —（大量資料效能未測） |
| M19-D25 | 1,000 筆上限計算：包含下架、失權及永久刪除後的不可用收藏佔位 | PASS | 佔位計入上限 | T/M16M19DiscoveryTest: stops at 1,000 bookmarks including placeholders… |
| M19-D26 | 批次清除：可逐筆取消收藏，也可一鍵清除全部；全部清除必須二次確認 | PASS | 逐筆取消＋全部清除二次確認 | Flutter: M19 shows placeholders and clears bookmarks after two confirmations |
| M19-D27 | 搜尋觸發：輸入關鍵字後按搜尋鍵才執行；不要求輸入即時搜尋 | NOT TESTED | 按搜尋才查詢 | Flutter: M19 searches… |
| M19-D28 | 無搜尋結果：顯示無結果並提供清除篩選；不自動推薦其他內容 | PASS | 無結果提示＋清除篩選按鈕（清除類別、日期、地點、標籤） | Flutter（s9_screens_test）: M19 filters by category and place（clear filters） |
| M19-D29 | 相關性技術：一般關鍵字相關性排序，不導入 AI 語意搜尋 | NOT TESTED | LIKE 加權相關性，無 AI | — |
| M19-D30 | 搜尋結果呈現：預設混合所有可搜尋內容類型，可切換指定類型 | PASS | 預設混合類型，可切換 | T/M16M19DiscoveryTest: searches…（type=article） |
