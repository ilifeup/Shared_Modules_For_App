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
</x-filament-panels::page>
