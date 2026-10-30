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
