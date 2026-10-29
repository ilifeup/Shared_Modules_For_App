<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('allows independent provider bindings without granting verified membership', function () {
    $user = User::factory()->unverified()->create();
    foreach (['google' => 'google-sub-123', 'apple' => 'apple-sub-456'] as $provider => $subject) {
        DB::table('user_social_accounts')->insert([
            'user_id' => $user->id,
            'provider' => $provider,
            'provider_subject' => $subject,
            'linked_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    expect(DB::table('user_social_accounts')->where('user_id', $user->id)->count())->toBe(2)
        ->and((int) $user->fresh()->verified_status)->toBe(1);
});

it('prevents one external identity from being linked to different members', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();
    $values = ['provider' => 'google', 'provider_subject' => 'stable-sub', 'linked_at' => now(), 'created_at' => now(), 'updated_at' => now()];
    DB::table('user_social_accounts')->insert(['user_id' => $first->id] + $values);

    expect(fn () => DB::table('user_social_accounts')->insert(['user_id' => $second->id] + $values))
        ->toThrow(QueryException::class);
});

it('prevents a member linking two different accounts from the same provider', function () {
    $user = User::factory()->create();
    $values = ['user_id' => $user->id, 'provider' => 'apple', 'linked_at' => now(), 'created_at' => now(), 'updated_at' => now()];
    DB::table('user_social_accounts')->insert($values + ['provider_subject' => 'first-sub']);

    expect(fn () => DB::table('user_social_accounts')->insert($values + ['provider_subject' => 'second-sub']))
        ->toThrow(QueryException::class);
});
