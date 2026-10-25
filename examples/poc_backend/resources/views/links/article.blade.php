@extends('links.layout')

@section('content')
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
@endsection
