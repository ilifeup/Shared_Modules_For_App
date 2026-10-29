# Stop Github online -> offline will be faster for開發.
2026-10-29 PM2200轉Ubuntu

# Shared Modules for Three Apps

Reusable Flutter, Laravel API and Filament Web Admin modules for three **independent** applications: Tiandi (first integration), Wuxiang and EachCore.

- Requirements and module specifications: [docs/shared_modules_list.md](docs/shared_modules_list.md)
- Original requirement baseline: [SPEC](docs/specs/three_apps_shared_modules_spec_v1.md)
- Original proof-of-concept acceptance baseline: [POC](docs/specs/three_apps_poc_acceptance_plan_v1.md)
- Agent instructions: [AGENTS.md](AGENTS.md), with [CLAUDE.md](CLAUDE.md) as Claude-specific entry point.

**Status:** S1 PoC implemented in `examples/` (see below); evidence is the CI run linked from the PR. Not production-ready; no module is accepted for App integration until the S1 gates pass. Each application owns its own authentication, database, deployment and business-specific rules. WooCommerce remains the EachCore transaction source of truth.

Work is performed through reviewed pull requests and verifiable CI. No deployment or production-data migration is authorized by this documentation.

## S1 PoC 快速啟動

S1 垂直切片：Flutter App → Laravel API（Sanctum）→ MySQL ← Filament 後台。

| 目錄 | 內容 |
|---|---|
| `packages/laravel/shared_core` | 可移植 PHP 規則：設定驗證、SMS Provider 介面、公告可見性 |
| `packages/flutter/shared_core` | 可移植 Dart 契約：Session／Member／Registration API、DTO |
| `examples/poc_backend` | 可啟動的 Laravel 13 + Sanctum + Filament 5 + Spatie PoC 主機（以 path repository 使用 shared_core） |
| `examples/poc_flutter` | 可啟動的 Flutter 3.47 Android PoC（以 path dependency 使用 shared_core） |

### 後端（PHP 8.4、Composer、MySQL 8.4）

```bash
cd examples/poc_backend
composer install
cp .env.example .env            # 設定 DB_CONNECTION=mysql 與該 App 專用的資料庫
php artisan key:generate
php artisan migrate
php artisan app:create-admin you@example.com   # 每個 App 唯一一位 Admin，互動輸入密碼（至少 12 字）
php artisan db:seed                            # 選用：示範會員與公告（僅限本機）
php artisan serve                              # API: http://127.0.0.1:8000/api/v1 ，後台: /admin
```

- Email 驗證信：本機預設 `MAIL_MAILER=log`，驗證連結寫在 `storage/logs/laravel.log`。正式寄信需設定 `MAIL_*`。
- 手機 OTP：預設「測試模式」，不發送真實簡訊；非正式環境的驗證碼寫在 `storage/logs/laravel.log`（`[FAKE SMS]`）。
- 測試：`php artisan test`（Pest）、`vendor/bin/pint --test`、`bash scripts/e2e_api_smoke.sh`（需已 migrate 的資料庫）。

### Flutter（Flutter 3.47.x）

```bash
cd examples/poc_flutter
flutter pub get
flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000     # Android 模擬器連本機後端
flutter test                                                      # widget / unit 測試
flutter build apk --debug --dart-define-from-file=env/dev.json
```

實機請改用手機可連到的 HTTPS 位址。Debug 版允許 http（只限 debug build）。

### API（`/api/v1`，成功 `{data, meta?}`，失敗 `{error:{code,message,fields?,request_id}}`，回應帶 `X-Request-Id`）

| Method | Path | 說明 |
|---|---|---|
| POST | `/auth/register` | 自行註冊：`name`、`email` 及／或 `phone`（台灣手機）、`password`、`password_confirmation` |
| GET | `/email/verify/{id}/{hash}`（web，簽章連結） | Email 驗證連結 |
| POST | `/auth/email/resend` | 重寄驗證信（不洩漏帳號是否存在） |
| POST | `/auth/phone/send-otp` | 發送手機 OTP（限額、冷卻） |
| POST | `/auth/phone/verify` | 驗證 OTP（雜湊儲存、單次使用、錯誤上限） |
| POST | `/auth/login` | `identifier`（Email 或手機）+ `password` → Sanctum Bearer token |
| POST | `/auth/logout` | 撤銷目前 token |
| GET | `/me`、`/me/profile` | 自己的資料 |
| PATCH | `/me/profile` | 只可改 `display_name` |
| GET | `/notices`、`/notices/{id}` | 已發布且到達發布時間的公告 |

### Filament 系統設定（`/admin/system-settings`，存入 `app_settings` 並由後端執行）

| Tab | 設定 | 預設 |
|---|---|---|
| 登入期限 | 會員長期登入（不因閒置失效） | 開 |
| | 會員 Token 閒置期限（分鐘，關閉長期登入時生效） | 43200 |
| | Admin 閒置登出（分鐘，滑動計算） | 10080（7 天） |
| 註冊與驗證 | Email 註冊／Email 驗證必要／手機註冊／手機 OTP 必要 | 全開 |
| SMS OTP | 測試模式（不發真實簡訊） | 開 |
| | SMS 業者：測試用／三竹／EVERY8D／SMSGO | 測試用 |
| | 每小時總額／每門號每小時／重發冷卻（分）／OTP 有效（分）／錯誤上限 | 100／3／3／5／5（暫定值，ADR-006） |

### SMS 業者（帳密只放伺服器環境變數）

| 業者 | 環境變數 | 規格依據 | 狀態 |
|---|---|---|---|
| 三竹 Mitake | `MITAKE_USERNAME`、`MITAKE_PASSWORD`、`MITAKE_ENDPOINT` | 官方 v2.14 PDF 無法於建置環境讀取；參數依公開整合文件 | **成功狀態碼待以官方文件核對**；需向三竹申請 HTTP API |
| EVERY8D | `EVERY8D_UID`、`EVERY8D_PASSWORD`、`EVERY8D_SITE` | 官方「簡訊 API2.1 規格書 v2.2」 | 未以真實帳號發送 |
| SMSGO | `SMSGO_USERNAME`、`SMSGO_PASSWORD`、`SMSGO_ENDPOINT` | 官方 SMSGO_API.pdf | 中文 BIG5 編碼方式待業者確認；需客服開通 API |

所有業者 Adapter 只以 HTTP 假回應測試，**未發送任何真實或付費簡訊**。啟用前需：業者帳號與 API 開通、IP 白名單（如需要）、核對規格、關閉測試模式。

