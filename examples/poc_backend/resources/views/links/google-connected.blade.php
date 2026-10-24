@extends('links.layout')

@section('content')
    <div class="box">
        @if ($ok)
            <h1>【已連結 Google 日曆】</h1>
            <p>已建立「{{ config('google_calendar.calendar_name') }}」日曆，我的報名與 VIP 私人行程會自動同步（只同步現在與未來的行程）。請回到 App。</p>
        @else
            <h1>【連結失敗】</h1>
            <p>{{ $message }}</p>
        @endif
    </div>
@endsection
