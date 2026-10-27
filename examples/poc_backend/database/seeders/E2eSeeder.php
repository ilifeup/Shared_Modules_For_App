<?php

namespace Database\Seeders;

use App\Models\Notice;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Synthetic data for the CI end-to-end test. Refuses to run in production.
 */
class E2eSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('E2eSeeder must never run in production.');
        }

        User::query()->create([
            'name' => 'E2E Member',
            'display_name' => 'E2E Member',
            'email' => 'e2e@example.com',
            'password' => (string) env('E2E_MEMBER_PASSWORD', 'e2e-synthetic-password'),
            'is_active' => true,
        ])->forceFill(['verified_status' => 2, 'email_verified_at' => now()])->save();

        Notice::query()->create([
            'title' => 'E2E 已發布公告',
            'body' => '由 E2E 種子資料建立。',
            'status' => Notice::STATUS_PUBLISHED,
            'published_at' => now()->subMinute(),
        ]);

        Notice::query()->create([
            'title' => 'E2E 草稿',
            'body' => '不應出現在會員 API。',
            'status' => Notice::STATUS_DRAFT,
        ]);
    }
}
