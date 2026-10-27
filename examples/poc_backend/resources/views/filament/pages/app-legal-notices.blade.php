<x-filament-panels::page>
    <p>分別編輯訪客入口、會員註冊及首次會員驗證顯示的文字。不儲存任何個人同意紀錄。</p>
    <form wire:submit="save" style="display: grid; gap: 1rem; max-width: 48rem;">
        <label>訪客初次進入 App 免責聲明
            <textarea wire:model="guestDisclaimer" rows="8" maxlength="20000" required style="display:block;width:100%;"></textarea>
            @error('guestDisclaimer') <span>{{ $message }}</span> @enderror
        </label>
        <label>會員初次註冊隱私同意書
            <textarea wire:model="registrationPrivacy" rows="8" maxlength="20000" required style="display:block;width:100%;"></textarea>
            @error('registrationPrivacy') <span>{{ $message }}</span> @enderror
        </label>
        <label>會員初次驗證隱私同意書
            <textarea wire:model="verificationPrivacy" rows="8" maxlength="20000" required style="display:block;width:100%;"></textarea>
            @error('verificationPrivacy') <span>{{ $message }}</span> @enderror
        </label>
        <button type="submit">儲存三份聲明</button>
    </form>
</x-filament-panels::page>
