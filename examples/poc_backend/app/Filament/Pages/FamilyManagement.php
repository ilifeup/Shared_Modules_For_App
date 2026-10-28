<?php

namespace App\Filament\Pages;

use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class FamilyManagement extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = '家庭會員管理';

    protected static ?string $title = '家庭會員管理';

    protected string $view = 'filament.pages.family-management';

    public int $maxMembers = 2;

    public ?int $managerId = null;

    public string $paymentReference = '';

    public string $reviewNote = '';

    public static function canAccess(): bool
    {
        return auth()->user()?->is_active && auth()->user()?->isAdmin();
    }

    public function mount(): void
    {
        $this->maxMembers = (int) DB::table('family_member_settings')->where('id', 1)->value('max_members');
    }

    public function saveLimit(): void
    {
        $this->validate(['maxMembers' => ['required', 'integer', 'min:1', 'max:100']]);
        DB::transaction(function (): void {
            DB::table('family_member_settings')->where('id', 1)->lockForUpdate()->first();
            $largest = (int) (DB::table('family_memberships')->selectRaw('COUNT(*) as total')
                ->groupBy('manager_user_id')->orderByDesc('total')->value('total') ?? 0);
            if ($this->maxMembers < $largest) {
                throw ValidationException::withMessages(['maxMembers' => '不可低於已建立的家庭會員數。']);
            }
            DB::table('family_member_settings')->where('id', 1)
                ->update(['max_members' => $this->maxMembers, 'updated_at' => now()]);
        });
        Notification::make()->title('上限已更新')->success()->send();
    }

    public function verifyPayment(): void
    {
        $data = $this->validate([
            'managerId' => ['required', 'integer', 'exists:users,id'],
            'paymentReference' => ['required', 'string', 'max:120', 'unique:family_member_paid_credits,payment_reference'],
        ]);
        $user = User::findOrFail($data['managerId']);
        if (! $user->is_active || (int) $user->verified_status !== 2 || $user->FamilyUser) {
            throw ValidationException::withMessages(['managerId' => '須為有效且已驗證的主要會員。']);
        }
        DB::table('family_member_paid_credits')->insert([
            'manager_user_id' => $user->id,
            'verified_by' => auth()->id(),
            'payment_reference' => trim($data['paymentReference']),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->paymentReference = '';
        Notification::make()->title('已登記一筆付費資格')->success()->send();
    }

    public function review(int $id, string $decision): void
    {
        if (! in_array($decision, ['approved', 'rejected'], true)) {
            return;
        }
        DB::transaction(function () use ($id, $decision): void {
            $managerId = DB::table('family_member_applications')->where('id', $id)->value('manager_user_id');
            if ($managerId === null) {
                throw ValidationException::withMessages(['reviewNote' => '申請不存在。']);
            }
            $manager = DB::table('users')->where('id', $managerId)->lockForUpdate()->first();
            $application = DB::table('family_member_applications')->where('id', $id)->lockForUpdate()->first();
            if ($application === null || $application->status !== 'pending') {
                throw ValidationException::withMessages(['reviewNote' => '申請已審核，請重新整理。']);
            }
            if ($decision === 'approved') {
                $count = DB::table('family_memberships')->where('manager_user_id', $managerId)->count();
                $max = (int) DB::table('family_member_settings')->where('id', 1)->value('max_members');
                if (! $manager->is_active || (int) $manager->verified_status !== 2 || $manager->FamilyUser
                    || $count >= $max || ($count >= 1 && ! $application->payment_verified)) {
                    throw ValidationException::withMessages(['reviewNote' => '會員資格、名額或付款條件不符合。']);
                }
            } else {
                DB::table('family_member_paid_credits')->where('used_application_id', $id)
                    ->update(['used_application_id' => null, 'updated_at' => now()]);
            }
            DB::table('family_member_applications')->where('id', $id)->update([
                'status' => $decision, 'reviewed_by' => auth()->id(),
                'reviewed_at' => now(), 'review_note' => $this->reviewNote ?: null,
                'updated_at' => now(),
            ]);
        });
        $this->reviewNote = '';
        Notification::make()->title('審核完成')->success()->send();
    }

    public function downloadEvidence(int $id)
    {
        $application = DB::table('family_member_applications')->where('id', $id)->first();
        if ($application === null || ! is_string($application->evidence_path)
            || preg_match('/\Afamily-evidence\/[A-Za-z0-9._-]+\z/D', $application->evidence_path) !== 1
            || str_contains($application->evidence_path, '..')
            || ! Storage::disk('local')->exists($application->evidence_path)) {
            throw ValidationException::withMessages(['reviewNote' => '找不到證明文件。']);
        }

        return Storage::disk('local')
            ->download($application->evidence_path, 'family-evidence-'.$id);
    }

    public function getPendingProperty()
    {
        return DB::table('family_member_applications')->where('status', 'pending')
            ->orderBy('id')->limit(30)->get();
    }

    public function getCountsProperty()
    {
        return DB::table('family_memberships')->join('users', 'users.id', '=', 'family_memberships.manager_user_id')
            ->select('family_memberships.manager_user_id', 'users.name')
            ->selectRaw('COUNT(*) as family_count')->groupBy('family_memberships.manager_user_id', 'users.name')
            ->orderByDesc('family_count')->limit(100)->get();
    }
}
