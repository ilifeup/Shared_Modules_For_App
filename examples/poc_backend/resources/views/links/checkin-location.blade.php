@extends('links.layout')

@section('content')
    <div class="box">
        <h1>【簽到地點】{{ $location?->name ?? '' }}</h1>
        <p>請使用 {{ config('app.name') }} App 掃描此 QR Code，登入後選擇要簽到的活動場次。簽到需要網路連線。</p>
    </div>
@endsection
