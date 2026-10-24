<?php

use App\Filament\Pages\SystemSettings;
use App\Filament\Resources\Notices\Pages\CreateNotice;
use App\Http\Middleware\EnforceAdminIdle;
use App\Models\AuditEvent;
use App\Models\Notice;
use App\Models\User;
use App\Support\Settings;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('shows the Filament login page to guests and redirects protected pages', function () {
    $this->get('/admin/login')->assertOk();
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('lets the administrator open every S1 admin page', function () {
    $admin = makeAdmin();

    foreach (['/admin', '/admin/members', '/admin/notices', '/admin/notices/create', '/admin/system-settings'] as $path) {
        $this->actingAs($admin)->get($path)->assertOk();
    }
});

it('never lets a member into Filament', function () {
    $member = User::factory()->create();

    $this->actingAs($member)->get('/admin')->assertForbidden();
    $this->actingAs($member)->get('/admin/notices')->assertForbidden();
});

it('denies a disabled administrator', function () {
    $admin = makeAdmin(['is_active' => false]);

    $this->actingAs($admin)->get('/admin')->assertForbidden();
});

it('keeps the admin signed in within 7 idle days and refreshes the activity time', function () {
    $admin = makeAdmin();
    $sixDaysAgo = now()->subDays(6)->getTimestamp();

    $this->actingAs($admin)
        ->withSession([EnforceAdminIdle::SESSION_KEY => $sixDaysAgo])
        ->get('/admin')
        ->assertOk()
        ->assertSessionHas(EnforceAdminIdle::SESSION_KEY, fn (int $value) => $value > $sixDaysAgo);

    expect(config('session.lifetime'))->toBe(10080);
});

it('logs the admin out after more than 7 idle days', function () {
    $admin = makeAdmin();

    $this->actingAs($admin)
        ->withSession([EnforceAdminIdle::SESSION_KEY => now()->subDays(7)->subMinute()->getTimestamp()])
        ->get('/admin')
        ->assertRedirect('/admin/login');

    $this->assertGuest();
});

it('applies an admin idle limit changed in settings', function () {
    app(Settings::class)->put(['admin_idle_minutes' => 60]);
    $admin = makeAdmin();

    $this->actingAs($admin)
        ->withSession([EnforceAdminIdle::SESSION_KEY => now()->subMinutes(61)->getTimestamp()])
        ->get('/admin')
        ->assertRedirect('/admin/login');
});

it('saves the session and SMS settings to the database and audits the change', function () {
    $this->actingAs(makeAdmin());

    Livewire::test(SystemSettings::class)
        ->set('data.member_long_login', false)
        ->set('data.member_idle_minutes', 1440)
        ->set('data.admin_idle_minutes', 4320)
        ->set('data.sms_provider', 'smsgo')
        ->set('data.sms_global_hourly_limit', 50)
        ->set('data.otp_max_attempts', 4)
        ->call('save')
        ->assertHasNoErrors();

    $settings = new Settings;
    expect($settings->bool('member_long_login'))->toBeFalse()
        ->and($settings->int('member_idle_minutes'))->toBe(1440)
        ->and($settings->int('admin_idle_minutes'))->toBe(4320)
        ->and($settings->string('sms_provider'))->toBe('smsgo')
        ->and($settings->int('sms_global_hourly_limit'))->toBe(50)
        ->and($settings->int('otp_max_attempts'))->toBe(4);

    $event = AuditEvent::query()->where('event', 'settings.updated')->firstOrFail();
    expect($event->details['changes']['sms_provider'])->toBe(['old' => 'fake', 'new' => 'smsgo']);
});

it('creates and publishes a notice from Filament that members can then read', function () {
    $this->actingAs(makeAdmin());

    Livewire::test(CreateNotice::class)
        ->set('data.title', '後台發布的公告')
        ->set('data.body', '內容')
        ->set('data.status', Notice::STATUS_PUBLISHED)
        ->call('create')
        ->assertHasNoErrors();

    $member = User::factory()->create();
    $this->app['auth']->forgetGuards();

    $this->actingAs($member, 'sanctum')->getJson('/api/v1/notices')
        ->assertOk()
        ->assertJsonPath('data.0.title', '後台發布的公告');
});
