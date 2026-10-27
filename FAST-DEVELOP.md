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

## 12. PR #53 MVP 合併前缺口 Gate（2026-10-28）

> 本節記錄驗收責任而非宣告功能已完成。每次判定均須附 exact HEAD、CI run 與具體測試／程式路徑。CI PASS 不代表實機與完整功能驗收。PR #53 保持 Draft；未經使用者核准不得合併或部署。

| 模組 | 本次 MVP 必須完成／驗收 | 可明確延後／禁止項目 | 目前證據與缺口 |
|---|---|---|---|
| #49 會員資格 | `users.id` 唯一會員鍵、`verified_status=1/2` 唯一資格權威；停用／撤銷、Email/手機驗證成功與政策切換、資料升級回退 | 舊歷史欄位清理可延後；禁止用聯絡驗證時間推算會員資格 | `docs/member-status-db-authority.md`、`MemberStatusDbAuthorityTest.php`；正式舊資料盤點／核准回填／備份還原 NOT TESTED |
| #47 預約 | 一次僅一位已驗證 `users.id`，自己或正式授權家庭會員；取消／撤銷代管、FIFO 候補、冪等、交易與並發、通知／Google handoff、Flutter 參加者選取 | 訪客／匿名／管理員開放訪客、單次多人預約均**永久禁止**，不是延後功能 | `SingleMemberBookingContractTest.php`、`BookingEngine.php` 與後端 CI；家庭撤銷／取消競態及端到端 Flutter 操作仍需逐項核對 |
| #46 影音中心 | 音樂單曲／會員等級授權、個別 deny 優先、有效期與撤銷即時檢查、MySQL FK/CHECK/rollback、受保護影音不得洩漏至公開 YouTube 清單、Flutter 實際播放 | 進階推薦、非必要播放體驗可延後；公開 YouTube 不得宣稱為 DRM | `AvEntitlement.php`、`AvCatalogController.php`、`AvEntitlementTest.php`；加密音訊解密／播放端及實機 NOT VERIFIED |
| #41 附件權限 | 管理員啟用、已驗證會員、週額度／大小與獨立 MySQL 並行、私人路徑、每次開啟重新授權、到期／下架／隱藏撤銷、Flutter PDF/圖片閱讀、AES-GCM 私人快取、強制浮水印與快取失效 | Windows 桌面版、非必要個人化浮水印、背景清理可延後；管理員浮水印／預設期限設定是否屬本次 MVP 尚待明確決策 | `s8-attachment-cache-contract.md`、`AttachmentEntitlementTest.php`；獨立 MySQL 並行、Flutter 閱讀器／加密快取／浮水印 NOT VERIFIED |

**合併規則：** 先針對上述 NOT TESTED / NOT VERIFIED 建立最小可重現驗收證據；必要功能未完成則保持 Draft。不可用刪測試、降低權限要求或將未核准功能改列延後來換取綠燈。正式資料庫 Migration、guest purge、部署均須另行核准。

## 13. 家庭會員 Client 與預約歷史不可變原則（使用者決策，優先於先前 #47 推論）

- **Client 禁止解除家庭會員關係**：代管者只可編輯家庭會員資料；不得提供刪除家庭會員、解除代管關係、或透過刪除觸發關係終止的 UI／API 流程。不可為了測試假想 Client 解除流程而新增功能。
- **預約發起者及歷史不可變**：原代管者建立的預約，booker_user_id 及歷史紀錄歸屬不因家庭關係變動而改寫、隱藏或刪除；「我的預約」按原發起者顯示其歷史，不能以目前家庭關係過濾。不得重新引入 #47 曾錯誤新增的 whereHas/whereDoesntHave 家庭授權過濾。
- **區分歷史與後續操作**：查看自己發起的歷史預約不等於取得對家庭會員的永久編輯權；新增／修改／取消等操作仍依各自已核准的業務授權判斷。管理端若有正式關係變更需求，須另立規格，不得推定 Client 有此功能。
- **驗收與測試**：保留「原發起者可查歷史」的正向回歸測試；刪除以「Client 解除關係→歷史消失」為正確預期的測試。不得將後端資料修復／管理員異動誤當 Client 功能。
