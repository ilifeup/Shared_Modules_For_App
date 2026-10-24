<?php

use App\Models\User;
use App\Services\Sms\FakeSmsGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * The single App administrator (Spatie role "admin").
 */
function makeAdmin(array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->assignRole(Role::findOrCreate(User::ADMIN_ROLE, 'web'));

    return $user;
}

function fakeSms(): FakeSmsGateway
{
    return app(FakeSmsGateway::class);
}

/**
 * The OTP contained in the last fake SMS.
 */
function lastOtp(): string
{
    $last = end(fakeSms()->sent);
    preg_match('/驗證碼 (\d{6})/u', $last['message'] ?? '', $matches);

    return $matches[1] ?? '';
}
