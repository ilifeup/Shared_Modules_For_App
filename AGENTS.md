# AGENTS.md｜Shared Modules AI 工作入口

> **每次工作第一步：先讀 [PROJECT_RULES.md](PROJECT_RULES.md) 的「一、有效決策」。** 該文件是唯一的已核准業務規則權威；本文件只管理 AI 工作方法，**不另行建立第二套業務規則**。
> 永久問題與 Debug 追蹤：[Shared Issue #35](https://github.com/ilifeup/Shared_Modules_For_App/issues/35)。跨 App 依賴參考 [Tiandi Issue #38](https://github.com/ilifeup/tiandi-app/issues/38)。`FAST-DEVELOP.md` 已刪除，其完整歷史內容封存於 PROJECT_RULES.md。

## 每次必做（ChatGPT / Claude / Codex / Copilot）
1. **讀最新真值**：取得 main SHA、PROJECT_RULES.md 有效決策、Issue #35 最新正文與歷史、相關 SPEC/ADR、Open/Closed Issues、PR、Commit、CI；讀不到就說明，不能靠記憶補足。
2. **先比對衝突**：逐條核對使用者最新核准規則；舊測試、舊註解、舊 PR 與已取消功能不得重新成為權威。
3. **建立基線**：記錄目標 SHA、分支、檔案、已有修法、失敗原因、驗證範圍及缺口；區分 PASS / FAIL / BLOCKED / NOT TESTED。
4. **跨層追蹤**：Route/Middleware → Controller → Service/Policy → Model → MySQL/Migration → API Response → Flutter；必要時加 Filament、Job、通知、付款及實機。
5. **集中修正**：同批相關問題先收集完整錯誤與 CI log，確認根因；由單一負責 Agent 一次修改相關程式、契約、DB、測試及文件；避免反覆小 Push。
6. **提交前本地 Gate**：PHP 語法／Laravel Pint、相關 Feature tests、Flutter analyze/tests、必要 MySQL InnoDB 交易與並發、權限、冪等、rollback；沒跑不可宣稱 PASS。
7. **GitHub 節制**：優先沿用既有 Issue/分支；**禁止新增 PR**（除非使用者重新明確授權）。不得自動合併、部署、刪除真實資料或啟用付費服務。既有 PR 需人工核准合併。
8. **CI 只驗證**：對照 exact HEAD SHA；先修根因再重跑，使用 cache、合理的 paths/concurrency、避免取消唯一驗收 run；CI 綠燈不等於正式驗收。
9. **完成回寫**：更新 PROJECT_RULES.md（僅在有新核准決策時）、Issue #35（進度與根因），列出修改檔案、SHA、測試結果、回退、未測事項。
10. **跨專案回補**：審 Tiandi PR 時評估共用 API/Service/Flutter 契約能否回補 Shared Modules，但不因審查自動啟動 Tiandi 開發。

## 專案與環境邊界
- Shared Modules P1，供天帝教、無相氣學、EachCore 三個獨立 App 重用；Flutter UI / Dart API / Laravel Service+Policy+Filament / App 業務分層。
- 三 App 資料庫、會員、Token、角色與部署分離；Flutter 不直連 MySQL；Filament 與 Flutter API 透過 Laravel Service/Policy 共用權威。
- API `/api/v1`、OpenAPI 契約、MySQL/MariaDB、Sanctum；實際版本以 lockfile 與 CI 為準。
- 不得將 iStudy Ubuntu 主機、ZFS、PostgreSQL、Docker 作為本專案開發環境；付費 staging、正式部署、iOS 簽章等另需核准。
- 不提交密鑰、.env、PAT、真實會員資料；不為 CI 綠燈刪安全測試或放寬授權。

## 相關來源（僅作背景，不覆蓋有效決策）
- [PROJECT_RULES.md](PROJECT_RULES.md)：**唯一有效業務規則與歷史封存**。
- [docs/shared_modules_list.md](docs/shared_modules_list.md)：M01–M20 模組索引。
- [docs/specs/three_apps_shared_modules_spec_v1.md](docs/specs/three_apps_shared_modules_spec_v1.md)、[docs/specs/three_apps_poc_acceptance_plan_v1.md](docs/specs/three_apps_poc_acceptance_plan_v1.md)：原始規格與驗收背景。
- [docs/decisions/](docs/decisions/)：ADR；[docs/ENGINEERING_PRINCIPLES.md](docs/ENGINEERING_PRINCIPLES.md)：工程原則。
- [Issue #35](https://github.com/ilifeup/Shared_Modules_For_App/issues/35)：問題、根因、實測證據與進度；不是第二套相衝突的業務決策表。

## GitHub 工作流
盤點需求／歷史 → 核對 PROJECT_RULES → 集中修正 → 本地 Gate → 更新既有分支 → exact-SHA CI → 安全與跨層審查 → 使用者明確核准合併 → main 整合驗證。無現有可用分支時先回報阻礙，不因「繼續」自行開 PR。
