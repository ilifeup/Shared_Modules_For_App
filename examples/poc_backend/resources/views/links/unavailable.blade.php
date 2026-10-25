@extends('links.layout')

@section('content')
    <div class="box">
        <h1>【連結無法開啟】</h1>
        <p>{{ $message ?? '此內容不存在、已下架，或您沒有查看權限。' }}</p>
    </div>
@endsection
