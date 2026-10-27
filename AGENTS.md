# AGENTS.md｜Shared Modules 多 AI 共用開發規則

## 最高優先級工作入口：永久問題追蹤與 AI 避免重工規範

**每次開發、修 Bug、審查 PR、跑 CI 或交接之前，必讀 [本專案總追蹤 Issue #35](https://github.com/ilifeup/Shared_Modules_For_App/issues/35) 的最新正文與相關歷史。** 此規則是兩個 Repository 共同的最高**流程**原則；仍須遵守使用者明確核准的決策、正式功能規格及安全邊界。

1. 先搜尋相關 **Open/Closed Issues、合併/未合併 PR、Commit、ADR、回歸測試與 CI**；查明曾經的根因、失敗修法及版本差異，再開始動手。跨 App 契約同時查閱 [Shared #35](https://github.com/ilifeup/Shared_Modules_For_App/issues/35) 與 [Tiandi #38](https://github.com/ilifeup/tiandi-app/issues/38)。
2. **CI PASS 不等於功能驗收或結案**；必須核對 Commit SHA、實際 Request → Controller → Service → Model → Database → Response → Client 契約、必要的失敗／並發／重試測試及目標分支。缺少證據標記 NOT TESTED／BLOCKED。
3. 即使 VERIFIED/CLOSED，未來復發仍須先查歷史、REOPEN 或建立互相連結的新 Issue；永久保留失敗嘗試、根因、測試證據與回退資訊，不得反覆踩同一個坑。
4. 優先更新既有 Issue，避免重複開案、重複 PR 和無意義 CI 重跑；新問題建立獨立 Issue，並在總追蹤留下索引。每次結束都回寫已做／未做、證據、SHA、狀態及下一步。
5. 只引用已實際讀取的紀錄；不可假裝工具自動讀過 Issue。**若無法存取 GitHub，須明確說明未讀取，不能以推測替代。**

詳細狀態、結案／重開門檻與完整欄位，以總追蹤 Issue 為單一規範來源，不在本檔重複維護。


> 適用 Repository：`ilifeup/Shared_Modules_For_App`。本文件是 ChatGPT、Codex、Claude、Copilot 與其他支援 AGENTS.md 的 AI 開發者的共用工作規範。**不保證所有 AI 工具自動讀取**；工具不支援時應明確提供此文件。

## 1. 專案主軸（不可偏離）

開發供三個獨立 App 使用的 Flutter／Laravel API／Filament Web 共用功能。三個 App：天帝教親和網（第一個整合，`ilifeup/tiandi-app`）、無相氣學、EachCore。**共用模組優先開發，天帝教只是第一個導入測試 App**。不要把此 Repo 改造成天帝教 App 或第四個正式營運 App。

## 2. 每次開始工作的閱讀順序

1. 本檔 `AGENTS.md`。
2. `docs/shared_modules_list.md`：M01–M20 及當前模組狀態。
3. `docs/specs/three_apps_shared_modules_spec_v1.md`：原始需求基準。
4. `docs/specs/three_apps_poc_acceptance_plan_v1.md`：原始測試與驗收基準。
5. `docs/decisions/` 與 README、目前 Issue/PR/CI；必要時讀 V8 完整背景。

**衝突優先序**：最新使用者明確核准決策 > 原始 SPEC > 原始 POC > V8 歷史背景 > AI 推論。若有 ADR 被新決策取代，保留歷史並新增決策，不覆寫來源。文件缺失時回報，不自行杜撰原文。

## 3. 架構及模組邊界

- L0 Flutter UI，L1 Dart Client/API Contract，L2 Laravel 可移植 Service/Policy/Filament，L3 App 專屬業務；不得混用。
- Flutter 不直連 MySQL；Filament 不作 Flutter API；兩者的寫入由 Laravel Service/Policy 控制。
- 三個 App 各有正式資料庫、會員、部署、Token、角色與權限；不做中央帳號、跨 App 資料庫外鍵或預設 SSO。
- 角色權限、VIP、認證、職稱、專業徽章分離；不能以付費或稱謂自動取得管理權。
- EachCore WooCommerce 是商品、庫存、訂單、付款、退款權威；團體 App 不新增贊助金流。
- API `/api/v1`；成功 `{data,meta?}`，錯誤 `{error:{code,message,fields?,request_id}}`；OpenAPI 是契約來源。
- 不硬編碼天帝教分院、無相課程、EachCore 商店 URL 或專屬欄位進共用 Core。

## 4. 當前階段與範圍控制

- S0：文件、規則、模組矩陣、基本 CI；**不因建立目錄就宣告功能完成**。
- S1：選定 Auth + M01 Profile + M02 最小權限 + M06 Notice + M07 最小稽核的 Flutter→Laravel/Sanctum→MySQL←Filament 垂直切片。
- S2：以固定版本導入 `tiandi-app`，跑 Android 實機與 CI 驗收。
- M03 認證、M08 Booking、M11 Check-in 業務規則未核准前不得擅自建立完整正式流程。
- 不自行擴充微服務、中央管理平台、通用工作流引擎、跨 App SSO、額外付費服務。

## 5. GitHub 雙 AI 開發流程

1. 先讀現有程式與 Issue/PR/ADR，確認分支、檔案與現有 CI；先給出最小變更方案與驗收方法。
2. 例行且已核准的低風險任務，ChatGPT／Claude 可分支、提交、開 PR、跑 CI、在符合既定條件時協助合併；不可同時在同一分支改碼。
3. 重大架構、身份/授權模型、敏感資料 Schema、正式部署、付費資源、秘密、真實資料遷移、刪除 Repo，必須先獲使用者明確批准。
4. 合併前核對 PR HEAD Commit SHA 與實際測試 Commit SHA 一致、diff 符合範圍、必要 CI PASS、無秘密與未核准變更；不合併失敗或未知測試的 PR。
5. GitHub Free Private Repo 不可假設有可用的 Required Checks／Branch Protection／Auto-merge；如無強制保護，記錄為程序性控制，不宣稱 GitHub 強制阻擋。
6. 不提交 `.env`、Token、PAT、SSH 私鑰、真實會員資料、密碼、服務憑證；CI Secrets 只在必要且核准後配置。
7. 每次修改附：目的、影響模組、變更檔案、測試命令與結果、PR/CI URL、回退方法、待決事項。

## 6. 品質與驗收

- 嚴格區分 `PASS`、`FAIL`、`BLOCKED`、`NOT TESTED`；不能以 CI Build PASS 宣稱 API/DB/Filament/Android 實機已 PASS。
- 最少測正常、401、403、404、422、Token 撤銷、重複請求、離線／逾時、敏感資料遮蔽；需要併發的模組另測 DB 交易與唯一索引。
- Flutter 遵循既定 Riverpod、Dio、go_router；Laravel 遵循 Sanctum、Spatie Permission、Policy、FormRequest；具體版本以 lockfile 與 CI 為準。
- 避免不必要新依賴、複雜抽象與沒有驗證的宣告；API/Schema 更動需同步契約、Migration、測試與文件。
- 共用模組採明確版本（Tag/SHA），App 整合需要相容性、回退與各自資料庫 migration 計畫。

## 7. 環境保護

- 不得使用、重設、安裝、修改 iStudy Ubuntu 主機及其 ZFS/PostgreSQL/Docker 作為本專案環境。
- Laravel Cloud 只是候選 staging；任何付費開通或正式部署需使用者核准。
- 不假設 iOS 已有 Mac 或簽章資源；Android APK Build 不等於雙平台上架完成。

## 8. 文件維護

- `docs/shared_modules_list.md` 是活的模組總規格，M01–M20 編號不可任意改。
- `docs/specs/` 的 SPEC/POC 原件不可悄悄改寫；新增 ADR 記錄新決策及取代關係。
- 若其他 AI 不支援自動讀 `AGENTS.md`，應由操作者在任務中指定讀取；**不要把檔案名稱當成權限或技術保證**。