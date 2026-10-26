@extends('links.layout')

@section('content')
    @if ($event)
        @php($tz = $event->timezone ?? 'Asia/Taipei')
        <article>
            <h1>{{ $event->status === 'cancelled' ? '【已取消】' : '' }}{{ $event->title }}</h1>
            <p>時間：{{ $event->starts_at->timezone($tz)->format('Y-m-d H:i') }}–{{ $event->ends_at->timezone($tz)->format('H:i') }}（{{ $tz }}）</p>
            @if ($event->location || $event->floor || $event->studio)
                <p>地點：{{ implode(' ', array_filter([$event->location, $event->floor, $event->studio])) }}</p>
            @endif
            @if ($event->description)
                <div style="white-space: pre-wrap">{{ $event->description }}</div>
            @endif
        </article>

        <div class="box">
            <p>活動預約僅開放正式會員，請在 App 登入後報名。</p>
        </div>
    @else
        <div class="box">
            <h1>【會員活動】</h1>
            <p>此活動僅限會員查看，請在 {{ config('app.name') }} App 登入後查看。</p>
        </div>
    @endif
@endsection
