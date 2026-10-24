<?php

use App\Filament\Resources\AuditEvents\Pages\ListAuditEvents;
use App\Models\AuditEvent;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('lists audit events with date, event, actor and result filters', function () {
    $admin = makeAdmin(['name' => '管理員甲']);
    $member = User::factory()->create(['name' => '會員乙']);
    $this->actingAs($admin);

    AuditEvent::record('member.login', $member->id);
    AuditEvent::record('member.login', $member->id, null, [], 'failure');
    AuditEvent::record('settings.updated', $admin->id, null, ['changes' => ['sms_provider' => ['old' => 'fake', 'new' => 'mitake']]]);
    $this->travel(-3)->days();
    AuditEvent::record('notice.published', $admin->id);
    $this->travelBack();

    $all = AuditEvent::query()->get();
    [$login, $failed, $settings, $old] = [$all[0], $all[1], $all[2], $all[3]];

    $this->get('/admin/audit-events')->assertOk()->assertSee('稽核紀錄');

    Livewire::test(ListAuditEvents::class)
        ->assertCanSeeTableRecords($all)
        ->filterTable('event', 'settings.updated')
        ->assertCanSeeTableRecords([$settings])
        ->assertCanNotSeeTableRecords([$login, $failed, $old]);

    Livewire::test(ListAuditEvents::class)
        ->filterTable('actor_id', $member->id)
        ->assertCanSeeTableRecords([$login, $failed])
        ->assertCanNotSeeTableRecords([$settings, $old]);

    Livewire::test(ListAuditEvents::class)
        ->filterTable('result', 'failure')
        ->assertCanSeeTableRecords([$failed])
        ->assertCanNotSeeTableRecords([$login]);

    Livewire::test(ListAuditEvents::class)
        ->filterTable('date', ['from' => now()->subDay()->toDateString(), 'until' => now()->toDateString()])
        ->assertCanSeeTableRecords([$login, $settings])
        ->assertCanNotSeeTableRecords([$old]);
});

it('has no way to create, edit or delete audit events', function () {
    $this->actingAs(makeAdmin());
    AuditEvent::record('member.login', null);
    $event = AuditEvent::query()->firstOrFail();

    $this->get('/admin/audit-events/create')->assertNotFound();
    $this->get("/admin/audit-events/{$event->id}/edit")->assertNotFound();

    expect(fn () => $event->update(['event' => 'tampered']))->toThrow(LogicException::class)
        ->and(fn () => $event->delete())->toThrow(LogicException::class)
        ->and($event->fresh()->event)->toBe('member.login');

    Livewire::test(ListAuditEvents::class)
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete')
        ->assertTableActionExists('details');
});

it('shows audit events only to admins with audit.view', function () {
    $admin = makeAdmin();
    Role::findByName(User::ADMIN_ROLE, 'web')->revokePermissionTo('audit.view');
    $this->actingAs($admin->refresh());

    $this->get('/admin/audit-events')->assertForbidden();
});
