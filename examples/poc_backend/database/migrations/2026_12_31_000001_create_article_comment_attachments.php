<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_comment_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_comment_id')->constrained('article_comments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('storage_path', 512)->unique();
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->timestamp('view_expires_at')->index();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('article_attachment_bookmarks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('attachment_id')->constrained('article_comment_attachments')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'attachment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_attachment_bookmarks');
        Schema::dropIfExists('article_comment_attachments');
    }
};
