<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>重設密碼｜{{ config('app.name') }}</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; padding: 24px; background: #fff; color: #111; }
        .box { max-width: 420px; margin: 32px auto; border: 2px solid #111; padding: 24px; }
        label { display: block; margin-top: 12px; }
        input { width: 100%; padding: 8px; margin-top: 4px; box-sizing: border-box; }
        button { margin-top: 16px; padding: 10px 16px; }
        .error { border: 2px solid #111; padding: 8px; margin-bottom: 12px; }
    </style>
</head>
<body>
    <div class="box">
        <h1>重設密碼</h1>
        @isset($failure)
            <div class="error">✖ 【失敗】{{ $failure }}</div>
        @endisset
        @if ($errors->any())
            <div class="error">✖ 【請修正】{{ $errors->first() }}</div>
        @endif
        <form method="post" action="{{ route('password.update') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <label>Email
                <input type="email" name="email" value="{{ old('email', $email) }}" required>
            </label>
            <label>新密碼（至少 8 個字元）
                <input type="password" name="password" required minlength="8">
            </label>
            <label>再次輸入新密碼
                <input type="password" name="password_confirmation" required minlength="8">
            </label>
            <button type="submit">設定新密碼</button>
        </form>
    </div>
</body>
</html>
