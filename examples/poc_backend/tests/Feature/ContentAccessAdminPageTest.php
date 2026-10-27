<?php

use App\Filament\Pages\ContentAccessSettings;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('lets an administrator configure all three levels in the Filament page', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(makeAdmin());

    $this->get('/admin/content-access-settings')->assertOk();

    Livewire::test(ContentAccessSettings::class)
        ->set('scope', 'page')
        ->set('resourceCode', 'articles')
        ->set('audience', 'guest')
        ->set('decision', 'allow')
        ->call('saveRule')
        ->assertHasNoErrors();

    expect(DB::table('app_content_access_rules')
        ->where('scope', 'page')
        ->where('resource_code', 'articles')
        ->where('audience', 'guest')
        ->value('allowed'))->toBe(1);

    Livewire::test(ContentAccessSettings::class)
        ->call('removeRule', 'page', 'articles', 'guest');

    expect(DB::table('app_content_access_rules')->where('resource_code', 'articles')->exists())->toBeFalse();
});

it('rejects ordinary members from the administration page and mutations', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(User::factory()->create());

    $this->get('/admin/content-access-settings')->assertForbidden();

    Livewire::test(ContentAccessSettings::class)
        ->set('scope', 'page')
        ->set('resourceCode', 'av')
        ->call('saveRule')
        ->assertForbidden();

    expect(DB::table('app_content_access_rules')->count())->toBe(0);
});
