# CLAUDE.md｜Claude Code 專用入口

本 Repository 的共用開發規範以 [`AGENTS.md`](AGENTS.md) 為**唯一主要規則來源**。Claude Code 開始工作時，必須先閱讀並遵守 `AGENTS.md`，再閱讀 `docs/shared_modules_list.md`、原始 SPEC/POC、相關 ADR、當前 Issue/PR 與 CI。若檔案尚未上傳，回報缺失，不得假裝已閱讀。

本專案的主軸是三個 App 的**共用 Flutter + Laravel API + Filament Web 模組**；`tiandi-app` 是第一個導入對象，不能把共用 Repo 寫成天帝教專屬 App。

Claude 與 ChatGPT 均可依已核准範圍操作 GitHub；重大架構、付費、正式部署、敏感資料 Schema 等仍需使用者明確核准。每次 PR 必須提供變更摘要、測試證據、風險與回退方式。不要複製 AGENTS.md 的整套規則到本檔，以免雙方規範分歧。

本檔保留名稱 `CLAUDE.md` 是為了 Claude Code 的原生自動讀取能力；其他 AI 應使用 `AGENTS.md`。自訂 `AI.md` 不保證自動載入。