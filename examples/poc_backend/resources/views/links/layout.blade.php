<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="{{ ($og ?? null) ? 'index' : 'noindex' }}">
    <title>{{ $pageTitle }}｜{{ config('app.name') }}</title>
    {{-- M14-D09/D28: preview only for public content; private pages get no content metadata. --}}
    @if ($og ?? null)
        <meta property="og:type" content="article">
        <meta property="og:title" content="{{ $og['title'] }}">
        <meta property="og:description" content="{{ $og['description'] }}">
        <meta property="og:url" content="{{ $og['url'] }}">
        @if ($og['image'])
            <meta property="og:image" content="{{ $og['image'] }}">
        @endif
        <meta name="twitter:card" content="summary_large_image">
    @else
        <meta property="og:title" content="{{ config('app.name') }}">
    @endif
    <style>
        body { font-family: system-ui, sans-serif; max-width: 720px; margin: 2rem auto; padding: 0 1rem; line-height: 1.7; }
        .box { border: 2px solid #333; padding: 1rem; margin: 1rem 0; }
        .app { border: 1px dashed #333; padding: .75rem 1rem; margin: 1rem 0; }
        img { max-width: 100%; height: auto; }
        label { display: block; margin-top: .5rem; }
        input { width: 100%; padding: .4rem; box-sizing: border-box; }
        button { margin-top: 1rem; padding: .5rem 1rem; }
        .error { border: 2px solid #900; padding: .5rem 1rem; }
    </style>
</head>
<body>
@yield('content')

<div class="app">
    <p><strong>【App】</strong>已安裝 {{ config('app.name') }} App 時，點選連結會直接在 App 開啟。</p>
    @if (config('deeplinks.android.store_url'))
        <p><a href="{{ config('deeplinks.android.store_url') }}" rel="noopener">下載 Android App</a></p>
    @endif
    @if (config('deeplinks.ios.store_url'))
        <p><a href="{{ config('deeplinks.ios.store_url') }}" rel="noopener">下載 iOS App</a></p>
    @endif
</div>
<p><a href="{{ url('/') }}">返回首頁</a></p>

@if ($clickId ?? null)
    {{-- M17-D21: the page reports that it really opened (a redirect alone never counts). --}}
    <script>
        try { fetch('{{ url('/api/v1/links/opens') }}', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify({ click_id: '{{ $clickId }}' }), keepalive: true }); } catch (e) {}
    </script>
@endif
</body>
</html>
