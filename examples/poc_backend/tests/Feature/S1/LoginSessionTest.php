<?php

use App\Models\User;
use App\Support\Settings;
use Laravel\Sanctum\PersonalAccessToken;

function issueToken(User $user): string
{
    return $user->createToken('mobile')->plainTextToken;
}

it('rejects a wrong password with a generic 401', function () {
    User::factory()->create(['email' => 'member@example.com', 'password' => 'correct-horse-1']);

    $this->postJson('/api/v1/auth/login', ['identifier' => 'member@example.com', 'password' => 'wrong-password'])
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

    $this->postJson('/api/v1/auth/login', ['identifier' => 'nobody@example.com', 'password' => 'wrong-password'])
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
});

it('blocks disabled accounts at login and on existing tokens', function () {
    $user = User::factory()->create(['email' => 'member@example.com', 'password' => 'correct-horse-1']);
    $token = issueToken($user);

    $user->update(['is_active' => false]);

    $this->postJson('/api/v1/auth/login', ['identifier' => 'member@example.com', 'password' => 'correct-horse-1'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'ACCOUNT_DISABLED');

    $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
});

it('revokes only the current token on logout', function () {
    $user = User::factory()->create();
    $phoneToken = issueToken($user);
    $tabletToken = issueToken($user);

    $this->withToken($phoneToken)->postJson('/api/v1/auth/logout')->assertOk();
    $this->app['auth']->forgetGuards();

    $this->withToken($phoneToken)->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');
    $this->app['auth']->forgetGuards();

    $this->withToken($tabletToken)->getJson('/api/v1/me')->assertOk();
});

it('keeps members logged in by default (long login, no fixed expiry)', function () {
    $user = User::factory()->create();
    $token = issueToken($user);

    PersonalAccessToken::query()->update(['last_used_at' => now()->subDays(400), 'created_at' => now()->subDays(400)]);

    $this->withToken($token)->getJson('/api/v1/me')->assertOk();
});

it('expires idle member tokens when the admin turns long login off', function () {
    app(Settings::class)->put(['member_long_login' => false, 'member_idle_minutes' => 60]);

    $user = User::factory()->create();
    $idle = issueToken($user);
    $active = issueToken($user);

    PersonalAccessToken::query()->whereKey(explode('|', $idle)[0])->update(['last_used_at' => now()->subMinutes(61)]);
    PersonalAccessToken::query()->whereKey(explode('|', $active)[0])->update(['last_used_at' => now()->subMinutes(30)]);

    $this->withToken($idle)->getJson('/api/v1/me')->assertUnauthorized();
    $this->app['auth']->forgetGuards();

    expect(PersonalAccessToken::query()->whereKey(explode('|', $idle)[0])->exists())->toBeFalse();

    // Activity slides the window: the active token is refreshed and keeps working.
    $this->withToken($active)->getJson('/api/v1/me')->assertOk();
    expect(PersonalAccessToken::query()->find(explode('|', $active)[0])->last_used_at->gt(now()->subMinute()))->toBeTrue();
});

it('requires a token for member endpoints', function () {
    $this->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');
    $this->getJson('/api/v1/notices')->assertUnauthorized();
});
