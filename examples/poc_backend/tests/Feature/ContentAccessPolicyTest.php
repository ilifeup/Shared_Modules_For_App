<?php

use App\Models\AuditEvent;
use App\Models\MemberLevelAssignment;
use App\Models\MembershipLevel;
use App\Models\MemberVerification;
use App\Models\User;
use App\Models\VerificationType;
use App\Services\ContentAccess\ContentAccessPolicy;
use App\Services\ContentAccess\ContentAccessRuleManager;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
        ['scope' => 'topic', 'resource_code' => 'articles/news/private', 'audience' => 'register_member', 'allowed' => true],
    ]);

    $policy = app(ContentAccessPolicy::class);
    expect($policy->allows(null, 'articles', 'news'))->toBeTrue()
        ->and($policy->allows(null, 'articles', 'news', 'private'))->toBeFalse();
});

it('makes explicit denial override member and verified grants', function (): void {
    $member = User::factory()->create(['email_verified_at' => now()]);
    DB::table('app_content_access_rules')->insert([
        ['scope' => 'page', 'resource_code' => 'av', 'audience' => 'register_member', 'allowed' => true],
        ['scope' => 'page', 'resource_code' => 'av', 'audience' => 'user:'.$member->id, 'allowed' => false],
    ]);

    expect(app(ContentAccessPolicy::class)->allows($member, 'av'))->toBeFalse();
});

it('allows an administrator to configure a page and list but not a restricted topic', function (): void {
    $admin = makeAdmin();
    $manager = app(ContentAccessRuleManager::class);
    $manager->save($admin, 'page', 'articles', 'guest', true);
    $manager->save($admin, 'list', 'articles/news', 'guest', true);
    $manager->save($admin, 'topic', 'articles/news/locked', 'guest', false);

    expect(app(ContentAccessPolicy::class)->allows(null, 'articles', 'news'))->toBeTrue()
        ->and(app(ContentAccessPolicy::class)->allows(null, 'articles', 'news', 'locked'))->toBeFalse();
});

it('prevents ordinary members from changing App page rules', function (): void {
    $member = User::factory()->create();
    expect(fn () => app(ContentAccessRuleManager::class)
        ->save($member, 'page', 'av', 'guest', true))->toThrow(HttpException::class);
});

it('rejects malformed rule identifiers and mismatched hierarchy', function (): void {
    $admin = makeAdmin();
    expect(fn () => app(ContentAccessRuleManager::class)
        ->save($admin, 'topic', 'av/track', 'guest', true))->toThrow(InvalidArgumentException::class);
});

it('audits administrator rule creation, changes and deletion without duplicate events', function (): void {
    $admin = makeAdmin();
    $manager = app(ContentAccessRuleManager::class);

    $manager->save($admin, 'page', 'av', 'guest', true);
    $manager->save($admin, 'page', 'av', 'guest', true);
    $manager->save($admin, 'page', 'av', 'guest', false);
    $manager->remove($admin, 'page', 'av', 'guest');

    expect(AuditEvent::query()->where('event', 'content.access_rule_saved')->count())->toBe(2)
        ->and(AuditEvent::query()->where('event', 'content.access_rule_removed')->count())->toBe(1)
        ->and(DB::table('app_content_access_rules')->where('resource_code', 'av')->exists())->toBeFalse();
});

it('respects existing settings.manage permission without creating another administrator role', function (): void {
    $admin = makeAdmin();
    Role::findByName(User::ADMIN_ROLE, 'web')->revokePermissionTo('settings.manage');

    expect(fn () => app(ContentAccessRuleManager::class)
        ->save($admin->refresh(), 'page', 'av', 'guest', true))
        ->toThrow(HttpException::class);
});

it('grants only currently active membership levels and rejects expired or revoked levels', function (): void {
    $member = User::factory()->create();
    $level = MembershipLevel::query()->where('code', MembershipLevel::VIP)->firstOrFail();
    $manager = app(ContentAccessRuleManager::class);
    $admin = makeAdmin();
    $manager->save($admin, 'page', 'av', 'level:'.$level->id, true);

    expect(app(ContentAccessPolicy::class)->allows($member, 'av'))->toBeFalse();

    $assignment = MemberLevelAssignment::query()->create([
        'user_id' => $member->id,
        'membership_level_id' => $level->id,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(),
        'assigned_by' => $admin->id,
        'reason' => 'content access test',
    ]);

    expect(app(ContentAccessPolicy::class)->allows($member, 'av'))->toBeTrue();

    $assignment->forceFill(['revoked_at' => now()])->save();
    expect(app(ContentAccessPolicy::class)->allows($member, 'av'))->toBeFalse();
});

it('treats unauthenticated requests as Guest even when a registered account exists', function (): void {
    $registered = User::factory()->create(['email_verified_at' => now()]);
    DB::table('app_content_access_rules')->insert([
        ['scope' => 'page', 'resource_code' => 'articles', 'audience' => 'register_member', 'allowed' => true],
        ['scope' => 'page', 'resource_code' => 'articles', 'audience' => 'verified_member', 'allowed' => true],
    ]);

    $policy = app(ContentAccessPolicy::class);
    expect($policy->allows(null, 'articles'))->toBeFalse()
        ->and($policy->allows($registered, 'articles'))->toBeTrue();

    DB::table('app_content_access_rules')->insert([
        'scope' => 'page', 'resource_code' => 'articles', 'audience' => 'guest', 'allowed' => true,
    ]);
    expect($policy->allows(null, 'articles'))->toBeTrue();
});

it('rejects deprecated all, member and verified audience codes', function (): void {
    $manager = app(ContentAccessRuleManager::class);
    $admin = makeAdmin();
    foreach (['all', 'member', 'verified'] as $old) {
        expect(fn () => $manager->save($admin, 'page', 'articles', $old, true))
            ->toThrow(InvalidArgumentException::class);
    }
});

it('denies inactive authenticated members even when no content rules are configured', function (): void {
    $inactive = User::factory()->create(['is_active' => false]);
    $policy = app(ContentAccessPolicy::class);

    expect($policy->allowsWhenConfigured($inactive, 'av'))->toBeFalse()
        ->and($policy->allowsWhenConfigured($inactive, 'av', 'music'))->toBeFalse()
        ->and($policy->allowsWhenConfigured(null, 'av'))->toBeTrue();
});

it('grants badge-scoped access only while the M03 approval is valid and honors explicit denial', function (): void {
    $admin = makeAdmin();
    $member = User::factory()->create();
    $type = VerificationType::query()->create([
        'name' => 'Access badge', 'requires_evidence' => false, 'required_approvals' => 1,
        'validity_days' => null, 'allow_multiple' => false,
        'revoke_notify' => VerificationType::NOTIFY_NONE, 'is_active' => true,
    ]);
    $manager = app(ContentAccessRuleManager::class);
    $policy = app(ContentAccessPolicy::class);
    $manager->save($admin, 'page', 'av', 'badge:'.$type->id, true);

    expect($policy->allows(null, 'av'))->toBeFalse()
        ->and($policy->allows($member, 'av'))->toBeFalse();

    $badge = MemberVerification::query()->create([
        'user_id' => $member->id, 'verification_type_id' => $type->id,
        'granted_at' => now()->subDay(), 'expires_at' => now()->addDay(),
        'is_public' => false,
    ]);
    expect($policy->allows($member, 'av'))->toBeTrue();

    $badge->forceFill(['revoked_at' => now()])->save();
    expect($policy->allows($member, 'av'))->toBeFalse();

    $badge->forceFill(['revoked_at' => null, 'expires_at' => now()->subSecond()])->save();
    expect($policy->allows($member, 'av'))->toBeFalse();

    $badge->forceFill(['expires_at' => now()->addDay()])->save();
    $manager->save($admin, 'page', 'av', 'user:'.$member->id, false);
    expect($policy->allows($member, 'av'))->toBeFalse();
});

it('does not let an inactive account inherit guest access to AV content', function (): void {
    $inactive = User::factory()->create(['is_active' => false, 'email_verified_at' => now()]);
    DB::table('app_content_access_rules')->insert([
        ['scope' => 'page', 'resource_code' => 'av', 'audience' => 'guest', 'allowed' => true],
        ['scope' => 'page', 'resource_code' => 'av', 'audience' => 'verified_member', 'allowed' => true],
        ['scope' => 'list', 'resource_code' => 'av/videos', 'audience' => 'guest', 'allowed' => true],
    ]);

    $policy = app(ContentAccessPolicy::class);
    expect($policy->allows(null, 'av', 'videos'))->toBeTrue()
        ->and($policy->allows($inactive, 'av', 'videos'))->toBeFalse()
        ->and($policy->allowsWhenConfigured($inactive, 'av', 'videos'))->toBeFalse();
});

it('keeps verified-member AV access separate from guest visibility and explicit denial', function (): void {
    $member = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
    $policy = app(ContentAccessPolicy::class);
    DB::table('app_content_access_rules')->insert([
        ['scope' => 'page', 'resource_code' => 'av', 'audience' => 'verified_member', 'allowed' => true],
        ['scope' => 'list', 'resource_code' => 'av/lessons', 'audience' => 'verified_member', 'allowed' => true],
        ['scope' => 'topic', 'resource_code' => 'av/lessons/intro', 'audience' => 'verified_member', 'allowed' => true],
    ]);

    expect($policy->allows(null, 'av', 'lessons', 'intro'))->toBeFalse()
        ->and($policy->allows($member, 'av', 'lessons', 'intro'))->toBeTrue();

    DB::table('app_content_access_rules')->insert([
        'scope' => 'topic', 'resource_code' => 'av/lessons/intro',
        'audience' => 'user:'.$member->id, 'allowed' => false,
    ]);
    expect($policy->allows($member, 'av', 'lessons', 'intro'))->toBeFalse();
});
