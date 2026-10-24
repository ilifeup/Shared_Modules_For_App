<?php

use App\Filament\Resources\Members\Pages\ListMembers;
use App\Filament\Resources\MembershipLevels\Pages\EditMembershipLevel;
use App\Models\AuditEvent;
use App\Models\MemberLevelAssignment;
use App\Models\MembershipLevel;
use App\Models\User;
use App\Services\MembershipService;
use App\Support\Api\ApiException;
use Filament\Facades\Filament;
use Livewire\Livewire;

function vip(): MembershipLevel
{
    return MembershipLevel::query()->where('code', MembershipLevel::VIP)->firstOrFail();
}

it('ships the general and VIP levels with general as default', function () {
    $this->getJson('/api/v1/membership-levels')
        ->assertOk()
        ->assertJsonPath('data.0.code', 'general')
        ->assertJsonPath('data.0.is_default', true)
        ->assertJsonPath('data.1.code', 'vip');

    $member = User::factory()->create();
    $this->actingAs($member, 'sanctum')->getJson('/api/v1/me/membership')
        ->assertOk()
        ->assertJsonPath('data.level.code', 'general')
        ->assertJsonPath('data.ends_at', null);
});

it('gives VIP for a period, then falls back to general and keeps history', function () {
    $admin = makeAdmin();
    $member = User::factory()->create();

    app(MembershipService::class)->assign($member, vip(), now(), now()->addDays(30), '活動贈送', $admin);

    $this->actingAs($member, 'sanctum')->getJson('/api/v1/me/membership')
        ->assertOk()
        ->assertJsonPath('data.level.code', 'vip')
        ->assertJsonPath('data.level.name', 'VIP 會員');

    $this->travel(31)->days();
    $this->app['auth']->forgetGuards();

    $this->actingAs($member, 'sanctum')->getJson('/api/v1/me/membership')
        ->assertOk()
        ->assertJsonPath('data.level.code', 'general');

    expect(MemberLevelAssignment::query()->where('user_id', $member->id)->count())->toBe(1)
        ->and(MemberLevelAssignment::query()->firstOrFail()->stateLabel())->toBe('已到期');
});

it('never grants admin rights through a level', function () {
    $admin = makeAdmin();
    $member = User::factory()->create();

    app(MembershipService::class)->assign($member, vip(), now(), null, null, $admin);

    expect($member->refresh()->hasRole(User::ADMIN_ROLE))->toBeFalse()
        ->and($member->getAllPermissions())->toBeEmpty();

    $this->actingAs($member)->get('/admin')->assertForbidden();
});

it('rejects invalid assignments', function () {
    $admin = makeAdmin();
    $member = User::factory()->create();
    $service = app(MembershipService::class);

    expect(fn () => $service->assign($member, MembershipLevel::default(), now(), null, null, $admin))->toThrow(ApiException::class);
    expect(fn () => $service->assign($member, vip(), now(), now()->subDay(), null, $admin))->toThrow(ApiException::class);
    expect(fn () => $service->assign($admin, vip(), now(), null, null, $admin))->toThrow(ApiException::class);
    expect(MemberLevelAssignment::query()->count())->toBe(0);
});

it('lets an admin assign and revoke VIP in Filament with an audit trail', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $admin = makeAdmin();
    $this->actingAs($admin);
    $member = User::factory()->create();

    Livewire::test(ListMembers::class)
        ->callTableAction('assignLevel', $member, data: [
            'membership_level_id' => vip()->id,
            'starts_at' => now()->subMinute()->toDateTimeString(),
            'ends_at' => now()->addMonth()->toDateTimeString(),
            'reason' => '測試',
        ])
        ->assertHasNoTableActionErrors();

    expect(app(MembershipService::class)->current($member)['level']->code)->toBe('vip');
    expect(AuditEvent::query()->where('event', 'member.level_assigned')->where('actor_id', $admin->id)->exists())->toBeTrue();

    $assignment = MemberLevelAssignment::query()->firstOrFail();
    app(MembershipService::class)->revoke($assignment, $admin);

    expect(app(MembershipService::class)->current($member)['level']->code)->toBe('general')
        ->and($assignment->refresh()->stateLabel())->toBe('已撤銷')
        ->and(AuditEvent::query()->where('event', 'member.level_revoked')->exists())->toBeTrue();
});

it('lets an admin edit level name and benefits shown in the App', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(makeAdmin());

    Livewire::test(EditMembershipLevel::class, ['record' => vip()->getRouteKey()])
        ->fillForm(['name' => '金卡 VIP', 'benefits' => '專屬活動優先報名'])
        ->call('save')
        ->assertHasNoFormErrors();

    $member = User::factory()->create();
    app(MembershipService::class)->assign($member, vip(), now(), null, null, User::query()->first());

    $this->actingAs($member, 'sanctum')->getJson('/api/v1/me/membership')
        ->assertJsonPath('data.level.name', '金卡 VIP')
        ->assertJsonPath('data.level.benefits', '專屬活動優先報名');

    expect(AuditEvent::query()->where('event', 'membership_level.updated')->exists())->toBeTrue();
    $this->get('/admin/membership-levels/create')->assertNotFound();
});
