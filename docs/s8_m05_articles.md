# S8｜M05 文章管理與互動、M01 公開暱稱

本文件說明 S8-M05 v1.0 在共用 PoC（`examples/poc_backend`、`examples/poc_flutter`、`packages/flutter/shared_core`）中的實作、設定與驗收範圍。各 App（第一個為 tiandi-app）各自部署自己的後端、資料庫與金鑰，沒有共用正式服務。

## 功能摘要

| 範圍 | 實作 |
|---|---|
| M05-A 文章 | Filament 富文字編輯、`.md` 匯入（YAML Front Matter：title / author / summary / categories / cover）、同名提示不覆蓋、勾選後只更新標題與內文；多分類最多三層（有子分類或文章禁止刪除）；草稿可無分類，發布／排程需至少一個；封面上傳或外部 URL；顯示作者可改、保留 `created_by`；公開／會員／指定等級或角色（OR）；立即發布、排程、自動下架、撤回；垃圾桶、還原、永久刪除。無版本歷史、無審核流程。 |
| B01 | 手動精選＋排序；一般依發布時間；人氣＝有效瀏覽＋按讚 × `article_like_weight`（預設 10，後台「系統設定 › 文章互動」可調）；同一會員或同一 IP＋UA 在 `article_view_dedupe_minutes`（預設 30）內只計一次。 |
| B02 | 分享連結 `/articles/{slug}`：只有公開文章顯示內容；會員或限定文章只顯示「請登入 App」提示，不洩漏標題與內文。App 以剪貼簿複製連結。 |
| B03 | 已驗證且有閱讀權限的會員可按讚／取消（每人每篇一個）；名單只列出開啟「在按讚名單顯示」的會員暱稱（預設關閉），其他人計入總數；管理員在文章編輯頁看會員 ID 與真實姓名（需 `members.manage`）。 |
| B04 | 需公開暱稱；Laravel Regex 黑名單（`config/articles.php`，可用 `ARTICLE_COMMENT_BLACKLIST` 覆蓋）命中時 422 `COMMENT_BLOCKED` 並稽核；通過即公開；管理員可隱藏／恢復／刪除。 |
| B05 | 預設理由＋選填說明；每輪每會員一次有效檢舉；達 `comment_report_threshold`（預設 3）位不同會員時，在列鎖內把該輪標為 `pending` 並於 commit 後排入一次 AI 審核；之後的檢舉持續累計但不再觸發。AI 只回 keep / hide / unsure；hide、unsure、無法使用（未設定、金鑰缺、逾時、額度、HTTP 錯誤、格式錯誤）都先暫時隱藏；AI 永不刪除。Email 通知具 `comments.moderate` 的管理員（檢舉摘要、AI 判斷、後台案件連結）。管理員可恢復、維持隱藏、刪除、結案；結案後的新檢舉開新一輪。 |
| M01 | Profile 分頁：基本資料／公開暱稱／隱私設定。暱稱唯一、2–20 字，按讚名單與留言一律顯示目前暱稱（改名後歷史也更新）。 |

## 權限與資料安全

- 閱讀權限只在 Laravel 判斷（`App\Services\ArticleAccess`），套用於列表、內文、封面、按讚名單、留言、互動與分享頁。
- 新權限：`articles.manage`（文章、分類）、`comments.moderate`（留言、檢舉案件），由 migration 給 `admin` 角色。
- AI 只收到留言文字與檢舉理由代碼，不含會員姓名、ID、Email、檢舉說明；系統提示把內容標示為不可信資料，回覆只接受嚴格 JSON，其他一律 unsure。
- API Key 只從伺服器環境變數讀取，只放在 HTTP Authorization header；不進 Flutter、公開 API、Git、稽核紀錄；失敗日誌只記 HTTP 狀態碼。

## 部署設定（各 App 自行設定，本批未部署）

1. 執行 migration `2026_10_31_000100_s8_articles`。
2. **Queue worker**：AI 審核是 queued job（`ReviewReportedComment`，`tries = 1`）。Laravel Cloud 需開 worker（`php artisan queue:work`）；`QUEUE_CONNECTION=sync` 時會在檢舉請求內同步執行。
3. **Mail**：設定 `MAIL_*`，管理員帳號需有 Email。
4. **AI**：`AI_MODERATION_DRIVER=openai_compatible`、`AI_MODERATION_API_KEY`（Secrets）、`AI_MODERATION_ENDPOINT`、`AI_MODERATION_MODEL`、`AI_MODERATION_TIMEOUT`。未設定時為 `null`：達門檻的留言一律暫時隱藏並通知管理員。
5. `ARTICLE_SHARE_BASE_URL`：分享連結網域（預設 `APP_URL`）。
6. 上傳封面存在 `local` disk 的 `article-covers/`，經 API 依權限讀取；正式環境需持久化儲存。

## 驗收邊界

自動化測試中的 AI 與 Email 都是模擬：`CommentModerator` 假物件、`Http::fake`（驗證真實 Adapter 的請求內容與錯誤處理）、`Notification::fake`。**尚未對任何真實 AI 服務或郵件服務驗收**；上線前需以正式金鑰做一次人工驗收（達門檻 → AI 判斷 → 隱藏／保留 → 收到 Email → 後台處理）。
