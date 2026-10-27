# FAST-DEVELOP.md — Shared Modules 最高開發效率指導原則

> 生效範圍：本 Repository 的 ChatGPT、Claude、Codex、Copilot、CI 與後續 AI Agent。
> 狀態：開發流程規範；不授權自動合併、部署、破壞性遷移或改變已核准業務規則。
> 閱讀順序：AGENTS.md → 本文件 → AGENTS.md 第 2 節列出的模組清單/SPEC/POC/ADR → Shared #35 → 最新使用者決策 → 相關 PR/Issue/CI → 目標程式。若規範衝突，以最新明確核准的使用者決策及不可降低的安全規則為準。
> 規範衝突：最新使用者明確核准決策與安全/資料規則優先；本文件僅優化工作方式，不降低驗收門檻。

## 1. 唯一目標
以最少重工、最少等待、可追溯的方式完成可運作的 Shared Modules，優先支援 Tiandi Android MVP，再重用於 Wuxiang / EachCore。以「驗證完成的功能」而非 PR、Commit、CI 次數衡量進度。禁止為增加 PR 數量而拆分同一問題。

## 2. 每次工作前先查證
1. 依上述順序閱讀 AGENTS.md、模組清單/SPEC/POC/ADR、Shared #35、現有 Open/Closed Issue、Open/Closed/Merged PR、分支相依、最新 main HEAD 和 CI。Tiandi #38 僅作跨專案相依與歷史參照；Tiandi 開發目前暫停，不因閱讀此追蹤項目而啟動 Tiandi PR、CI 或排程。
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

## 5A. CI 一次修正、集中提交與過期執行取消（2026-10-27 補充）

> 目標：避免「修改一個測試 → Push → 等完整 CI → 再修改」的往返循環。先完成同一批問題的根因分析與本地驗證，再集中 Push 一次。GitHub Actions 只負責驗證，不會自行修復程式。

1. **先收集完整失敗集合**：鎖定 PR 最新 head SHA，取得該 SHA 的全部失敗 job logs、測試名稱、斷言差異、錯誤碼與相關程式；區分真實業務缺陷、舊測試假設、環境故障與 CI 設定問題。不得只看第一個錯誤就 Push。
2. **一次追蹤跨層根因**：對每組失敗檢查 Route/Middleware → Controller → Service/Policy → Model → Migration/DB → API Response → Flutter；對照最新核准契約及 Shared #35 的歷史修正。先列出預期修正清單與回歸風險，再動手。
3. **同一批問題集中修正**：在同一工作樹／單一負責 Agent 中完成所有已確認的相依修改；同步更新測試、資料庫契約與必要文件。不得刪除安全回歸測試、放寬授權或修改業務規則來換取綠燈。
4. **Push 前先做快速本地 Gate**：至少執行受影響檔案語法檢查、相關單元／Feature tests、必要靜態檢查；涉及會員資格、預約、簽到、交易或 DB migration 時追加對應安全及資料完整性回歸。無可用本地環境時明確標記 NOT TESTED，不能聲稱已驗證。
5. **集中 Push，減少重複 CI**：修正與本地 Gate 完成後才集中推送既有 PR 分支；不要為同一失敗連續推送多個小 Commit 觸發多輪完整 CI。等待最新 exact-HEAD 的 Backend／Mobile／Documentation 結果；舊 SHA 的綠燈或失敗不得冒充新 SHA 結果。
6. **過期 run 的 concurrency 設計**：可在各 workflow 的 job/workflow 設定 `concurrency`，group 同時包含 `github.workflow` 與 PR 編號或 `github.ref`，避免不同 workflow 互相取消。例如：
   ```yaml
   concurrency:
     group: ${{ github.workflow }}-${{ github.event.pull_request.number || github.ref }}
     cancel-in-progress: ${{ github.event_name == 'pull_request' }}
   ```
   此範例僅供逐一檢查後採用，不能直接保證所有事件／受保護分支適用；不要取消唯一正在驗收的 exact-SHA run、正式發布、部署或不可中斷工作。Concurrency 只避免過期執行，**不會減少每次 Push 觸發的次數**，也不能替代本地 Gate。
7. **依風險分層執行**：PR 快速 Gate 跑受影響測試及必要契約／安全檢查；整合 Gate 跑 DB/MySQL、跨模組與 Flutter；發布 Gate 才跑完整建置與 E2E。使用 paths/changed-files 時確保 Required Checks 不會因整個 workflow 跳過而卡 Pending，安全／DB 變更不能被錯誤略過。
8. **失敗後只啟動下一輪必要修正**：最新完整 CI 失敗時，一次彙整所有失敗與根因，再集中修正；不要因單一 job 尚未結束就頻繁 Push。記錄每輪 SHA、失敗數、執行時間、排隊時間、修正範圍與驗證結果，避免重踩。
9. **研究外部經驗但不盲套**：遇到 GitHub Actions／Laravel／Flutter CI 特殊問題，可查 GitHub 官方文件、GitHub Issues/Discussions 與 Reddit 經驗；第三方做法須對照本專案版本、權限及測試環境後才採用。
10. **完成判準**：最新 exact-SHA 所有必要 CI PASS，加上資料庫、權限、業務規則與跨 PR 相容性審查，才可提交人工驗收；不自動 merge、deploy 或結案。

參考：GitHub Docs — [Control the concurrency of workflows and jobs](https://docs.github.com/en/actions/how-tos/write-workflows/choose-when-workflows-run/control-workflow-concurrency)、[Using conditions to control job execution](https://docs.github.com/en/actions/how-tos/write-workflows/choose-when-workflows-run/using-conditions-to-control-job-execution)。

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
- #46: S10 影音權限 Draft；後續已加入 AV migrations、測試、受保護 catalog API 與 middleware，不能沿用「缺 migrations/tests/API/middleware」的舊判斷。仍缺完整播放/離線授權及跨 PR #49 驗證政策整合驗收；以最新 HEAD、CI 與程式實際內容重新查證。
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
Shared #35 是目前 Shared Modules 開發的主要總追蹤入口；Tiandi #38 僅保留作跨專案歷史與相依參照，除非使用者明確恢復 Tiandi 開發。正式驗收後仍須保存根因、失敗修法、證據、限制與回歸觸發條件；新 AI 必須先查歷史再改碼，禁止因 CI 綠燈自動結案。


---

## 11. Shared Modules 具體開發基線：Baseline A / B / C

> 本節與前述 Fast-Develop 規則同屬單一資訊頁。目標時間是估算，不是已完成證明或保證。所有狀態須以當下 PR HEAD SHA、main SHA 與測試紀錄更新。

### Baseline A — 可重現、可追溯的開發起點（目標 1–2 天）
**交付目標**：產生一份可供任何 AI 接手的真實基線清單，停止盲目增加 PR。
- 核對 main HEAD、GitHub Actions workflows、lockfiles、測試環境與每個 PR 的 base/head SHA。
- 盤點全部 Open/Draft PR 及必要 Closed/Merged PR，建立相依圖、變更檔案、重複修正、衝突與保留/整合/淘汰建議。
- 保存現有未合併成果的 branch/SHA；不得直接關閉或刪除尚未整理的 PR/branch。
- 建立功能矩陣：會員資格、影音中心、M08 預約、M11 簽到、通知、公告安全與 Tiandi 對接。每項標記 IMPLEMENTED / PARTIAL / BLOCKED / NOT TESTED，並附證據。
- 重現至少一輪代表性 backend、OpenAPI、Flutter CI，紀錄耗時、失敗原因、測試覆蓋與 exact SHA。
**驗收**：清單與證據可重現；沒有遺失未合併成果；尚未驗證者清楚標記。完成 A 不代表可發布。

### Baseline B — 會員制預約 + 通知交易 + 簽到整合候選（目標 3–7 天）
**交付目標**：建立可供後續本機 AI 接手的「整合候選分支」，不直接視為正式 main。
- 以 #43 最新會員制規則為契約基礎，對照 #42、#47、#45、#40 與相關歷史 PR；選定整合方式，避免同一變更重複套用。
- 預約僅限會員，所有參加者均須合法會員 ID；不允許訪客、管理員例外或 name-only 參加者。
- 驗證家庭代管/撤銷、候補 FIFO、簽到簽退、通知/Google handoff、重試冪等、交易失敗回滾及並發安全。
- 對照 Route → Controller → Service → Model → DB → API Response → Flutter，更新 OpenAPI、Migration、測試與 Flutter 參加者選取。
- 破壞性 guest purge 只可在隔離測試 DB 演練；正式資料庫需先備份、還原驗證、影響清單與使用者明確核准，未核准不得執行。
- exact-SHA CI、必要 MySQL/Flutter/E2E 檢查完成；缺失保持 BLOCKED/NOT TESTED。
**驗收**：整合候選分支及證據完整，安全阻礙可追蹤；未經核准不 merge、不 deploy。

### Baseline C — 會員資格 + 影音中心可重用契約（目標 5–10 天，可與 B 部分並行）
**交付目標**：保留並推進 #49 與 #46，而非等待所有 M01–M20 完成。
- 會員資格：從 #49 V01-A 出發，補齊管理員驗證/撤銷、有效期、家庭關係、授權來源、狀態轉移、後端與 Filament、測試；明確列出本次 MVP 必需與可延期功能。
- 影音中心：從 #46 S10 權限初版出發，補齊資料表、Migration、索引/外鍵、授權服務、API/OpenAPI、middleware、測試與 Tiandi 契約；不得以只有設計文件宣告完成。
- 兩條開發線可並行，但會員 ID、資格、媒體授權、共用 DB Schema/API 契約須有單一責任者；避免 Agent 同時改同一 Migration。
- **跨 PR 驗證 Gate（已發現衝突）**：#46 的 `AvEntitlementTest` 在切換 email→phone 後，對原已 email-verified 會員預期立即拒絕；#49 V01-A 對切換當下已驗證會員採 grandfathering，預期仍可符合驗證資格。兩者各自 CI PASS 不代表合併相容。須在同一整合樹測切換前已驗證、切換後才驗證、未驗證、已撤銷/停用，以及有／無有效會員等級的 AV 授權；不能直接把 #46 舊斷言當作正式規則。
- **影音資料完整性 Gate**：驗證 `av_page_placements` 的 `series_id`/`video_id` 恰有一個有效目標；刪除關聯內容後不得留下仍啟用的孤兒版位。必須以 MySQL 8.4 實測 FK、CHECK/Service 驗證、刪除行為及 rollback；尚未測試前標記 NOT TESTED。
- **公開 YouTube 相容性 Gate**：#44 的 `/public/youtube-videos` 不得列出 #46 受保護影音內容。若保留舊公開頻道擴充，必須明確隔離公開與受限內容，否則暫不合併 #44。
- 依實際功能需求驗證權限、撤銷、重複/併發、資料完整性與回退，並確認 Tiandi 使用固定 Shared 版本/SHA。
**驗收**：每條功能線有可重現的 API/DB/測試證據；未完成項目清楚記錄，不能把 Draft 當作已驗收。

### 里程碑總覽

| 基線 | 可交付成果 | 估算 | 目前狀態 |
|---|---|---|---|
| A | 完整 PR/CI/Schema/功能盤點與可重現環境 | 1–2 天 | IN PROGRESS：僅完成初步 GitHub 快照 |
| B | M08/M11/通知交易整合候選分支 | 3–7 天 | NOT VERIFIED：#42/#43/#45/#47 仍需整合 |
| C | 會員資格、影音中心契約與必要實作 | 5–10 天 | PARTIAL：#49/#46 仍為 Draft |

### 本機 AI 切換 Gate
A 完成、B 整合候選可重現且阻礙已列明時，才考慮以 git clone 轉為本機主要開發。C 可在雲端與本機依相依性持續推進。切換前保留所有 PR head/base SHA、未合併分支與 lockfiles；不直接改動 iStudy 主機。

### 優先順序與決策權
P0：永久會員制、權限、資料庫一致性、不可逆清除安全；P1：M08/M11/通知整合；P2：會員資格與影音中心並行；P3：不阻礙 Tiandi MVP 的其他共用模組。此排序不取代使用者已明確核准的業務優先序。
