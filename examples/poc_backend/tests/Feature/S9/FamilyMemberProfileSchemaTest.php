<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

it('stores separate family identities while allowing shared profile contact information', function () {
    $manager = User::factory()->create();
    $first = User::factory()->create();
    $second = User::factory()->create();

    foreach ([$first, $second] as $member) {
        DB::table('family_member_profiles')->insert([
            'member_user_id' => $member->id,
            'birth_date' => '2012-03-04',
            'address' => '同一家庭地址',
            'contact_phone' => '0912345678',
            'contact_email' => 'family@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    expect(DB::table('family_member_profiles')->where('contact_email', 'family@example.test')->count())->toBe(2)
        ->and($first->id)->not->toBe($second->id)
        ->and($manager->id)->not->toBe($first->id);
});

it('does not treat family profile contact data as verified membership', function () {
    $member = User::factory()->unverified()->create();
    DB::table('family_member_profiles')->insert([
        'member_user_id' => $member->id,
        'birth_date' => '2012-03-04',
        'address' => '家庭地址',
        'contact_phone' => '0912345678',
        'contact_email' => 'family@example.test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect((int) $member->fresh()->verified_status)->toBe(1);
});
