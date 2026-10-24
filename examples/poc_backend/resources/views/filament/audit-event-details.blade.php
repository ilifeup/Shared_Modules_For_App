<dl class="space-y-2 text-sm">
    <div><dt class="font-semibold">時間</dt><dd>{{ $record->created_at?->format('Y-m-d H:i:s') }}</dd></div>
    <div><dt class="font-semibold">操作者</dt><dd>{{ $record->actor?->name ?? '（系統／訪客）' }}</dd></div>
    <div><dt class="font-semibold">對象</dt><dd>{{ $record->subject_type ? $record->subject_type.' #'.$record->subject_id : '—' }}</dd></div>
    <div><dt class="font-semibold">結果</dt><dd>{{ $record->result ?? '—' }}</dd></div>
    <div><dt class="font-semibold">request_id</dt><dd>{{ $record->request_id ?? '—' }}</dd></div>
    <div><dt class="font-semibold">詳細（不含密碼、驗證碼、token）</dt><dd><pre class="whitespace-pre-wrap">{{ $details }}</pre></dd></div>
</dl>
