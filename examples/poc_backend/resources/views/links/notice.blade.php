@extends('links.layout')

@section('content')
    @if ($notice)
        <article>
            <h1>{{ $notice->title }}</h1>
            <p>{{ $notice->published_at?->timezone(config('app.display_timezone'))->format('Y-m-d') }}</p>
            <div style="white-space: pre-wrap">{{ $notice->body }}</div>
        </article>
    @else
        <div class="box">
            <h1>【會員公告】</h1>
            <p>這則公告僅限會員查看，請在 {{ config('app.name') }} App 登入後查看。</p>
        </div>
    @endif
@endsection
