<div style="border: 2px solid #555; padding: 1rem;">
    @if ($problems)
        <div style="border: 2px solid #900; padding: .5rem 1rem; margin-bottom: 1rem;">
            <strong>【無法發布】</strong>
            <ul>@foreach ($problems as $p)<li>{{ $p }}</li>@endforeach</ul>
        </div>
    @endif
    <p><strong>{{ $campaign->title }}</strong></p>
    @if ($campaign->summary)<p>{{ $campaign->summary }}</p>@endif
    <p>圖片：{{ $campaign->image_path ? '已上傳' : '無' }}</p>
    <p>按鈕：［{{ $campaign->button_text }}］</p>
    <p>導向：{{ $item['target']['url'] ?? ($campaign->target_url ?? '（目前無法展示，請見上方原因）') }}</p>
    <p>預覽不會公開草稿；發布後依排程、區塊上限與觀看者權限顯示。</p>
</div>
