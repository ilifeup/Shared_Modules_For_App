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

it('allows an administrator to configure a page and list but not a restricted topic', function (): void {
    $admin = makeAdmin();
    $manager = app(\App\Services\ContentAccess\ContentAccessRuleManager::class);
    $manager->save($admin, 'page', 'articles', 'guest', true);
    $manager->save($admin, 'list', 'articles/news', 'guest', true);
    $manager->save($admin, 'topic', 'articles/news/locked', 'guest', false);

    expect(app(ContentAccessPolicy::class)->allows(null, 'articles', 'news'))->toBeTrue()
        ->and(app(ContentAccessPolicy::class)->allows(null, 'articles', 'news', 'locked'))->toBeFalse();
});

it('prevents ordinary members from changing App page rules', function (): void {
    $member = User::factory()->create();
    expect(fn () => app(\App\Services\ContentAccess\ContentAccessRuleManager::class)
        ->save($member, 'page', 'av', 'guest', true))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('rejects malformed rule identifiers and mismatched hierarchy', function (): void {
    $admin = makeAdmin();
    expect(fn () => app(\App\Services\ContentAccess\ContentAccessRuleManager::class)
        ->save($admin, 'topic', 'av/track', 'guest', true))->toThrow(\InvalidArgumentException::class);
});
