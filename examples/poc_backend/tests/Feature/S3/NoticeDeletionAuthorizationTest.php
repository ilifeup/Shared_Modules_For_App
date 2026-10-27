<?php

use App\Filament\Resources\Notices\NoticeResource;
use App\Models\Notice;
use App\Models\User;
use Spatie\Permission\Models\Permission;

it('denies draft notice deletion without notices.manage even for an authenticated member', function (): void {
    $member = User::factory()->create();
    $draft = new Notice(['status' => Notice::STATUS_DRAFT]);

    $this->actingAs($member);
    expect(NoticeResource::canDelete($draft))->toBeFalse();

    Permission::findOrCreate('notices.manage', 'web');
    $member->givePermissionTo('notices.manage');

    expect(NoticeResource::canDelete($draft))->toBeTrue();
});

it('does not allow deleting published or withdrawn notices with management permission', function (): void {
    $manager = User::factory()->create();
    Permission::findOrCreate('notices.manage', 'web');
    $manager->givePermissionTo('notices.manage');
    $this->actingAs($manager);

    expect(NoticeResource::canDelete(new Notice(['status' => Notice::STATUS_PUBLISHED])))->toBeFalse()
        ->and(NoticeResource::canDelete(new Notice(['status' => Notice::STATUS_WITHDRAWN])))->toBeFalse();
});

it('does not allow guests to delete draft notices', function (): void {
    expect(NoticeResource::canDelete(new Notice(['status' => Notice::STATUS_DRAFT])))->toBeFalse();
});
