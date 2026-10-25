# S9 M14 iOS Universal Links 設定模板

> 狀態：**模板已提供；正式設定、簽章與實機驗證為 BLOCKED**，需要 Apple Developer Team ID、正式網域與實機。

## 已在 repo 中準備

| 項目 | 位置 | 狀態 |
|---|---|---|
| Entitlements 模板 | `examples/poc_flutter/ios/Runner/Runner.entitlements.example`（Tiandi：`mobile/ios/Runner/Runner.entitlements.example`） | 模板；**尚未接到 Xcode 專案** |
| AASA 檔 | 後端 `GET /.well-known/apple-app-site-association`，只接受固定路徑 `/articles/*`、`/events/*`、`/notices/*`、`/checkin/l/*` | 只有設定 `IOS_APP_ID` 後才回應；未設定時 404 |
| App 端連結處理 | `app_links` 監聽、`DeepLinkParser` 只接受 `APP_LINK_HOST` 網域、冷／熱啟動返回首頁規則 | Android 與 iOS 共用；**NOT TESTED（實機）** |

## 取得 Team ID 與網域後的啟用步驟（由使用者或指定人員執行）

1. 在 Apple Developer 的 App ID 啟用 **Associated Domains** capability，並重新產生 provisioning profile。
2. 後端環境設定 `IOS_APP_ID=<TEAMID>.<bundle id>`、`DEEPLINK_BASE_URL=https://<網域>`。確認 `https://<網域>/.well-known/apple-app-site-association` 回應 200，且是 `application/json`、沒有重新導向。
3. 把模板複製為 `ios/Runner/Runner.entitlements`。在 Runner target 的 Build Settings 設定：
   - `CODE_SIGN_ENTITLEMENTS = Runner/Runner.entitlements`
   - `APP_LINK_HOST = <網域>`
4. 建置 App：`--dart-define=APP_LINK_HOST=<網域>`。
5. 實機驗收（M14-D24）：
   - 在備忘錄、LINE、Safari 點擊 `https://<網域>/events/<id>`；
   - 冷啟動與熱啟動各測一次；
   - 未登入時登入後回到原頁；
   - 無權限、已下架、離線各測一次。

## 為什麼不現在接到 Xcode 專案

沒有 Associated Domains capability 的 provisioning profile 時，實機簽章會失敗。所以 entitlements 只提供模板，等使用者取得 Team ID 後再啟用，避免影響現有 iOS 建置。
