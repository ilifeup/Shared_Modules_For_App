<?php

namespace Database\Seeders;

use App\Models\Notice;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Local / test data only. Never run against a production database.
 * The administrator is created separately with: php artisan app:create-admin you@example.com
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DatabaseSeeder creates demo data and must never run in production.');
        }

        User::factory()->create([
            'name' => 'Demo Member',
            'display_name' => '示範會員',
            'email' => 'member@example.com',
            'phone_e164' => '+886900000001',
            'phone_verified_at' => now(),
        ]);

        Notice::query()->create([
            'title' => '歡迎使用 S1 PoC',
            'body' => '這是一則已發布的示範公告。',
            'status' => Notice::STATUS_PUBLISHED,
            'published_at' => now()->subMinute(),
        ]);

        Notice::query()->create([
            'title' => '草稿公告（會員看不到）',
            'body' => '尚未發布。',
            'status' => Notice::STATUS_DRAFT,
        ]);
    }
}
