# R1 單人預約重構 — GitHub 實作基線與修改計畫

狀態：設計及依賴盤點中；此文件不是完成驗收證明。
範圍：Shared_Modules_For_App，僅 refactor/r1-single-booking 分支。
前提：沒有必須保留的正式會員、預約、付款資料；允許在隔離開發 DB 做破壞性重建。不得操作其他 DB、iStudy、正式環境或自動合併 main。

## 已核對的現況
- routes/api.php 同時有 S6 /events/{id}/bookings、/me/bookings 與 S9 /events/{id}/booking-groups、/me/booking-groups。
- BookingEngine.php 仍有 bookLegacy 與 group/attendee 資料結構；單次報名已強制一位會員。
- BookingRequest.php 仍有 attendees 陣列及 contactName/contactEmail/contactPhone。
- EventBookingGroup.php 有 legacy_booking_id、seat_count、contact_*、attendees()；EventBookingAttendee.php 是簽到座位紀錄。
- CheckinService.php 的 member/staff QR 流程以 attendee_id 及 EventBookingAttendee 鎖定；不能只改資料表。
- CashInvoiceService.php 查詢 event_booking_groups/event_booking_attendees，負責到期、收款、取消、課程衍生預約。
- CourseEnrollmentService.php 透過 BookingRequest.attendees 產生場次預約，檢查 seat_count=1。
- 2026_11_05_000100_create_course_mvp_tables.php 建立 course_terms/course_sessions/course_enrollments/course_makeups；makeups 外鍵指向 event_booking_groups。
- 2026_11_09_000100_create_course_group_booking_tables.php 建立 course_groups/course_group_events/course_group_members/invoice_booking_items；items 外鍵指向 event_booking_groups。
- 2026_11_06_000100_create_invoice_payment_foundation.php：invoices 以 booking_type+booking_id 做多型關聯，payment_transactions 已有 transaction_code、idempotency_key、provider+external_transaction_id 唯一約束。
- contracts/openapi.yaml 同時宣告 S6/S9 API，簽到仍用 attendee_id；Flutter group_booking_screen.dart 使用 BookingGroup DTO、bookingGroupApiProvider、pending request secure storage。

## 目標資料契約（提案，實作前需完成依賴檢查）
1. event_bookings 作為唯一單場次預約表；一筆預約對應一個 calendar_event_id、一位 member_user_id，另有 booker_user_id 及 actor/audit 欄位。
2. status 明確涵蓋 confirmed/waitlisted/cancelled/event_cancelled；付款等待狀態及歷史需與現有流程核對。
3. 使用 DB 唯一性保證同會員同活動最多一筆有效預約；取消後再預約須允許保留歷史，MySQL NULL/generated-column 策略要驗證。
4. event_attendances、event_waitlist_entries、course_makeups、invoice_booking_items 改為 event_booking_id 外鍵；簽到 QR token 應版本化或明確作廢舊 token。
5. 移除 event_booking_groups、event_booking_attendees、legacy_booking_id、seat_count、booking_max_seats、訪客報名 contact_*；不得刪除活動總容量、候補容量或付款欄位。
6. 保留家庭代管：booker_user_id 可不同於 member_user_id，須驗證有效代管；一請求只報名一人。
7. 系列課程兩套模型必須先釐清業務差異；未完成審查前不得刪除 course_terms 或 course_groups。
8. invoice 的事件預約與課程報名應有可驗證引用，禁止裸多型 ID 指向不存在資料；退款和部分付款生命週期需保留。

## 修改批次與依賴順序
A. 完整盤點所有 migration、Eloquent 關聯、Controller、Service、Jobs、Filament、Flutter DTO、OpenAPI、測試；建立受影響檔案清單。
B. 確定單人預約、課程與發票目標 schema，更新 migration 與 Model；僅對隔離 DB migrate:fresh。
C. 一次統一 BookingEngine、BookingRequest、API 路由、付款/退款、候補、簽到、課程/補課、通知與併發鎖。
D. 同步更新 OpenAPI、Flutter API/DTO/UI、Filament 列表/表單/操作，保留 pending request 冪等重試。
E. 更新並執行 Pest、Flutter、OpenAPI 契約及並發測試，搜尋舊 group/attendee 引用；全部驗收後才建立 PR，絕不自動合併。

## 必驗情境
- 自己報名、家庭代管一位會員報名、無效代管拒絕、訪客拒絕、多人 payload 拒絕。
- 同會員同活動重複提交及併發、取消後重新報名、候補 FIFO 與名額釋放。
- 待付款逾期、付款成功、取消/退款一致性、重試冪等。
- 活動 QR/地點 QR、簽到/簽退、管理員更正、候補不得簽到。
- 課程整期衍生場次、請假/補課、取消及付款同步。
- 從零 migration 成功，DB 外鍵/索引有效，Flutter 與 Filament 全部使用新契約。

## 尚待核對（不得宣稱已完成）
- 建立 event_booking_groups/event_booking_attendees/event_bookings 的原始 Migration 路徑與所有增量 Migration。
- 完整 Flutter DTO/Client/provider/router、Filament 其他 relation manager、Jobs、通知、Policy、測試。
- course_groups 與 course_terms 是否承擔不同且仍必要的課程業務。
- Invoice 多型關聯的所有交易與退款例外。

## R1.4 原始 Migration 核對補充（2026-10-30）
以下均已直接讀取 main 上的實際檔案，尚未執行資料庫 Migration。
- 2026_11_05_000100_create_course_mvp_tables.php：course_makeups.event_booking_group_id 有 UNIQUE + FK；須同步修改 CourseMakeup Model、CourseEnrollmentService、CashInvoiceService 的相關查詢，不能先刪舊表。
- 2026_11_09_000100_create_course_group_booking_tables.php：invoice_booking_items.event_booking_group_id 有 UNIQUE/FK，course_group_members 有 course_group_id/member_user_id 唯一約束、request_key 冪等、FIFO queue index。這是「系列課程會員」而非一筆多人活動報名，禁止因 group 字眼直接刪除。
- 2026_11_06_000100_create_invoice_payment_foundation.php：invoices.booking_type + booking_id 有唯一索引但沒有實體 FK；payment_transactions 有 idempotency_key、transaction_code 及 provider/external_transaction_id 唯一性。不得只將 invoice.booking_id 當作活動預約外鍵，因 course enrollment 也共用此欄。
- 2026_11_07_000200_create_invoice_refund_audits.php：退款稽核 FK 指向 invoice，不需跟隨活動預約 ID 重命名。
- 2026_11_04_000100_add_event_waitlist_capacity.php：waitlist_capacity 是候補總容量，不是每筆報名人數，必須保留。

## 實作前鎖定事項
1. 找到最初建立 event_bookings/event_booking_groups/event_booking_attendees/event_waitlist_entries/event_attendances 的 migration，確認現有 FK/index/default，不能憑 Model 推斷。
2. 列出 course_terms/course_groups 是否為兩種必要業務；保持 course_group_members 與 event_booking_groups 語意區分。
3. 對 invoices.booking_type 做逐值對照，決定分型 FK 或受控關聯驗證，不可任意改變 01/02 既有語意。
4. CheckinService 的 QR 'a' token 目前指 attendee ID；改 booking ID 前必須同步更改產生/驗證與所有 API 契約及測試。
5. 前台 Flutter 有舊 S6 我的預約頁和 S9 單人預約頁；須檢查 router、provider、API client、tests 以防刪除仍在使用的路由。
6. 已有 Filament 防呆修改僅為過渡：booking_max_seats 欄位仍在 DB，正式完成 schema 之後應刪掉隱藏表單欄位，不可誤以為完成資料模型統一。

## R1.10 階段式交接快照（2026-10-30）

> 本節供下一段 ChatGPT 對話接續使用。狀態是「GitHub 遠端已提交、尚未執行完整測試」，**不是 R1 完成或 CI 通過證明**。

### 固定工作方式與分支
- 使用者已明確選定 **B：ChatGPT 直接在 GitHub 開發**，因 Claude 額度已滿；不要再要求 Claude Code 主開發或重複詢問 A/B。
- Repository：`ilifeup/Shared_Modules_For_App`；唯一開發分支 `refactor/r1-single-booking`。不得自行合併 `main`。
- 已從當時 `main` 建立回退分支 `backup/pre-r1-single-booking`；這是 branch 而非 tag，不能涵蓋尚未推送的本機修改。
- 使用者要求每個階段更新**本文件**，因對話接近容量限制；新對話先讀取本文件、GitHub 最新 branch HEAD、CI 結果，再修改程式。
- 2026-10-30 比較 GitHub 分支時：R1 分支較 main ahead 8、behind 1（main 差異為 README.md）；此數值是當時快照，必須重新查證。

### 已提交修改（按時間順序）
1. `6a54893`：建立本 R1 實作計畫文件。
2. `364d4fe`：Filament CalendarEventResource 過渡性單人限制／欄位顯示調整。
3. `3fe55fa`：補充 R1 依賴與 Migration 審查文件。
4. `f7f8685`：BookingGroupController 初步拒絕舊多人／訪客欄位。
5. `89b0e3b`：擴充禁止欄位清單（後由 allowlist 取代）。
6. `a064cff`、`55fdea0`：BookingGroupController 改為只允許 `client_request_id`、`member_user_id`；未知輸入以 ValidationException 拒絕，修正 namespace。
7. `133366d`：SingleMemberBookingContractTest 新增未知欄位拒絕、不得產生預約的案例。
8. `82fef43`：BookingEngine 共用 `createGroup` 強制 `seat_count=1` 與 `booker_user_id`，`createAttendee` 拒絕 null/非正會員 ID。
9. `8c0bf0d`、`a37ff1f`、`a59ff0d`：BookingEngine `validateRequest` 改用 FILTER_VALIDATE_INT；加入內部非法會員 ID 測試，改用 ApiException.status/errorCode 斷言。
10. `cc38ad4`、`9cc1390`：冪等 replay 也用 FILTER_VALIDATE_INT，新增 replay 異常會員 ID 測試。

### 已確認的技術事實與風險
- BookingEngine 仍同時有 `bookLegacy` (S6) 和 `book` (S9)；兩者寫入 event_booking_groups + event_booking_attendees；S6 另寫 event_bookings。**尚未建立唯一的 event_bookings 單場次契約。**
- `validateRequest` 在 `book` 的 transaction 內執行，但冪等 `replay` 在前段可能先執行；目前已對 replay 會員 ID 加嚴解析，仍須檢查完整資格／授權與取消重試語意。
- `coveredByCourseTerm` 路徑依賴 course_enrollments/course_sessions、確定性 UUID5；重構不可直接移除。
- `course_group_events` 是系列課程入口限制；`course_group_members` 不是舊活動多人預約表，不可只因名稱含 group 就刪除。
- `CashInvoiceService`、`CheckinService`、`CourseEnrollmentService` 及 QR attendee ID、invoice_booking_items、course_makeups 等仍依賴舊 group/attendee ID。
- 新增的測試案例尚未實際執行；不得聲稱 Pest/Flutter/CI 通過。先確認現有測試 fixture、API middleware、資料庫隔離設定。
- 目前零散 hardening commits 過多；後續以 **schema/engine/跨模組整合為單位集中修改與測試**，不要再把單一驗證修補當成 R1 主進度。
- **重要：** 既有計畫的「沒有正式資料」只是一項待核實前提；任何 `migrate:fresh` 或破壞性 migration 前，必須核對實際目標為隔離開發 DB，禁止碰正式資料。

### 下一階段執行順序（接手 AI 必看）
1. 先在 GitHub 查明建立 `event_bookings`、`event_booking_groups`、`event_booking_attendees`、`event_waitlist_entries`、`event_attendances` 的原始 migrations、增量 migrations、索引和 FK；不可從 Model 猜測。
2. 審查 `course_terms` vs `course_groups`、invoice 01/02、退款與多場次課程 invoice_booking_items 的實際關係，明確保留必要功能。
3. 設計並**整批**實作目標 schema 與 Model、BookingEngine/Request、Controller/route、付款/退款、候補、簽到/QR、課程/補課、通知；禁止先刪舊表造成未同步依賴中斷。
4. 同批更新 Filament、Flutter DTO/provider/router/UI、OpenAPI、Pest 與 Flutter 測試；保留 idempotency key 與家庭代管授權。
5. 在隔離 DB 執行從零 migration、Pest、併發／付款退款／候補／簽到／課程測試、Flutter analyze/test、OpenAPI 契約檢查；列出確實執行結果與未覆蓋風險。
6. 完成後再提出 PR 供使用者審核；**不可自動 merge main**。

### 對話交接最低必要資訊
- 最近已知 R1 commit：`9cc1390665d24860ae6e2c39c011122d21a1d3e9`（本節更新前；下次以 GitHub HEAD 為準）。
- 本文件位置：`docs/refactor-r1-single-booking-plan.md`。
- 已有測試：`examples/poc_backend/tests/Feature/S9/SingleMemberBookingContractTest.php`；原有 `M08BookingTest.php` 含 S6/S9 共存情境，正式移除 S6 時須同步改測試。
- GitHub 操作使用既有 GitHub connector；先讀取最新 SHA，再帶 SHA 更新，避免覆蓋其他修改。

## R1.11 階段快照（2026-10-30）

- 新增 Pest 防線測試 `5eff724`：直接呼叫 BookingEngine::book()，涵蓋訪客 booker=null 與兩位會員 attendees，要求分別拒絕為 403 GUEST_NOT_ALLOWED、422 SINGLE_ATTENDEE_ONLY，且不得新增 group/占位。**尚未執行測試。**
- 重新讀取 `BookingGroupController.php`：POST /events/{id}/booking-groups 只允許 client_request_id、member_user_id；GET /me/booking-groups 仍查 event_booking_groups/attendees；cancel 仍走 cancelGroup。這證實目前尚非單一 event_bookings 契約。
- 重新讀取 `BookingEngine.php`：`bookLegacy` 寫 event_bookings + event_booking_groups + attendee；`book` 寫 group + attendee；兩者使用 event row lock 與共用座位計算。遷移時須一次改 S6/S9 寫入、取消、候補、付款、通知。
- GitHub 程式碼搜尋原始 migration 建表字串未返回結果，且 fetch_file 不支援直接讀取目錄。**原始 migration 尚未定位；不得因此假設不存在，也不得先刪表。** 下一階段應透過可列舉 Git tree 的工具或本機 checkout 全量搜尋 `Schema::create`，並以實際檔案核對索引與外鍵。
- 交接時先讀本文件，確認 branch HEAD、CI 和未完成依賴；**嚴禁把新增測試視為測試已通過**。不自動合併 main。

## R1.12 階段快照（2026-10-30）

- `5d30cb1`：BookingEngine::createAttendee 寫入邊界新增檢查，`seat_count` 必須等於 1，且該 group 不能已有 attendee；內部／管理者呼叫也不可追加第二位參加者。此為舊表過渡性防護，**不等於完成 event_bookings 單表重構**。
- 直接檢查最新已知 R1.11 文件 commit `8d558e5`：GitHub PR-triggered workflow runs 回傳空陣列、combined statuses 也為空陣列；這只表示**沒有從該查詢取得 CI 結果**，不能宣稱 CI 綠燈、失敗或已執行。
- 目前仍待：找齊原始 migration、schema 一致性、invoice 01/02 與退款、課程衍生場次、簽到 QR、Flutter/Filament/OpenAPI；應以跨模組批次為單位推進，避免零散小修取代主線。
- 最近已知程式 commit：`5d30cb11c47f0183c93b016991cca6aa0eb6d796`；下次接手以 GitHub HEAD 重新核實。

## R1.13｜資料庫 FK 實際核對（2026-10-30）

本階段已直接從 `refactor/r1-single-booking` 分支讀取四支 migration，以下為**已驗證**的結構，不是依 Model 猜測：

| Migration | 實際 schema/約束 | R1 變更前提 |
| --- | --- | --- |
| `2026_11_04_000100_add_event_waitlist_capacity.php` | `calendar_events` 增補 `waitlist_capacity` | 保留候補總容量，勿誤刪 |
| `2026_11_05_000100_create_course_mvp_tables.php` | `course_sessions.calendar_event_id` UNIQUE + FK；`course_enrollments(course_term_id,user_id)` UNIQUE；`course_makeups.event_booking_group_id` UNIQUE + FK（restrictOnDelete） | 先改 `course_makeups` 及 CourseEnrollment/Makeup service 的引用，再移除 group |
| `2026_11_09_000100_create_course_group_booking_tables.php` | `course_group_events.calendar_event_id` UNIQUE；`course_group_members(course_group_id,member_user_id)` UNIQUE、request_key/FIFO 唯一索引；`invoice_booking_items.event_booking_group_id` FK（restrictOnDelete）且全域 UNIQUE | `course_group_members` 是系列課程會員，不是多人活動預約；`invoice_booking_items` 須改新 event_booking FK |
| `2026_11_06_000100_create_invoice_payment_foundation.php` | `invoices(booking_type,booking_id)` UNIQUE（無實體 FK）；`payment_transactions.transaction_code` UNIQUE、`idempotency_key` UNIQUE、`(provider_code,external_transaction_id)` UNIQUE | 不可直接改 `invoices.booking_id` FK 指向活動預約，因課程類型共用此欄；需先盤點 01/02 全部服務 |

**實際阻擋：** 以上四支都不是建立 `event_bookings`、`event_booking_groups`、`event_booking_attendees`、`event_waitlist_entries`、`event_attendances` 的原始 migration；GitHub connector 的 `fetch_file` 不接受目錄，程式碼搜尋未回傳建表字串。需使用可列舉 Git tree 的途徑，或在授權開發環境 checkout 後 `rg -n "Schema::create\('(event_bookings|event_booking_groups|event_booking_attendees|event_waitlist_entries|event_attendances)'" examples/poc_backend/database/migrations`；找不到前不可破壞性修改。

**CI 查核：** 最近文件 commit `8d558e5` 的 workflow runs / statuses 查詢均回傳空集合，不能推定測試通過。尚未實際執行 Pest、Flutter、Migration。

**後續原則：** 下一階段先找到五張核心表的建表 migration；再形成完整 FK 變更清單，集中實作 schema/Model/Service/API/Flutter/OpenAPI/Tests；不可逐張刪表。不要動正式 DB 或合併 main。

## R1.14｜候補晉升資料一致性修正（2026-10-30）

- `117cb3b`：BookingEngine::promote 在發放候補名額前，除既有單人、會員驗證、家庭授權檢查，新增 **group.calendar_event_id、attendee.calendar_event_id 均須符合目前活動**、member_user_id 不可為 null；歷史資料異常時標記 invalid_waitlist_entry 並走原有跳過／取消／稽核流程，不可晉升到錯誤活動。
- `7d101a5`：SingleMemberBookingContractTest 新增跨活動 attendee ledger 損壞情境：一人已確認、一人候補；候補 attendee 的 calendar_event_id 被修改成其他活動，取消前者後，後者不得晉升，應取消且不占原活動名額。**尚未實際執行 Pest。**
- 已確認 `tests/Pest.php` 使用 `RefreshDatabase`，並以 `s9Event` 建立活動、`seats` 查確認名額；原 fixture 仍保留過渡性 `booking_max_seats=5`（不是目標 schema）。
- 核心資料庫 Migration 原始建表檔仍未定位；不能以本階段防護取代 R1 統一 `event_bookings`。需要 Git tree 列舉／完整 checkout，才能安全修改 migration 與 FK。
- 最近已知程式 commit `7d101a58acb074d388bce3fcabeeed748db43e47`；以 GitHub HEAD 為準。未執行 CI、未合併 main。


## R1.16｜核心原始 Migration 已定位（2026-10-30）

本階段直接從 GitHub commit 歷史追溯並讀取 R1 分支的兩支原始 Migration；解除 R1.13–R1.15 所記「原始建表檔未定位」阻擋。此為靜態查核，尚未執行資料庫測試。

1. `examples/poc_backend/database/migrations/2026_10_30_000100_s6_event_bookings.php`（原始引入 commit `f66697c5`）：建立 `event_bookings`，`calendar_event_id` FK restrict、`user_id` FK cascade、`status` confirmed/cancelled、`active` nullable，UNIQUE(calendar_event_id,user_id,active)；取消歷史依 NULL 允許重複。此唯一索引只覆蓋 S6 舊預約，不能直接當作新 R1 跨 S6/S9 單人唯一性已完成。
2. `examples/poc_backend/database/migrations/2026_11_01_000100_s9_modules.php`（原始引入 commit `a557a623`）：建立 `event_booking_groups`（含 legacy_booking_id FK 指向 event_bookings、booker_user_id）、`event_booking_attendees`（group FK cascade、calendar_event_id FK restrict、member_user_id nullable FK nullOnDelete）、`event_waitlist_entries`（event_booking_group_id UNIQUE FK cascade）、`event_attendances`（event_booking_attendee_id FK restrict、event+attendee UNIQUE）、`event_attendance_corrections`（attendance FK cascade）。
3. 已確認五張核心表的原始 Migration 路徑，接下來須讀取上述 S9 Migration 第 190–295 行的完整欄位／索引、後續所有增量 Migration，並逐一核對 Model/Service 的 FK 引用，才能開始 schema 整批修改。
4. 特別注意：S9 Migration 的 event_attendances 是指向 attendee 而非 group；不能只把 group_id 重新命名成 booking_id。S6 的 user_id 也需與新 member_user_id/booker_user_id 語意整合。
5. 本階段沒有修改應用程式碼、沒有執行 Pest/Flutter/CI、沒有操作 DB，也沒有合併 main。下一階段進入完整 FK/索引與增量 Migration 核對，之後集中實作。


## R1.17｜原始欄位／索引／FK 核對完成第一批（2026-10-30）

已直接讀取 R1 分支原始 Migration 內容（靜態審查，非 migrate 成功證明）：

- S6 `2026_10_30_000100_s6_event_bookings.php`：`event_bookings` 原欄位 calendar_event_id、user_id、status、active、booked_at、cancelled_at、cancelled_by、timestamps；UNIQUE(calendar_event_id,user_id,active)。取消以 active=NULL 保留歷史。
- S9 `2026_11_01_000100_s9_modules.php` 第 194–247 行：groups 含 legacy_booking_id UNIQUE FK、booker_user_id nullable、is_guest/contact_*、seat_count、client_request_id UNIQUE、status/時間/取消資訊；attendees 含 group_id FK cascade、event_id FK restrict、member_user_id nullable FK nullOnDelete、name/status/active，UNIQUE(calendar_event_id,member_user_id,active)；waitlist_entries 含 group_id UNIQUE FK cascade、UNIQUE(event_id,sequence)、(event_id,status,sequence) 索引。
- 同檔第 266–294 行：event_attendances.event_booking_attendee_id FK restrict，UNIQUE(calendar_event_id,event_booking_attendee_id)，status/source/簽到簽退時間與操作者；event_attendance_corrections.event_attendance_id FK cascade。
- `2026_11_05_000100_create_course_mvp_tables.php`：course_makeups.event_booking_group_id UNIQUE FK restrict；course_sessions.calendar_event_id UNIQUE FK；course_enrollments(course_term_id,user_id) UNIQUE。
- `2026_11_09_000100_create_course_group_booking_tables.php`：invoice_booking_items.event_booking_group_id FK restrict 且全域 UNIQUE；course_group_members 的 member、request_key、FIFO 唯一約束應保留。
- `2026_11_06_000100_create_invoice_payment_foundation.php`：invoices.booking_type/booking_id 複合 UNIQUE 無實體 FK；payment_transactions.invoice_id FK restrict，transaction_code、idempotency_key、provider/external_transaction_id 唯一性需保留。

### 新契約前的具體注意事項

1. 不可直接在 S6 `event_bookings` 上只新增 member_user_id 而保留舊 (calendar_event_id,user_id,active) UNIQUE 作為唯一防線；S9 原本靠 attendee 表的獨立唯一約束，兩者須合一並保留取消後再預約。
2. 待付款、候補、取消、活動取消及歷史須對應同一預約主鍵；client_request_id 冪等與 course term deterministic key 需核對，不可丟失。
3. 新 event_booking_id FK 必須同批覆蓋 waitlist、attendance、course_makeups、invoice_booking_items；attendance corrections 仍引用 attendance 主鍵，不必更動。
4. 發票 `booking_type=01/02` 是兩種業務，不可直接將所有 invoices.booking_id 加 FK 指向 event_bookings；保留付款交易與退款稽核關係。
5. 以上是**第一批原始建表核對**；仍須盤點後續增量 Migration 與全量程式引用，尚未開始 schema 修改、未執行測試或 CI。


## R1.18｜後續增量 Migration 實際核對（2026-10-30）

已從 GitHub commit 歷史定位並直接讀取下列 R1 分支 Migration（靜態審查）：

- `2026_11_02_000100_remove_guest_bookings.php`：先清除 guest / booker_user_id=NULL / member_user_id=NULL 的舊預約與相關簽到、候補、通知；刪除 event_categories.allow_guest_booking、groups.is_guest/claimed_at；**保留 groups.contact_email/contact_phone**；移除原 nullOnDelete FK 後將 groups.booker_user_id、attendees.member_user_id 改 NOT NULL + restrictOnDelete。production 有 `GUEST_PURGE_BACKUP_VERIFIED=yes` 閘門；MySQL DDL 不受 row-delete transaction 回滾保護；down() 不可逆。R1 只允許隔離開發 DB，禁止因重構繞過保護。
- `2026_11_08_000100_remove_invoice_number_unique_constraints.php`：依 Schema::getIndexes 判斷，移除 invoices.invoice_no 及 invoice_number_corrections.old_number 的舊唯一索引；down() 刻意不恢復唯一性。R1 必須保留可編輯、非唯一發票號碼政策。

### 對 R1 目標 Schema 的直接影響

1. S9 原始 Migration 的 guest/nullable member 定義**不是**完整 migrate 後的最終契約；不能以原始 Schema 誤判目前可允許訪客或無會員預約。
2. `contact_email/contact_phone` 仍在已部署後的 group Schema；R1 移除舊 group 時須確認合法會員通知／收款／顯示資料已由 user/member/profile 取得，不可只刪欄位。
3. Migration 的不可逆資料清除與 production 備份閘門是既有安全設計；R1 只能對隔離 DB 做 fresh，不得執行正式環境破壞性變更。
4. 發票唯一性須保留 invoices(booking_type,booking_id)、invoice_code、付款冪等索引；不得恢復 invoice_no 的唯一索引。
5. 本階段只核對兩支增量 Migration；其他增量與全量引用仍需持續檢查。未執行程式測試、Migration 或 CI。


## R1.19｜核心 Service/Model 相依引用實查（2026-10-30）

本階段直接讀取 R1 分支以下七支程式，並非單憑檔名推估：

| 檔案 | 目前已核對的舊契約 | 集中修改時必須同步處理 |
| --- | --- | --- |
| `app/Services/Booking/BookingEngine.php` | bookLegacy() 寫 event_bookings 並同步 group/attendee；book() 回傳 EventBookingGroup；confirmedSeats、activeAttendeeOf、cancelGroup、expireCashHold、promote 依 attendee/group/候補 | 單一 booking 寫入與鎖、active 唯一性、FIFO、付款到期、取消、家庭代管、舊入口相容 |
| `app/Services/Payment/CashInvoiceService.php` | forEvent() 接收 EventBookingGroup；發票 01 對應 group ID；課程付款查 event_booking_groups、course_makeups.event_booking_group_id | 將事件發票及課程衍生場次對應到新 booking ID，保留 02 課程 enrollment 發票、冪等、退款 |
| `app/Services/Course/CourseEnrollmentService.php` | 使用 EventBookingGroup 查衍生場次；invoice booking_type 02；整期取消／候補 | 課程衍生 booking 與單場次 booking 共用新主鍵，不破壞 course term/enrollment FIFO |
| `app/Services/Checkin/CheckinService.php` | EventBookingAttendee 是查詢、鎖、簽到、簽退及管理員更正的主體；event_attendances.event_booking_attendee_id | 直接使用 EventBooking；QR 舊 attendee token 需版本化或失效；簽到併發鎖及更正稽核保留 |
| `app/Models/EventBookingGroup.php` | legacyBooking() 指向 EventBooking；attendee()/attendees() 指向 EventBookingAttendee | 過渡相容後移除，避免殘留舊 relation |
| `app/Models/EventBookingAttendee.php` | member_user_id、group FK、attendance relation | 會員與出席直接關聯新 EventBooking |
| `app/Models/EventWaitlistEntry.php` | event_booking_group_id fillable 與 belongsTo(group) | 改 event_booking_id UNIQUE FK 及 belongsTo(EventBooking) |

**依賴次序：** 先建立最終 schema／Model 的完整欄位與外鍵對照，再以同一整合批次處理 BookingEngine + CashInvoiceService + CourseEnrollmentService + CheckinService + controllers/requests/notifications；同步更新 Flutter、Filament、OpenAPI、Pest。任何一個 Service 單獨換主鍵都會產生破壞性不相容。

**尚未核對：** 所有 Controller/Request、其他 Model、Jobs/Notification、Flutter DTO/provider、Filament relation managers 與全量增量 Migration；本輪未執行測試或修改核心程式。下一階段應優先以 repository 檔案樹或逐檔實讀完成引用清單，然後進入集中實作；禁止僅追加文件就宣稱重構完成。


## R1.20｜預約歷史 API 第一批程式／測試修改（2026-10-30）

- 程式 commit `2ba63a1`：`BookingGroupController::mine` 接受 `status=event_cancelled` 並精確過濾；原 `status=cancelled` 仍同時涵蓋 cancelled/event_cancelled。
- 測試 commit `b24f8d7`：`SingleMemberBookingContractTest` 新增活動取消歷史明確查詢及取消彙總查詢的回歸案例。
- 本階段已實際修改 PHP controller 與 Pest test，**但尚未執行測試**，不得宣稱通過。此修正是 R1 過渡期的局部契約修復，並非 event_bookings 主鍵統一完成。
- 下一批仍需一次處理新 Schema、BookingEngine、付款／課程／簽到及 Flutter/OpenAPI；不得以此小修代替主重構。未合併 main。


## R1.21｜跨活動候補誤取消防護（2026-10-30）

- 程式 commit `816cb601`：`BookingEngine::promote` 發現候補 head 指向的 group.calendar_event_id 不屬於目前活動時，仍標記本活動的 queue entry 為 skipped，但**不呼叫 endGroup**，避免錯誤取消另一活動的預約、發票、參加者狀態或觸發跨活動同步。
- 測試 commit `b10cf42d`：新增跨活動 queue/group 不一致情境；取消第一位確認會員釋出名額後，錯誤 queue 必須 skipped，另一活動 group 仍維持 waitlisted。
- 已實際修改 BookingEngine 與 Pest 測試；**尚未執行測試／CI**。不代表完成 R1 主 Schema 統一。未合併 main。


## R1.22｜現金保留逾期的活動歸屬防護（2026-10-30）

- 程式 commit `cfbd53d5`：`BookingEngine::expireCashHold` 取得事件鎖並重新讀取 group 後，新增 `group.calendar_event_id === locked event.id` 條件，避免讀取候選 group 到取得鎖之間發生事件歸屬變更時仍更新 invoice_status、取消預約與觸發候補晉升。
- 本次只修正既有 S9 引擎的保護條件；不改正式資料庫，不代表完成單一 event_bookings 主表遷移。尚未執行 Pest、CI 或 MySQL 測試；此邊界仍需補齊交易競態回歸測試。
- R1 主要工作仍是 schema/booking/payment/course/checkin/API/Flutter 一致切換；不得將過渡期防護修正誤列為重構完成。


## R1.23｜統一預約 Model 狀態契約起步（2026-10-30）

- `42cf3510`：`app/Models/EventBooking.php` 新增 STATUS_WAITLISTED、STATUS_EVENT_CANCELLED，及 `isActive()`（confirmed/waitlisted 且 active=1）。保留原 isConfirmed()、user()、event()，避免尚未遷移 S6 時破壞舊讀取。
- `bb92c1db`：新增 EventBooking 狀態／active 欄位的 Pest 測試，確認取消及活動取消不視為有效預約。
- 本次為目標 Model 契約的**非破壞性程式準備**，不是 schema 切換；尚未執行測試／CI，未合併 main。下一步應建立一次性整合 schema 改造及所有依賴切換，避免長期停留於過渡模式。


## R1.24｜新增 canonical event_bookings 第一階段 Migration（2026-10-30）

- `f159782c`：新增 `database/migrations/2026_11_10_000100_prepare_canonical_event_bookings.php`，為既有 S6 event_bookings **加法式**加入 member_user_id、booker_user_id（nullable FK users restrictOnDelete）、client_request_id（UUID UNIQUE）、confirmed_at、cancel_reason；建立 UNIQUE(calendar_event_id,member_user_id,active) 與 booker/history 索引。
- 以既有 S6 user_id 回填歷史列的 member_user_id/booker_user_id，confirmed 狀態的 confirmed_at 回填 booked_at；保留既有 ID、user_id 與舊 unique，並提供反向移除新增欄位／索引的 down()。
- `f1002a35`：增加 Pest Schema 欄位存在性回歸測試。**尚未執行測試或 Migration**。
- **重要限制：** 目前舊 S6/S9 引擎仍可寫入沒有 canonical member/booker 的新資料；本 Migration 尚未搬移 S9 groups/attendees，也未將新增 FK 改 NOT NULL，**不得宣稱已達成單一預約主表**。後續須在同一協調批次完成歷史 S9 資料轉換、寫入路徑、外鍵依賴與唯一性、舊欄位清理。
- 只允許隔離開發 DB 驗證；正式環境禁止直接執行未審核的破壞性切換。未合併 main。


## R1.25｜S6 入口雙寫 canonical event_bookings 欄位（2026-10-30）

- `496e239c`：EventBooking Model fillable 新增 member_user_id、booker_user_id、client_request_id、confirmed_at、cancel_reason；confirmed_at datetime cast。
- `c37a4ec9`：BookingEngine::bookLegacy() 在原有 S6 transaction 中將 member_user_id/booker_user_id 設為會員 ID，並將 booked_at/confirmed_at 設為同一時間；保留 user_id 以維持舊契約。
- `f418ff0c`：Pest 增加 S6 建立預約後檢查 canonical 身分欄位與 confirmed_at 的回歸測試。
- **目前僅 S6 寫入雙寫**，S9 仍以 group/attendee 為權威，未搬移 S9 歷史資料，尚未切換候補／付款／課程／簽到外鍵。這些步驟須集中協調以避免雙權威資料漂移。
- **Migration/Pest/CI 未執行**；沒有合併 main 或操作正式資料庫。


## R1.26｜決策變更：全新資料庫、不要舊資料相容（2026-10-30）

**此節優先於 R1.24、R1.25 的歷史過渡規劃。** 使用者明確確認「沒有舊資料！簡單設計就好，不要過度設計」。因此最終採用全新隔離開發資料庫，以 `migrate:fresh` 重建；不執行歷史 S6/S9 回填、雙寫過渡期維護、資料搬移或正式環境資料清理。

- `4f5c91a9`：將 canonical 欄位直接合入初始 `2026_10_30_000100_s6_event_bookings.php` Migration。
- `69a7ff78`：刪除 `2026_11_10_000100_prepare_canonical_event_bookings.php` 過渡 Migration。
- **尚未完成**：目前舊 S6 `user_id` 與 S9 group/attendee 還存在，為了保持未完成重構分支可讀，下一步須集中修改 BookingEngine、check-in、payment、course、API、Filament、相關 Migration 和 Pest，最後移除所有舊表／欄位，不能將目前暫留視為新決策已完整落地。
- 不觸碰正式資料庫；尚未執行 migrate:fresh／Pest／CI；未合併 main。


## R1.27｜實際預約生命週期測試補強（2026-10-30）

- `9a4b451a`：修正已過時的「additive migration 回填」測試說明，改檢查新建 schema 直接具有 canonical 欄位。
- 新增 S9 API 預約→event_bookings canonical row→取消清除 active 的整體回歸測試，核對 member/booker/client_request_id/status/cancelled_at。
- **重要：** 目前測試是對既有 S9 過渡寫入路徑的契約檢查，仍引用 group 表，**不是最終單表實作**。依使用者最新指示，最終不需舊資料或雙寫；下一階段要移除 group/attendee 及相關外鍵依賴，不應繼續增加相容層。
- 尚未執行測試、CI 或資料庫重建，未合併 main。


## R1.28｜Canonical member/booker 關聯與候補測試（2026-10-30）

- `e0911030`：EventBooking Model 新增 member()（member_user_id）及 booker()（booker_user_id）關聯，支援家庭代報名時的身分區分。
- `7f79a012`：新增 S9 候補建立、前一位取消後晉升、canonical event_bookings waitlisted→confirmed 的 Pest 回歸測試。
- `8aba531b`：新增委託預約的 member/booker 關聯測試。
- 查詢最新 Commit 的 PR workflow runs 回傳空清單；未證實 CI 已執行。Pest 尚未執行，未合併 main。
- **R1 最終單表仍未完成**：舊 group/attendee、付款、課程、簽到、API 依賴尚待協調切換；不要把上述準備與測試當成已完成。


## R1.29｜活動確認名額改以 event_bookings 為權威（2026-10-30）

- `7e7e5835`：BookingEngine::confirmedSeats() 改由 `event_bookings` 計算 `status=confirmed AND active=1`，不再以 `event_booking_attendees` 計算已佔用名額；同時更新服務註解。
- `96e4e710`：新增 Pest 測試，確認舊 attendee 狀態不影響 canonical 名額數，而取消 canonical 預約會釋放名額。
- **整體切換仍未完成**：目前 activeAttendeeOf()、候補 queue、check-in、付款及課程仍有舊表依賴。因使用者已明確無舊資料、要求簡單設計，最終需刪除舊表與相容程式，不應保留雙寫作為完成狀態。
- 尚未執行 Pest、CI、migrate:fresh；未操作正式資料庫，未合併 main。


## R1.30｜候補名額改由 canonical event_bookings 計算（2026-10-30）

- `1285cd10`：BookingEngine::waitingGroups() 改以 `event_bookings.status=waitlisted AND active=1` 計數，候補容量不再依賴 event_waitlist_entries.status=waiting 的筆數。
- `569ecd29`：新增 Pest 回歸測試，驗證舊 queue 狀態變化不改變 canonical 候補數量、canonical 預約取消釋放候補容量。
- 尚未完成的部分：FIFO sequence／晉升仍由 event_waitlist_entries 管理；重複預約檢查仍有 attendee 依賴，付款／課程／簽到也仍引用舊表。最終目標仍為無歷史資料、單一 event_bookings 預約主表，不保留過渡雙寫。
- Pest、CI、migrate:fresh 尚未執行；未合併 main，未操作正式資料庫。


## R1.31｜重複預約檢查改查 canonical event_bookings（2026-10-30）

- `0ef91d6e`：BookingEngine::activeAttendeeOf() 改以 `event_bookings.calendar_event_id + member_user_id + active=1 + status IN (confirmed,waitlisted)` 查詢；回傳型別改為 EventBooking，現有兩個呼叫點僅判斷 null 與否。
- `8b3a0d57`：新增 Pest 測試，驗證 attendee ledger 即使狀態變動，canonical 有效預約仍阻擋重複報名；canonical 取消後查詢回 null。
- 仍待：將 FIFO queue、付款／退款、課程、簽到、API、Migration 全部切至單表後移除舊 group/attendee。使用者已確認無舊資料，禁止擴張歷史相容層。
- 未執行 Pest、CI、migrate:fresh；未操作正式資料庫，未合併 main。


## R1.32｜候補 FIFO 序號寫入 canonical booking（2026-10-30）

- `2b9aceb4`：全新 event_bookings schema 新增 nullable waitlist_sequence 及候補排序索引。
- `c66db3b8`：S9 候補建立時，以 canonical booking 的 max(waitlist_sequence)+1 指派序號，並寫入原有 queue entry（暫時維持下游相容）。
- `6910bbfd`：管理員調整舊 queue 序號時同步更新 canonical booking 的 waitlist_sequence。
- `65cca0b7`：新增 Pest 測試，驗證兩位候補會員在 canonical booking 的順序為 1、2。
- **尚未完成**：promote() 仍以舊 event_waitlist_entries 選擇候補首位；尚未刪除 group/attendee/queue 舊表。下一步須將晉升與管理員排序直接改以 canonical waitlist_sequence 運作，並處理 invoice/course/check-in 的外鍵切換。避免擴充舊表相容層。
- 尚未執行 Pest、CI、migrate:fresh；未操作正式資料庫，未合併 main。


## R1.33｜PR #63 Backend CI 診斷與格式修正（2026-10-30）

- CI Run `41030671872`（S1 PoC Backend #1282，對應 HEAD `5fac3a18`）最終 Gate 失敗；完整 job log 顯示 `pint=failure`，其餘 `pest/migrate/openapi/audit/e2e/concurrency/booking_concurrency/s9_concurrency/m11_concurrency/course_concurrency/rollback=success`。注意診斷步驟本身顯示 success 不等於所有檢查通過，應以 Gate outcomes 為準。
- Pint 唯一指出 `examples/poc_backend/tests/Feature/S9/CourseEnrollmentServiceTest.php` 格式問題，涉及 `class_attributes_separation`、`fully_qualified_strict_types`、`ordered_imports`。
- `bd323ae401ffe09a827b95c6d34412d0c883d5ab`：直接在 PR #63 分支修正測試檔案格式（補方法間距、Carbon import 與引用、移除多餘尾端空行）；未改業務邏輯。
- 查詢該新 SHA 的 PR workflow runs 回傳空清單，**新提交尚無已驗證 CI 結果**。下一步確認新 HEAD CI、Pint/Pest；尤其審查接近開課付款期限、開課後拒絕報名與交易回滾，必要時再集中修正。
- R1 canonical 單表重構仍未完成，不能將本次 CI 修正當成整體完成；不得自動 merge main，不操作正式資料庫。
- 後續每階段須同步更新本計畫書：實際變更、SHA、測試結果、剩餘風險、下一步。


## R1.34｜CI 等待期間並行補強開課後回滾驗收（2026-10-30）

- `b8e5297ec9b65f39538450bc0af979b43cf0b25d`：擴充 `CourseEnrollmentServiceTest.php` 開課後拒絕報名測試，除 enrollment/invoice 為零外，亦要求 canonical `event_bookings`、過渡 `event_booking_groups`/`event_booking_attendees` 及 `payment_transactions` 均無殘留，防止失敗路徑留下部分寫入。
- 提交前 CI 狀態（對 `d1f1f99c`）：S0 Documentation success；Backend pending；Mobile queued；Shared Core in_progress。這是當時快照，不代表本提交 CI 結果。
- **未驗證**：新增測試尚待新 HEAD CI/Pest 執行。仍須完成 FIFO、付款、課程、簽到、Migration 與 Flutter/Filament 對 canonical 單表的整體切換；目前舊表斷言僅保護過渡期一致性，非保留舊表的最終決策。
- 未合併 main、未操作正式資料庫。


## R1.35｜候補排序拒絕時的雙資料狀態保護（2026-10-30）

- `8cb14bd0837640d3e4eafb728b89fc6e92da6e2b`：補強 `SingleMemberBookingContractTest.php` 的重複 canonical FIFO 序號拒絕案例。操作前保存 canonical `event_bookings.waitlist_sequence`，操作失敗後確認 canonical 序號完全不變；原本舊 queue 序號不變的斷言仍保留。
- 目的：在過渡階段避免管理員排序失敗時造成新舊 FIFO 狀態不一致；此測試**不代表**已完成候補流程單表化。
- 提交時前一 HEAD `1a9fce8d` 的 S0 文件檢查 success；Backend/Mobile pending、Shared Core in_progress（當時快照）。本次新增測試的 CI/Pest 尚未驗證。
- 下一批：統一 canonical 候補晉升、管理員排序及下游付款/通知依賴，分階段移除舊表；需保持原有 FIFO、併發、回滾與審計語意。不得自動合併 main，不操作正式資料庫。


## R1.36｜Canonical FIFO 晉升的佇列狀態一致性（2026-10-30）

- `c6b02aa07d46740f341ebc97ba1972ac66a82fe9`：補強 `SingleMemberBookingContractTest.php` 的 canonical FIFO 晉升案例。故意使 canonical 排序與舊 queue 排序不同，取消原已確認預約後，除了驗證 canonical 第三位晉升／第二位等待，也驗證**被選中**的舊 queue 狀態為 `promoted`，未選中的仍為 `waiting`，避免跨資料表狀態錯置。
- 新增測試尚未取得本次 HEAD CI 結果。先前 `6721c918` 的 S0 Documentation success，Backend/Mobile pending、Shared Core in_progress（查詢當時）。
- 這是過渡期回歸防護，不是完成單表切換。後續仍須移除晉升對舊 queue/group/attendee 的依賴，並同步付款、通知、課程、簽到、Migration 與 Flutter 契約。
- 不操作正式 DB，不自動合併 main。


## R1.37｜管理員 FIFO 排序與下游佇列順序一致性（2026-10-30）

- `868aa9274e7c3a6d35dc2e1a693084827c3a9498`：擴充 `SingleMemberBookingContractTest.php` 管理員重排候補案例；canonical 第二位移至首位後，除核對 `event_bookings.waitlist_sequence` 為 1/2，亦檢查下游過渡 `event_waitlist_entries` 依 sequence 排序的 group ID 與 canonical 一致。
- 此測試僅保護過渡階段的新舊佇列一致性；不是認可長期保留雙表。新提交 CI/Pest 尚未驗證。
- 提交前 `9f2b9a33` 的 S0 success、Shared Core in_progress、Backend pending、Mobile queued（當時快照）。
- 下一階段應將 queue 消費者逐一改讀 canonical booking，並同步處理付款、通知、簽到及 migration 的舊 FK；仍不可自動合併 main 或碰正式 DB。


## R1.38｜候補晉升 canonical 身分交叉驗證（2026-10-30）

- `4c88586ed363c819adb71d40bb5108a9a1c2917c`：`BookingEngine::promote()` 在晉升前鎖定 canonical `event_bookings`，檢查同活動、有效候補、`member_user_id` 與 attendee 一致、`booker_user_id` 與 group 一致；不一致以 `canonical_booking_mismatch` 跳過，避免授予錯誤會員名額。
- `5f3fc7c8e88ca3bfbe37a1394819836a82b4e94d`：新增 `SingleMemberBookingContractTest.php` 身分不一致案例，模擬 canonical 會員 ID 被更改後釋出名額，要求候補被取消、舊 queue 標記 skipped 且不占用席次。
- **未驗證**：新增測試尚待 Backend CI/Pest 實際執行。此為過渡期防錯，不是舊表移除完成；後續須直接以 canonical booking 為唯一晉升來源並重構下游付款、通知、簽到、課程及 migration。
- 查詢前一 HEAD `de7651b8` 時 S0/Shared Core 執行中，Backend/Mobile pending；勿視為本次提交的結果。未合併 main，未操作正式 DB。


## R1.39｜共用取消入口 canonical 身分限制（2026-10-30）

- `b7b2fc4de1729a2c89636be44fccecc1e8ee1e28`：`BookingEngine::endGroup()` 依 group 指標取消 canonical 預約前，額外限定 `calendar_event_id`、`booker_user_id` 與 group attendee 的 `member_user_id` 一致，並對符合條件的 canonical row 加鎖。避免過渡期舊 FK 錯誤時直接取消其他會員或其他活動預約。
- `4b471ddcf2603aad305829127e8203fd6f86f341`：延伸 canonical 身分錯置的候補案例，驗證舊 group 被取消、queue skipped 時，錯置 canonical row 不會被連帶取消。
- **風險**：不一致的 canonical row 可能保留有效狀態，須在後續資料修復／單表切換時建立明確隔離或清理流程；此防護不代表最終狀態已一致。新提交的 Backend/Pest 尚未驗證。提交前舊 HEAD `768f3239` 的 S0 與 Shared Core success，Backend/Mobile in_progress；新提交可能觸發新的 CI 並取消舊 run。
- 後續優先處理 canonical 為唯一真實來源、錯誤關聯隔離、invoice/course/check-in FK 與 API/migration 清理。未合併 main，未操作正式 DB。


## R1.40｜Shared Core M08 失敗案例修復（2026-10-30）

- HEAD `5490441f` 的 Shared Core workflow `41032350525`，`m08-backend-contract` job 失敗：`SingleMemberBookingContractTest.php:892` 原代訂者取消案例，期望 cancelled、實際 confirmed；測試摘要 1 failed、104 warnings、575 assertions。此案例僅修改 canonical member_user_id，未同步舊 attendee ledger，與 R1.39 的身分一致性防護衝突。
- `439ca2792ae3113279e6a99d241031a9fae1e073`：修正測試 fixture，使 canonical 與 attendee 的參加會員相同，仍保留 S6 舊 user_id 作為原報名者；測試原報名者可合法取消，不放寬 R1.39 的防錯保護。
- 注意：此修正尚待新 HEAD CI 驗證。先前 S0 success、Shared Core php/dart/flutter success，但 m08-backend-contract failure；Backend/Mobile 當時仍在執行。頻繁提交可能取消前一輪 workflow，應以新 HEAD 結果為準。
- 尚待：canonical 單表為唯一來源，移除舊 queue/group/attendee，完成 invoice/course/check-in/API/migration；未合併 main，未操作正式 DB。


## R1.41｜取消前身分一致性防護（2026-10-30）

- 上一版 HEAD `026b9e16`：S0、Shared Core（含 M08）、Backend Gate 皆 success，Mobile E2E success；Android APK 編譯尚未完成。
- `d145c21f3863a15c7a737d6194704660d649df6a`：S6 `cancelLegacy()` 在變更 invoice/group/attendee 之前驗證 canonical event/booker/member 與舊群組、參加者一致；不一致回傳 409 `CANONICAL_BOOKING_MISMATCH`，由既有 transaction 回滾。
- `bed4e9e38e3f16ae9e999750dd917c867185c4e3`：新增 canonical member pointer 損壞的取消回歸測試，要求 canonical/group/attendee 保持 confirmed。
- **驗證狀態：新提交尚待 CI。** 本階段僅封鎖 S6 取消入口的部分更新風險；`endGroup()` 其他呼叫路徑、異常候補跳過及 invoice 生命週期仍需完整檢查，不能宣稱單表重構完成。
- 未合併 main，未修改正式 DB。
