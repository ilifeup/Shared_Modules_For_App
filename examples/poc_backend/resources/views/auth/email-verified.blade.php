<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Email 驗證完成｜{{ config('app.name') }}</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; padding: 24px; background: #fff; color: #111; }
        .box { max-width: 420px; margin: 48px auto; border: 2px solid #111; padding: 24px; }
        h1 { font-size: 1.25rem; margin: 0 0 12px; }
    </style>
</head>
<body>
    <div class="box">
        <h1>✔ 【完成】Email 已驗證</h1>
        <p>請回到 {{ config('app.name') }} App，使用 Email 與密碼登入。</p>
    </div>
</body>
</html>
