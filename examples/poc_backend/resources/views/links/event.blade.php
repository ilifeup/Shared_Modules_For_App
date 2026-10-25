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

        @if ($result ?? null)
            <div class="box">
                <h2>{{ $result['status'] === 'confirmed' ? '【報名成功】' : '【已列入候補】' }}</h2>
                <p>共 {{ $result['seats'] }} 位{{ $result['status'] === 'waitlisted' ? '，有名額時將依順序整組遞補' : '' }}。</p>
                <p>以報名時的 Email 或手機註冊會員並完成驗證後，可在 App「我的報名」認領並自行管理此報名。</p>
            </div>
        @elseif ($guestBooking)
            <div class="box">
                <h2>訪客報名</h2>
                @if ($errors->any())
                    <div class="error">@foreach ($errors->all() as $message)<p>{{ $message }}</p>@endforeach</div>
                @endif
                <form method="post" action="{{ url('/events/'.$event->id.'/guest-booking') }}">
                    @csrf
                    <input type="hidden" name="client_request_id" value="{{ $clientRequestId }}">
                    <label>聯絡人姓名（必填）<input name="contact_name" required maxlength="80" value="{{ old('contact_name') }}"></label>
                    <label>Email（Email 或手機至少填一項）<input name="contact_email" type="email" maxlength="255" value="{{ old('contact_email') }}"></label>
                    <label>手機<input name="contact_phone" maxlength="20" value="{{ old('contact_phone') }}"></label>
                    @for ($i = 0; $i < $maxSeats; $i++)
                        <label>參加者 {{ $i + 1 }} 姓名{{ $i === 0 ? '（必填）' : '（選填）' }}<input name="attendees[]" maxlength="80" {{ $i === 0 ? 'required' : '' }} value="{{ old('attendees.'.$i) }}"></label>
                    @endfor
                    <button type="submit">送出報名</button>
                </form>
                <p>訪客報名不會寄送 Email 或簡訊；註冊會員並驗證相同的 Email／手機後即可在 App 查看通知與管理報名。</p>
            </div>
        @endif
    @else
        <div class="box">
            <h1>【會員活動】</h1>
            <p>此活動僅限會員查看，請在 {{ config('app.name') }} App 登入後查看。</p>
        </div>
    @endif
@endsection
