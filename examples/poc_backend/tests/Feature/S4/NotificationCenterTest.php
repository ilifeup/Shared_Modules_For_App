<?php

use App\Filament\Resources\Members\Pages\ListMembers;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\MemberNotifier;
use App\Support\Api\ApiException;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

function notifyMember(User $user, string $title = '通知', array $extra = []): string
{
    $existing = $user->notifications()->pluck('id')->all();
    app(MemberNotifier::class)->send($user, $title, $extra['body'] ?? '內容', $extra['type'] ?? 'system', $extra['action_type'] ?? null, $extra['action_id'] ?? null);

    return (string) $user->notifications()->whereNotIn('id', $existing)->value('id');
}

it('lists only the member\'s own notifications, newest first, with the unread count', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    notifyMember($a, '第一則');
    $this->travel(1)->minutes();
    notifyMember($a, '第二則', ['type' => 'notice', 'action_type' => 'notice', 'action_id' => '7']);
    notifyMember($b, 'B 的通知');

    $this->actingAs($a, 'sanctum')->getJson('/api/v1/me/notifications')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.title', '第二則')
        ->assertJsonPath('data.0.type', 'notice')
        ->assertJsonPath('data.0.action', ['type' => 'notice', 'id' => '7'])
        ->assertJsonPath('data.0.is_read', false)
        ->assertJsonPath('data.1.action', null)
        ->assertJsonPath('meta.unread_count', 2)
        ->assertJsonPath('meta.total', 2)
        ->assertJsonMissing(['title' => 'B 的通知']);

    $this->actingAs($a, 'sanctum')->getJson('/api/v1/me/notifications/unread-count')
        ->assertOk()
        ->assertJsonPath('data.unread_count', 2);
});

it('never lets member A read or mark member B\'s notification', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $bId = notifyMember($b, 'B 私人通知');

    $this->actingAs($a, 'sanctum')->getJson("/api/v1/me/notifications/{$bId}")
        ->assertNotFound()
        ->assertJsonPath('error.code', 'NOT_FOUND')
        ->assertDontSee('B 私人通知');
    $this->actingAs($a, 'sanctum')->postJson("/api/v1/me/notifications/{$bId}/read")->assertNotFound();
    $this->actingAs($a, 'sanctum')->postJson('/api/v1/me/notifications/read-all')->assertOk()->assertJsonPath('data.updated', 0);

    expect($b->unreadNotifications()->count())->toBe(1);
});

it('marks one notification read idempotently and keeps the count consistent', function () {
    $user = User::factory()->create();
    $id = notifyMember($user, 'A');
    notifyMember($user, 'B');

    $first = $this->actingAs($user, 'sanctum')->postJson("/api/v1/me/notifications/{$id}/read")
        ->assertOk()
        ->assertJsonPath('data.is_read', true)
        ->assertJsonPath('meta.unread_count', 1);
    $readAt = $first->json('data.read_at');

    $this->travel(5)->minutes();
    $this->actingAs($user, 'sanctum')->postJson("/api/v1/me/notifications/{$id}/read")
        ->assertOk()
        ->assertJsonPath('data.read_at', $readAt)
        ->assertJsonPath('meta.unread_count', 1);

    $list = $this->actingAs($user, 'sanctum')->getJson('/api/v1/me/notifications')->json();
    $unreadInList = collect($list['data'])->where('is_read', false)->count();
    expect($unreadInList)->toBe($list['meta']['unread_count'])->toBe(1);

    $this->actingAs($user, 'sanctum')->getJson("/api/v1/me/notifications/{$id}")
        ->assertOk()
        ->assertJsonPath('data.title', 'A');
});

it('marks all read, repeatedly without error', function () {
    $user = User::factory()->create();
    foreach (range(1, 3) as $i) {
        notifyMember($user, "N{$i}");
    }

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/me/notifications/read-all')
        ->assertOk()
        ->assertJsonPath('data.updated', 3)
        ->assertJsonPath('data.unread_count', 0);
    $this->actingAs($user, 'sanctum')->postJson('/api/v1/me/notifications/read-all')
        ->assertOk()
        ->assertJsonPath('data.updated', 0);

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/me/notifications')
        ->assertJsonPath('meta.unread_count', 0)
        ->assertJsonPath('data.0.is_read', true);
});

it('paginates 20 per page', function () {
    $user = User::factory()->create();
    foreach (range(1, 25) as $i) {
        notifyMember($user, "N{$i}");
    }

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/me/notifications')
        ->assertJsonCount(20, 'data')
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonPath('meta.unread_count', 25);
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/me/notifications?page=2')->assertJsonCount(5, 'data');
});

it('requires login and a satisfied verification policy', function () {
    $this->getJson('/api/v1/me/notifications')->assertUnauthorized();

    $unverified = User::factory()->unverified()->create();
    $this->actingAs($unverified, 'sanctum')->getJson('/api/v1/me/notifications')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'VERIFICATION_REQUIRED');

    // Malformed ids are not found.
    $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/me/notifications/not-a-uuid')->assertNotFound();
    $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/me/notifications/'.Str::uuid())->assertNotFound();
});

it('validates notifications and never sends them to the administrator', function () {
    $admin = makeAdmin();
    $notifier = app(MemberNotifier::class);

    expect(fn () => $notifier->send(User::factory()->create(), '', 'x'))->toThrow(ApiException::class)
        ->and(fn () => $notifier->send(User::factory()->create(), 't', 'x', 'unknown'))->toThrow(ApiException::class)
        ->and($notifier->send($admin, 't', 'x'))->toBe(0)
        ->and($admin->notifications()->count())->toBe(0);
});

it('lets an admin send a notification to one or several members from Filament', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $admin = makeAdmin();
    $this->actingAs($admin);
    [$a, $b, $c] = User::factory()->count(3)->create()->all();

    Livewire::test(ListMembers::class)
        ->callTableAction('sendNotification', $a, data: ['title' => '個別通知', 'body' => '內容', 'type' => 'notice', 'notice_id' => 5])
        ->assertHasNoTableActionErrors();

    expect($a->notifications()->firstOrFail()->data)->toMatchArray(['title' => '個別通知', 'action_type' => 'notice', 'action_id' => '5']);

    Livewire::test(ListMembers::class)
        ->callTableBulkAction('sendNotificationBulk', [$b, $c], data: ['title' => '群發', 'body' => '內容', 'type' => 'system'])
        ->assertHasNoTableBulkActionErrors();

    expect($b->notifications()->count())->toBe(1)
        ->and($c->notifications()->count())->toBe(1)
        ->and($a->notifications()->count())->toBe(1)
        ->and(AuditEvent::query()->where('event', 'notification.sent')->where('actor_id', $admin->id)->count())->toBe(2);
});

it('hides sending from an admin without notifications.send', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $admin = makeAdmin();
    Role::findByName(User::ADMIN_ROLE, 'web')->revokePermissionTo('notifications.send');
    $this->actingAs($admin->refresh());
    $member = User::factory()->create();

    Livewire::test(ListMembers::class)
        ->assertTableActionHidden('sendNotification', $member);
});
