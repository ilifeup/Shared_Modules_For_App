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
