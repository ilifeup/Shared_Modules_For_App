<x-filament-panels::page>
    <p>逐層設定 App 頁面、List 列表及主題詳情的瀏覽資格。未設定規則預設拒絕；明確禁止優先。此設定不會解除影音播放或活動預約權限。</p>
    <form wire:submit="saveRule" style="display: grid; gap: 1rem; max-width: 36rem;">
        <label>層級
            <select wire:model="scope">
                <option value="page">App 頁面</option>
                <option value="list">List 列表</option>
                <option value="topic">主題詳情</option>
            </select>
        </label>
        <label>識別碼（頁面：av；列表：av/music；主題：av/music/song1）
            <input wire:model="resourceCode" type="text" required maxlength="120" />
        </label>
        <label>資格
            <select wire:model.live="audienceType">
                <option value="guest">訪客</option>
                <option value="all">所有使用者</option>
                <option value="member">已登入會員</option>
                <option value="verified">已驗證會員（含 V01-A）</option>
                <option value="level">指定會員等級（含 VIP）</option>
                <option value="user">指定會員</option>
            </select>
        </label>
        @if (in_array($audienceType, ['level', 'user'], true))
            <label>{{ $audienceType === 'level' ? '會員等級 ID' : '會員 ID' }}
                <input wire:model="audienceId" type="number" min="1" required />
            </label>
        @endif
        <label>決策
            <select wire:model="decision">
                <option value="allow">允許</option>
                <option value="deny">禁止</option>
            </select>
        </label>
        <button type="submit">儲存規則</button>
    </form>
    <h3>現有規則</h3>
    <table>
        <thead><tr><th>層級</th><th>識別碼</th><th>資格</th><th>決策</th><th>操作</th></tr></thead>
        <tbody>
            @foreach ($this->rules() as $rule)
                <tr wire:key="rule-{{ $rule->scope }}-{{ $rule->resource_code }}-{{ $rule->audience }}">
                    <td>{{ $rule->scope }}</td><td>{{ $rule->resource_code }}</td><td>{{ $rule->audience }}</td>
                    <td>{{ $rule->allowed ? '允許' : '禁止' }}</td>
                    <td><button type="button" wire:click="removeRule(@js($rule->scope), @js($rule->resource_code), @js($rule->audience))">刪除</button></td>
                </tr>
            @endforeach
        </tbody>
    </table>
</x-filament-panels::page>
