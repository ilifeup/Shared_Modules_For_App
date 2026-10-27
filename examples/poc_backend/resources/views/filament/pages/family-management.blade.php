<x-filament-panels::page>
    <div class="space-y-6">
        <section class="rounded-xl border p-4 space-y-3">
            <h2 class="font-bold">家庭會員上限（免費名額固定 1 位）</h2>
            <form wire:submit="saveLimit" class="flex items-center gap-3">
                <input type="number" min="1" max="100" wire:model="maxMembers" class="rounded border p-2 w-28 text-gray-900" />
                <x-filament::button type="submit">儲存上限</x-filament::button>
            </form>
            @error('maxMembers') <p class="text-red-600">{{ $message }}</p> @enderror
        </section>
        <section class="rounded-xl border p-4 space-y-3">
            <h2 class="font-bold">人工確認付款（第二位起每位一筆）</h2>
            <form wire:submit="verifyPayment" class="flex flex-wrap gap-3">
                <input type="number" wire:model="managerId" placeholder="主要會員 ID" class="rounded border p-2 text-gray-900" />
                <input wire:model="paymentReference" placeholder="唯一付款編號" class="rounded border p-2 text-gray-900" />
                <x-filament::button type="submit">確認已付款</x-filament::button>
            </form>
            @error('managerId') <p class="text-red-600">{{ $message }}</p> @enderror
            @error('paymentReference') <p class="text-red-600">{{ $message }}</p> @enderror
        </section>
        <section class="rounded-xl border p-4 space-y-3">
            <h2 class="font-bold">待審核申請（最多顯示 30 筆）</h2>
            <input wire:model="reviewNote" placeholder="審核備註（選填）" class="rounded border p-2 text-gray-900" />
            @error('reviewNote') <p class="text-red-600">{{ $message }}</p> @enderror
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr><th class="p-2 text-left">申請 ID</th><th class="p-2 text-left">代管者 ID</th><th class="p-2 text-left">家庭會員</th><th class="p-2 text-left">付費確認</th><th class="p-2 text-left">審核</th></tr></thead>
                    <tbody>
                    @forelse ($this->pending as $item)
                        <tr class="border-t">
                            <td class="p-2">{{ $item->id }}</td>
                            <td class="p-2">{{ $item->manager_user_id }}</td>
                            <td class="p-2">{{ $item->member_name }}</td>
                            <td class="p-2">{{ $item->payment_verified ? '已確認' : '免費申請' }}</td>
                            <td class="p-2 space-x-2">
                                <x-filament::button size="sm" wire:click="review({{ $item->id }}, 'approved')" wire:confirm="確定核准？">核准</x-filament::button>
                                <x-filament::button size="sm" color="danger" wire:click="review({{ $item->id }}, 'rejected')" wire:confirm="確定駁回？">駁回</x-filament::button>
                                <x-filament::button size="sm" color="gray" wire:click="downloadEvidence({{ $item->id }})">下載證明</x-filament::button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="p-3">目前沒有待審核申請。</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        <section class="rounded-xl border p-4">
            <h2 class="font-bold">主要會員已建立的家庭會員數</h2>
            <table class="w-full text-sm">
                <thead><tr><th class="p-2 text-left">主要會員 ID</th><th class="p-2 text-left">姓名</th><th class="p-2 text-left">家庭會員數</th></tr></thead>
                <tbody>
                @forelse ($this->counts as $item)
                    <tr class="border-t"><td class="p-2">{{ $item->manager_user_id }}</td><td class="p-2">{{ $item->name }}</td><td class="p-2">{{ $item->family_count }}</td></tr>
                @empty
                    <tr><td colspan="3" class="p-3">目前尚無家庭會員。</td></tr>
                @endforelse
                </tbody>
            </table>
        </section>
    </div>
</x-filament-panels::page>
