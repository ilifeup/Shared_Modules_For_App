<x-filament-panels::page>
    <div style="border: 1px solid currentColor; padding: 1rem;">
        <p><strong>目前政策：</strong>
            @if ($this->currentPolicy() === \App\Support\Settings::POLICY_PHONE)
                【手機驗證必要】phone_required — 會員須完成手機 OTP 驗證才能使用完整功能；Email 可稍後驗證。
            @else
                【Email 驗證必要】email_required — 會員須完成 Email 驗證才能使用完整功能；手機 OTP 為選擇性。
            @endif
        </p>
        <p><strong>真實簡訊：</strong>
            {{ $this->smsAvailable() ? '✔ 可用' : '✖ 不可用（測試模式或業者未設定）' }}
        </p>
        <p>兩種政策下，姓名、手機、Email 都是註冊必填；切換不會把任何聯絡方式自動標記為已驗證。</p>
    </div>
    <div style="margin-top: 1rem; border: 1px solid currentColor; padding: 1rem;">
        <p><strong>撤銷既有會員保留資格</strong>（單一管理員操作）</p>
        <p>輸入會員 ID，撤銷後該會員須依目前驗證政策完成驗證。撤銷不可透過切換政策還原。</p>
        <form wire:submit="revokeMemberById" style="display: flex; gap: .5rem; align-items: center;">
            <label for="grandfather-member-id">會員 ID</label>
            <input id="grandfather-member-id" type="number" min="1" step="1" wire:model="revocationMemberId" required style="max-width: 10rem; color: black; background: white; padding: .35rem;" />
            <button type="submit" wire:confirm="確定撤銷此會員的保留驗證資格？此操作會記錄稽核。" style="border: 1px solid currentColor; padding: .4rem .75rem;">確認撤銷</button>
        </form>
        @error('revocationMemberId') <p role="alert">{{ $message }}</p> @enderror
    </div>
</x-filament-panels::page>
