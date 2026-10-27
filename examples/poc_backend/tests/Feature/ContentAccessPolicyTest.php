<?php

use App\Models\User;
use App\Services\ContentAccess\ContentAccessPolicy;
use Illuminate\Support\Facades\DB;

it('denies missing rules and does not let a permitted topic bypass its list', function (): void {
    $policy = app(ContentAccessPolicy::class);
    expect($policy->allows(null, 'av'))->toBeFalse();

    DB::table('app_content_access_rules')->insert([
        ['scope' => 'page', 'resource_code' => 'av', 'audience' => 'guest', 'allowed' => true],
        ['scope' => 'topic', 'resource_code' => 'av/music/song1', 'audience' => 'guest', 'allowed' => true],
    ]);

    expect($policy->allows(null, 'av'))->toBeTrue()
        ->and($policy->allows(null, 'av', 'music', 'song1'))->toBeFalse();
});

it('allows public lists but denies restricted topics', function (): void {
    DB::table('app_content_access_rules')->insert([
        ['scope' => 'page', 'resource_code' => 'articles', 'audience' => 'guest', 'allowed' => true],
        ['scope' => 'list', 'resource_code' => 'articles/news', 'audience' => 'guest', 'allowed' => true],
        ['scope' => 'topic', 'resource_code' => 'articles/news/private', 'audience' => 'member', 'allowed' => true],
    ]);

    $policy = app(ContentAccessPolicy::class);
    expect($policy->allows(null, 'articles', 'news'))->toBeTrue()
        ->and($policy->allows(null, 'articles', 'news', 'private'))->toBeFalse();
});

it('makes explicit denial override member and verified grants', function (): void {
    $member = User::factory()->create(['email_verified_at' => now()]);
    DB::table('app_content_access_rules')->insert([
        ['scope' => 'page', 'resource_code' => 'av', 'audience' => 'member', 'allowed' => true],
        ['scope' => 'page', 'resource_code' => 'av', 'audience' => 'user:'.$member->id, 'allowed' => false],
    ]);

    expect(app(ContentAccessPolicy::class)->allows($member, 'av'))->toBeFalse();
});
