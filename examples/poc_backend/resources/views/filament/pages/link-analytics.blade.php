<x-filament-panels::page>
    @php($r = $this->report())
    @php($max = max(1, collect($r['trend'])->max('total') ?? 1))
    <div style="display:flex; flex-wrap:wrap; gap:.75rem; align-items:flex-end;">
        <label>期間
            <select wire:model.live="period">
                @foreach (\App\Services\Analytics\LinkReport::PERIODS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
            </select>
        </label>
        @if ($period === 'custom')
            <label>起 <input type="date" wire:model.live="from"></label>
            <label>迄 <input type="date" wire:model.live="to"></label>
        @endif
        <label>內容類型
            <select wire:model.live="type">
                <option value="">全部</option><option value="article">文章</option><option value="event">活動</option><option value="notice">公告</option><option value="campaign">行銷活動</option>
            </select>
        </label>
        <label>內容 ID <input type="number" min="1" wire:model.live.debounce.500ms="content" style="width:6rem"></label>
        <label>來源 <input type="text" wire:model.live.debounce.500ms="source" placeholder="line / social / search…" style="width:10rem"></label>
        <label>排行依據
            <select wire:model.live="metric"><option value="total">總點擊</option><option value="dedup">估計去重點擊</option></select>
        </label>
    </div>

    <p>統計區間 {{ $r['from'] }} ～ {{ $r['to'] }}（固定時區 {{ $r['timezone'] }}）｜統計截至：{{ $r['as_of'] ?? '尚無資料' }}</p>

    <table style="width:100%; border-collapse:collapse;" border="1" cellpadding="6">
        <tr><th>總點擊</th><th>估計去重點擊（估計值，非精確人數）</th><th>成功開啟</th><th>未確認開啟</th><th>已排除：已知機器人</th><th>已排除：異常高頻</th></tr>
        <tr>
            <td>{{ $r['summary']['total'] }}</td><td>{{ $r['summary']['dedup'] }}</td><td>{{ $r['summary']['opens'] }}</td>
            <td>{{ $r['summary']['unconfirmed'] }}</td><td>{{ $r['summary']['bots'] }}</td><td>{{ $r['summary']['abnormal'] }}</td>
        </tr>
    </table>

    <h3>趨勢（每日總點擊）</h3>
    <table style="width:100%; border-collapse:collapse;" border="1" cellpadding="4">
        <tr><th style="width:8rem">日期</th><th>總點擊</th><th style="width:6rem">去重</th><th style="width:6rem">開啟</th></tr>
        @forelse ($r['trend'] as $day)
            <tr>
                <td>{{ $day->stat_date }}</td>
                <td><div style="display:flex;align-items:center;gap:.5rem"><div style="height:.8rem;width:{{ (int) round(100 * $day->total / $max) }}%;border:1px solid #333;background:repeating-linear-gradient(45deg,#999,#999 2px,transparent 2px,transparent 5px)"></div>{{ $day->total }}</div></td>
                <td>{{ $day->dedup }}</td><td>{{ $day->opens }}</td>
            </tr>
        @empty
            <tr><td colspan="4">此期間沒有資料。</td></tr>
        @endforelse
    </table>

    <h3>熱門內容（依{{ $metric === 'dedup' ? '估計去重點擊' : '總點擊' }}）</h3>
    <table style="width:100%; border-collapse:collapse;" border="1" cellpadding="4">
        <tr><th>內容</th><th>總點擊</th><th>估計去重</th><th>成功開啟</th><th>機器人排除</th><th>異常排除</th></tr>
        @forelse ($r['top'] as $row)
            <tr><td>{{ $row['label'] }}</td><td>{{ $row['total'] }}</td><td>{{ $row['dedup'] }}</td><td>{{ $row['opens'] }}</td><td>{{ $row['bots'] }}</td><td>{{ $row['abnormal'] }}</td></tr>
        @empty
            <tr><td colspan="6">此期間沒有資料。</td></tr>
        @endforelse
    </table>

    <h3>流量來源</h3>
    <table style="width:100%; border-collapse:collapse;" border="1" cellpadding="4">
        <tr><th>來源</th><th>總點擊</th><th>估計去重</th><th>成功開啟</th></tr>
        @foreach ($r['sources'] as $row)<tr><td>{{ $row->source }}</td><td>{{ $row->total }}</td><td>{{ $row->dedup }}</td><td>{{ $row->opens }}</td></tr>@endforeach
    </table>

    <h3>裝置（粗分類）</h3>
    <table style="width:100%; border-collapse:collapse;" border="1" cellpadding="4">
        <tr><th>裝置</th><th>總點擊</th><th>估計去重</th></tr>
        @foreach ($r['devices'] as $row)<tr><td>{{ ['mobile' => '手機', 'tablet' => '平板', 'desktop' => '桌面', 'unknown' => '未知'][$row->device_class] ?? $row->device_class }}</td><td>{{ $row->total }}</td><td>{{ $row->dedup }}</td></tr>@endforeach
    </table>
</x-filament-panels::page>
