<?php

use App\Filament\Pages\FamilyManagement;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('only downloads family evidence from the private evidence directory via API', function (): void {
    Storage::fake('local');
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $manager = User::factory()->create();

    Storage::disk('local')->put('private/unrelated.txt', 'private data');
    Storage::disk('local')->put('family-evidence/valid.pdf', 'valid evidence');

    $id = DB::table('family_member_applications')->insertGetId([
        'manager_user_id' => $manager->id,
        'member_name' => 'Child',
        'relationship' => 'child',
        'birth_date' => '2015-01-01',
        'address' => 'Home',
        'evidence_path' => 'private/unrelated.txt',
        'status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach (['private/unrelated.txt', 'family-evidence/../private/unrelated.txt'] as $invalidPath) {
        DB::table('family_member_applications')->where('id', $id)
            ->update(['evidence_path' => $invalidPath]);
        $this->actingAs($admin, 'sanctum')
            ->get("/api/v1/admin/family-applications/{$id}/evidence")
            ->assertNotFound();
    }

    DB::table('family_member_applications')->where('id', $id)
        ->update(['evidence_path' => 'family-evidence/valid.pdf']);
    $this->actingAs($admin, 'sanctum')
        ->get("/api/v1/admin/family-applications/{$id}/evidence")
        ->assertOk();
});

it('blocks out-of-directory evidence downloads from the Filament page', function (): void {
    Storage::fake('local');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    $manager = User::factory()->create();
    Storage::disk('local')->put('private/unrelated.txt', 'private data');
    $id = DB::table('family_member_applications')->insertGetId([
        'manager_user_id' => $manager->id,
        'member_name' => 'Child',
        'relationship' => 'child',
        'birth_date' => '2015-01-01',
        'address' => 'Home',
        'evidence_path' => 'private/unrelated.txt',
        'status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test(FamilyManagement::class)
        ->call('downloadEvidence', $id)
        ->assertHasErrors(['reviewNote']);
});
