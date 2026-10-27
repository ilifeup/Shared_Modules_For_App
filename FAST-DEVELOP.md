# FAST-DEVELOP.md — Shared Modules 最高開發效率指導原則

> 生效範圍：本 Repository 的 ChatGPT、Claude、Codex、Copilot、CI 與後續 AI Agent。
> 狀態：開發流程規範；不授權自動合併、部署、破壞性遷移或改變已核准業務規則。
> 閱讀順序：AGENTS.md → 本文件 → Shared #35 → 最新使用者決策/ADR → 相關 PR/Issue/CI → 目標程式。
> 規範衝突：最新使用者明確核准決策與安全/資料規則優先；本文件僅優化工作方式，不降低驗收門檻。

## 1. 唯一目標
以最少重工、最少等待、可追溯的方式完成可運作的 Shared Modules，優先支援 Tiandi Android MVP，再重用於 Wuxiang / EachCore。以「驗證完成的功能」而非 PR、Commit、CI 次數衡量進度。禁止為增加 PR 數量而拆分同一問題。

## 2. 每次工作前先查證
1. 讀 Shared #35 與 Tiandi #38、現有 AGENTS.md、ADR、Open/Closed Issue、Open/Closed/Merged PR、分支相依、最新 main HEAD 和 CI。
2. 先建立基線清單：main SHA、候選 PR head/base SHA、是否已合併、檔案差異、依賴、測試證據與阻礙；標記 VERIFIED / PASS / FAIL / BLOCKED / NOT TESTED。
3. 對照已核准規則，避免舊程式/測試復活已禁止行為。不得僅依 PR 標題、綠燈或歷史敘述宣稱完成。
4. 有現存對應 PR 或 Issue 時，優先延續並更新；除非不同責任邊界或必要隔離，不另開重複 PR。
5. 先查明根因與既有失敗紀錄，再動手修改。

## 3. 批次 PR 與分支策略
- 以完整功能或一組相互關聯的缺陷為工作批次。開發分支可有多個小 Commit；功能達到可審查狀態才建立或更新一個整合 PR。
- 不把所有模組塞入巨型 PR。建議會員資格、影音中心、預約/通知、簽到、安全修正分開，明確記錄跨分支相依。
- 同一資料表、Migration、共用 Service、API 契約只允許一個明確整合責任者；多 Agent 不得在同一工作樹/分支互相覆寫。
- 已存在堆疊 PR 時，先畫出 base/head 相依圖，決定保留、rebase、cherry-pick 或重建整合分支；不直接把 draft 視為 main 的一部分。
- 每個批次至少記錄：目標、影響檔案、契約/DB 變更、驗收清單、風險、測試命令、CI run + SHA、回退方法。
- 不自動合併 PR、不自動部署、不自動關閉問題。需要使用者核准時保持 Draft。

## 4. 雲端 GitHub 最短開發迴圈
需求與現況盤點 → 一個功能分支連續修改 → 本地或 Agent 工作環境執行相關快速測試 → 集中 Push → 更新既有 PR → exact-HEAD CI → 重要風險審查 → 使用者核准合併 → main 整合驗證。
- 不為文件、小格式修正、同一缺陷每次 Commit 建立新 PR。
- 失敗時先看第一個根因與 job log，修復後只重跑受影響測試；不得以重跑代替修正。
- CI 運作時可平行處理互不衝突的工作；不得對同一分支競爭寫入。
- ChatGPT/Claude/Codex 是開發與審查者，不因 GitHub Actions 執行而自動具備修改/修復能力。

## 5. GitHub Actions 效率政策
- PR 快速 Gate：語法、靜態檢查、相關單元/功能測試、契約與必要安全回歸。
- 整合/合併 Gate：MySQL migration/rollback、安全/權限、跨模組 API、併發/重試/通知、必要 Flutter 測試。
- 發布 Gate：完整回歸、Android Build、實機/E2E、部署與回退驗證。
- 對可安全跳過的工作使用 paths 或受影響檔案偵測；不得讓 Required Checks 因 workflow 被整體跳過而永遠 Pending。安全與 DB 契約變更必須跑相應檢查。
- 使用 Composer/Pub/Gradle cache、matrix 範圍控制與 concurrency 取消同分支過時 run；不取消正在驗收的唯一 exact-SHA 證據。
- 以實際 job duration、失敗率、queue wait、重跑率、每個功能總耗時持續優化；不得先假定 CI 或本機一定較快。
- CI PASS 僅代表指定 SHA 與測試範圍通過；不可等同功能驗收、正式發布或問題結案。

## 6. 不可犧牲的功能/安全規則
- 活動永遠僅限會員預約；管理員也不可開放訪客預約。所有參加者必須有有效、唯一且經授權的會員 ID。訪客僅可閱讀公開公告，沒有個人通知收件匣或預約異動通知。
- 家庭代管、候補 FIFO、交易原子性、取消授權、稽核、重試冪等、401/403/404/409/422 必須依最新決策驗證。
- 會員資格、影音中心的資料庫設計必須有明確主鍵/外鍵/唯一性/索引、狀態轉移、授權來源、Migration/rollback、測試資料與契約；影音權限不得只靠前端。
- 所有跨層檢查追蹤 Route/Middleware → Controller → Service/Policy → Model → DB → Response → Flutter。
- 破壞性資料清除（包含舊訪客預約與欄位）須先做備份、資料影響盤點、隔離環境還原演練與明確核准；不得直接操作正式資料。
- 不提交 Secrets/真實會員資料；不使用或更動 iStudy Ubuntu 主機、ZFS、PostgreSQL、Docker 作為本專案環境，除非使用者另行明確核准。

## 7. 本次基線（2026-10-27 查詢快照，需重新驗證）
- main HEAD: 9b22fe559b02b0c6fbf5087fe44e360ec4015793。這是已提交基線，不是最新業務規則驗收基線。
- #43: members-only booking、移除訪客與破壞性 purge migration；Draft，不能部署。
- #42: durable booking side-effects；Draft。
- #47: #42 + #43 整合候選；Draft，尚未完整驗收。
- #45: M11 簽到與 #43 的整合測試；Draft。
- #49: 會員資格 V01-A 第一切片；Draft，尚未完成全部會員管理。
- #46: S10 影音權限初版；缺 migrations/tests/API/middleware，Draft。
- #48: 公告刪除授權修正；Draft。
- #44: Tiandi 專用 YouTube API 擴充契約；Draft。
- 所有 PR 必須核對即時 SHA/CI，不以此快照當作即時結果。
- 基線凍結流程：盤點所有未合併 PR → 建立 dependency graph → 先確認 #43 的會員規則與清除安全 → 整合 #42/#47 與 #45 → 並行完善 #49/#46 → 安全回歸 #48 → Flutter/Tiandi pinned SHA 驗證 → 人工核准整合。
- 在正式基線確立前不得宣稱「所有 PR 已 OK」或「可直接搬遷本機」。

## 8. 切換本機 AI 的條件
保留 GitHub 為唯一版本歷史/審查來源；本機 git clone（非 ZIP）保留分支/標籤/PR 關係，核對 main SHA、未合併 PR 與 lockfile。先在隔離環境重現 CI，再切換主要開發迴圈。本機開發不等於略過 GitHub PR 或正式驗收。不得直接挪用 iStudy 主機作為環境。

## 9. 每次 AI 回報格式
- 目前 main SHA / 工作分支 SHA / PR base-head SHA
- 已確認完成、未完成、BLOCKED/NOT TESTED
- 本次修正與受影響模組/資料表/API
- 本機與 CI 測試命令、結果、exact SHA、連結
- 是否有未核准破壞性操作、資安/權限風險
- 下一個最短可驗證步驟、負責分支、回退方式

## 10. 永久知識庫
Shared #35 與 Tiandi #38 是總追蹤入口。正式驗收後仍須保存根因、失敗修法、證據、限制與回歸觸發條件；新 AI 必須先查歷史再改碼，禁止因 CI 綠燈自動結案。
