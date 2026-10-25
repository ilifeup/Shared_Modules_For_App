<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="{{ $article ? 'index' : 'noindex' }}">
    <title>{{ $article?->title ?? '會員文章' }}｜{{ config('app.name') }}</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 720px; margin: 2rem auto; padding: 0 1rem; line-height: 1.7; }
        .box { border: 2px solid #333; padding: 1rem; }
        img { max-width: 100%; height: auto; }
    </style>
</head>
<body>
@if ($article)
    <article>
        <h1>{{ $article->title }}</h1>
        <p>作者：{{ $article->author_name }}｜{{ $article->published_at?->timezone(config('app.display_timezone'))->format('Y-m-d') }}</p>
        {{-- body_html is sanitized on save (App\Support\ArticleHtml). --}}
        {!! $article->body_html !!}
    </article>
@else
    <div class="box">
        <h1>【會員文章】</h1>
        <p>這篇文章僅限會員閱讀，請在 {{ config('app.name') }} App 登入後查看。</p>
    </div>
@endif
</body>
</html>
