<?php

use App\Filament\Resources\Members\Pages\EditMember;
use App\Filament\Resources\Members\Pages\ListMembers;
use App\Models\AuditEvent;
use App\Models\PhoneOtp;
use App\Models\User;
use App\Support\Settings;
use Filament\Facades\Filament;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

function profileMember(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'name' => '王小明',
        'email' => 'me@example.com',
        'phone_e164' => '+886977000111',
        'phone_verified_at' => now(),
        'password' => 'correct-horse-1',
    ], $attributes));
}

it('reports missing required fields so old members can complete them', function () {
    $old = profileMember(['phone_e164' => null, 'phone_verified_at' => null]);

    $this->actingAs($old, 'sanctum')->getJson('/api/v1/me/profile')
        ->assertOk()
        ->assertJsonPath('data.name', '王小明')
        ->assertJsonPath('data.profile.complete', false)
        ->assertJsonPath('data.profile.missing', ['phone'])
        ->assertJsonPath('data.has_avatar', false);

    // Old data is kept untouched by reading it.
    expect($old->refresh()->email)->toBe('me@example.com');
});

it('lets a member update name and display name only', function () {
    $user = profileMember();

    $this->actingAs($user, 'sanctum')->patchJson('/api/v1/me/profile', ['name' => '王大明', 'email' => 'x@example.com'])
        ->assertOk()
        ->assertJsonPath('data.name', '王大明');

    $this->actingAs($user, 'sanctum')->patchJson('/api/v1/me/profile', ['name' => ''])->assertStatus(422);
    expect($user->refresh()->email)->toBe('me@example.com');
});

it('adds a phone for an old member only after the new number is verified', function () {
    $user = profileMember(['phone_e164' => null, 'phone_verified_at' => null]);

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/me/phone/send-otp', ['phone' => '0977000222', 'password' => 'wrong'])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['fields' => ['password']]]);
    expect(fakeSms()->sent)->toBeEmpty();

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/me/phone/send-otp', ['phone' => '0977000222', 'password' => 'correct-horse-1'])
        ->assertOk();
    expect(end(fakeSms()->sent)['phone'])->toBe('+886977000222')
        ->and(PhoneOtp::query()->latest('id')->firstOrFail()->only(['purpose', 'phone_e164']))
        ->toBe(['purpose' => PhoneOtp::PURPOSE_PHONE_CHANGE, 'phone_e164' => '+886977000222']);

    // Not saved before verification.
    expect($user->refresh()->phone_e164)->toBeNull();

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/me/phone/verify', ['phone' => '0977000222', 'code' => '000000'])
        ->assertStatus(422);
    expect($user->refresh()->phone_e164)->toBeNull();

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/me/phone/verify', ['phone' => '0977000222', 'code' => lastOtp()])
        ->assertOk()
        ->assertJsonPath('data.phone', '+886977000222')
        ->assertJsonPath('data.phone_verified', true)
        ->assertJsonPath('data.profile.complete', true);
});

it('changes a phone number only through a phone-change code for that number', function () {
    $user = profileMember();
    profileMember(['email' => 'other@example.com', 'phone_e164' => '+886977000333']);

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/me/phone/send-otp', ['phone' => '0977000333', 'password' => 'correct-horse-1'])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['fields' => ['phone']]]);

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/me/phone/send-otp', ['phone' => '0977000444', 'password' => 'correct-horse-1'])->assertOk();
    $code = lastOtp();

    // The code is bound to the new number.
    $this->actingAs($user, 'sanctum')->postJson('/api/v1/me/phone/verify', ['phone' => '0977000555', 'code' => $code])->assertStatus(422);

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/me/phone/verify', ['phone' => '0977000444', 'code' => $code])->assertOk();
    expect($user->refresh()->phone_e164)->toBe('+886977000444')
        ->and($user->phone_verified_at)->not->toBeNull()
        ->and(AuditEvent::query()->where('event', 'member.phone_changed')->exists())->toBeTrue();

    // Single use.
    $this->actingAs($user, 'sanctum')->postJson('/api/v1/me/phone/verify', ['phone' => '0977000444', 'code' => $code])->assertStatus(422);
});

it('rolls back a phone change and OTP consumption if saving the new number fails', function () {
    $user = profileMember();
    $oldPhone = $user->phone_e164;
    $oldVerifiedAt = $user->phone_verified_at;

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/me/phone/send-otp', [
            'phone' => '0977000888',
            'password' => 'correct-horse-1',
        ])->assertOk();

    $code = lastOtp();
    $otpId = PhoneOtp::query()->latest('id')->firstOrFail()->id;
    $failOnce = true;

    User::saving(function (User $saving) use (&$failOnce): void {
        if ($failOnce && $saving->phone_e164 === '+886977000888') {
            $failOnce = false;
            throw new RuntimeException('Simulated phone update failure');
        }
    });

    try {
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/me/phone/verify', [
                'phone' => '0977000888',
                'code' => $code,
            ])->assertStatus(500);

        expect($user->refresh()->phone_e164)->toBe($oldPhone)
            ->and($user->phone_verified_at->equalTo($oldVerifiedAt))->toBeTrue()
            ->and(PhoneOtp::findOrFail($otpId)->status)->toBe(PhoneOtp::STATUS_SENT)
            ->and(PhoneOtp::findOrFail($otpId)->used_at)->toBeNull()
            ->and(AuditEvent::query()->where('event', 'member.phone_changed')->exists())->toBeFalse();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/me/phone/verify', [
                'phone' => '0977000888',
                'code' => $code,
            ])->assertOk()->assertJsonPath('data.phone', '+886977000888');

        expect(PhoneOtp::findOrFail($otpId)->status)->toBe(PhoneOtp::STATUS_USED)
            ->and(AuditEvent::query()->where('event', 'member.phone_changed')->count())->toBe(1);
    } finally {
        User::flushEventListeners();
    }
});

it('marks a changed email unverified and requires verifying it again', function () {
    Notification::fake();
    $user = profileMember();
    $token = $user->createToken('mobile')->plainTextToken;
    profileMember(['email' => 'taken@example.com', 'phone_e164' => '+886977000666']);

    $this->withToken($token)->postJson('/api/v1/me/email', ['email' => 'taken@example.com', 'password' => 'correct-horse-1'])
        ->assertStatus(422);
    $this->withToken($token)->postJson('/api/v1/me/email', ['email' => 'new@example.com', 'password' => 'nope'])
        ->assertStatus(422);

    $this->withToken($token)->postJson('/api/v1/me/email', ['email' => 'New@Example.com', 'password' => 'correct-horse-1'])
        ->assertOk()
        ->assertJsonPath('data.email', 'new@example.com')
        ->assertJsonPath('data.email_verified', false);

    Notification::assertSentTo($user->refresh(), VerifyEmail::class);

    // Contact email needs re-verification, but verified membership persists in the database.
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/notices')->assertOk();
    expect((int) $user->refresh()->verified_status)->toBe(2);
});

it('keeps phone-change codes apart from registration and reset codes', function () {
    $user = profileMember(['phone_verified_at' => null]);
    app(Settings::class)->put(['verification_policy' => Settings::POLICY_PHONE]);

    $this->postJson('/api/v1/auth/phone/send-otp', ['phone' => '0977000111'])->assertOk();
    $registrationCode = lastOtp();

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/me/phone/verify', ['phone' => '0977000111', 'code' => $registrationCode])
        ->assertStatus(422);
    expect($user->refresh()->phone_verified_at)->toBeNull();
});

it('stores the avatar re-encoded on the private disk and serves it to the owner only', function () {
    Storage::fake('local');
    $user = profileMember();

    $this->actingAs($user, 'sanctum')->post('/api/v1/me/avatar', [
        'avatar' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertStatus(422);

    $this->actingAs($user, 'sanctum')->post('/api/v1/me/avatar', [
        'avatar' => UploadedFile::fake()->image('me.png', 900, 600),
    ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.has_avatar', true);

    $path = $user->refresh()->avatar_path;
    expect($path)->toStartWith("avatars/{$user->id}/")->toEndWith('.jpg');
    Storage::disk('local')->assertExists($path);
    [$width, $height] = getimagesizefromstring(Storage::disk('local')->get($path));
    expect([$width, $height])->toBe([512, 512]);

    $this->actingAs($user, 'sanctum')->get('/api/v1/me/avatar')
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');

    // Another member only ever reaches their own (missing) avatar.
    $other = profileMember(['email' => 'b@example.com', 'phone_e164' => '+886977000999']);
    $this->app['auth']->forgetGuards();
    $this->actingAs($other, 'sanctum')->getJson('/api/v1/me/avatar')->assertNotFound();

    // Replacing removes the old file; deleting clears it.
    $this->app['auth']->forgetGuards();
    $this->actingAs($user, 'sanctum')->post('/api/v1/me/avatar', ['avatar' => UploadedFile::fake()->image('me2.jpg', 300, 300)], ['Accept' => 'application/json'])->assertOk();
    Storage::disk('local')->assertMissing($path);

    $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/me/avatar')->assertOk()->assertJsonPath('data.has_avatar', false);
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/me/avatar')->assertNotFound();
});

it('lists, searches and filters members in Filament', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(makeAdmin());

    $complete = profileMember();
    $incomplete = profileMember(['name' => '李小華', 'email' => 'lee@example.com', 'phone_e164' => null, 'phone_verified_at' => null]);

    Livewire::test(ListMembers::class)
        ->assertCanSeeTableRecords([$complete, $incomplete])
        ->searchTable('李小華')
        ->assertCanSeeTableRecords([$incomplete])
        ->assertCanNotSeeTableRecords([$complete]);

    Livewire::test(ListMembers::class)
        ->filterTable('incomplete')
        ->assertCanSeeTableRecords([$incomplete])
        ->assertCanNotSeeTableRecords([$complete]);

    Livewire::test(ListMembers::class)
        ->filterTable('phone_verified_at', true)
        ->assertCanSeeTableRecords([$complete])
        ->assertCanNotSeeTableRecords([$incomplete]);
});

it('filters members by database qualification rather than verified email or phone', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(makeAdmin());

    $verified = profileMember([
        'email' => 'persisted-verified@example.com',
        'phone_e164' => '+886977001111',
        'verified_status' => 2,
        'email_verified_at' => null,
        'phone_verified_at' => null,
    ]);
    $pending = profileMember([
        'email' => 'contact-only@example.com',
        'phone_e164' => '+886977002222',
        'verified_status' => 1,
        'email_verified_at' => now(),
        'phone_verified_at' => now(),
    ]);

    Livewire::test(ListMembers::class)
        ->assertCanSeeTableRecords([$verified, $pending])
        ->filterTable('verified_status', '2')
        ->assertCanSeeTableRecords([$verified])
        ->assertCanNotSeeTableRecords([$pending]);

    Livewire::test(ListMembers::class)
        ->filterTable('verified_status', '1')
        ->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$verified]);
});

it('clears verification when an admin changes a member contact', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $admin = makeAdmin();
    $this->actingAs($admin);
    $member = profileMember();

    Livewire::test(EditMember::class, ['record' => $member->getRouteKey()])
        ->fillForm(['phone_e164' => '+886977000777'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($member->refresh()->phone_e164)->toBe('+886977000777')
        ->and($member->phone_verified_at)->toBeNull()
        ->and($member->email_verified_at)->not->toBeNull();

    $event = AuditEvent::query()->where('event', 'member.updated_by_admin')->firstOrFail();
    expect($event->actor_id)->toBe($admin->id)->and($event->details['fields'])->toContain('phone_e164');
});

it('hides member management from an admin without members.manage', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $admin = makeAdmin();
    Role::findByName(User::ADMIN_ROLE, 'web')->revokePermissionTo('members.manage');
    $this->actingAs($admin->refresh());

    $this->get('/admin/members')->assertForbidden();
});
