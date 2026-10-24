<?php

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\User;
use App\Services\Sms\FakeSmsGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * The single App administrator (Spatie role "admin").
 */
function makeAdmin(array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->assignRole(Role::findOrCreate(User::ADMIN_ROLE, 'web'));

    return $user;
}

function fakeSms(): FakeSmsGateway
{
    return app(FakeSmsGateway::class);
}

/**
 * The OTP contained in the last fake SMS.
 */
function lastOtp(): string
{
    $last = end(fakeSms()->sent);
    preg_match('/驗證碼 (\d{6})/u', $last['message'] ?? '', $matches);

    return $matches[1] ?? '';
}

// S8 M05 article helpers.

function articleCategory(string $name = '一般', ?ArticleCategory $parent = null): ArticleCategory
{
    return ArticleCategory::query()->create(['name' => $name, 'parent_id' => $parent?->id]);
}

/**
 * A live public article in one category unless overridden.
 */
function liveArticle(array $attributes = [], ?ArticleCategory $category = null): Article
{
    $article = Article::query()->create(array_merge([
        'title' => '測試文章',
        'body_html' => '<p>內文</p>',
        'author_name' => '作者',
        'status' => Article::STATUS_PUBLISHED,
        'published_at' => now()->subHour(),
        'visibility' => Article::VISIBILITY_PUBLIC,
    ], $attributes));
    $article->categories()->sync([($category ?? articleCategory('分類'.Str::random(8)))->id]);

    return $article;
}

function articleMember(array $attributes = []): User
{
    return User::factory()->create(array_merge(['nickname' => '暱稱'.Str::random(6)], $attributes));
}
