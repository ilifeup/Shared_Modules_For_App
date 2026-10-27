<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('av_music_tracks', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('category', 100)->index();
            $table->string('encrypted_storage_path');
            $table->boolean('is_published')->default(false);
            $table->timestamps();
        });

        Schema::create('av_track_level_access', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('track_id')->constrained('av_music_tracks')->cascadeOnDelete();
            $table->foreignId('member_level_id')->constrained('membership_levels')->cascadeOnDelete();
            $table->unique(['track_id', 'member_level_id']);
        });

        Schema::create('av_track_member_access', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('track_id')->constrained('av_music_tracks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('allowed');
            $table->unique(['track_id', 'user_id']);
        });

        Schema::create('av_videos', function (Blueprint $table): void {
            $table->id();
            $table->string('youtube_id', 32)->unique();
            $table->string('title');
            $table->boolean('is_published')->default(false);
            $table->timestamps();
        });

        Schema::create('av_video_level_access', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('video_id')->constrained('av_videos')->cascadeOnDelete();
            $table->foreignId('member_level_id')->constrained('membership_levels')->cascadeOnDelete();
            $table->unique(['video_id', 'member_level_id']);
        });

        Schema::create('av_video_series', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(false);
            $table->timestamps();
        });

        Schema::create('av_series_videos', function (Blueprint $table): void {
            $table->foreignId('series_id')->constrained('av_video_series')->cascadeOnDelete();
            $table->foreignId('video_id')->constrained('av_videos')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->primary(['series_id', 'video_id']);
        });

        Schema::create('av_page_placements', function (Blueprint $table): void {
            $table->id();
            $table->string('page_code', 100);
            $table->string('block_code', 100);
            $table->foreignId('series_id')->nullable()->constrained('av_video_series')->cascadeOnDelete();
            $table->foreignId('video_id')->nullable()->constrained('av_videos')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_enabled')->default(false);
            $table->timestamps();
            $table->unique(['page_code', 'block_code', 'sort_order']);
            $table->index(['series_id', 'is_enabled']);
            $table->index(['video_id', 'is_enabled']);
        });

        // A placement must reference exactly one valid target. MySQL 8.4
        // enforces CHECK constraints, including for direct DB writes.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            \Illuminate\Support\Facades\DB::statement('ALTER TABLE av_page_placements ADD CONSTRAINT av_placement_one_target CHECK ((series_id IS NOT NULL AND video_id IS NULL) OR (series_id IS NULL AND video_id IS NOT NULL))');
        }
    }

    public function down(): void
    {
        foreach (['av_page_placements', 'av_series_videos', 'av_video_series', 'av_video_level_access', 'av_videos', 'av_track_member_access', 'av_track_level_access', 'av_music_tracks'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
