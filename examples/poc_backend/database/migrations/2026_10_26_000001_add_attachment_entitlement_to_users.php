<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('attachment_upload_enabled')->default(false);
            $table->timestamp('attachment_upload_expires_at')->nullable();
            $table->unsignedInteger('attachment_weekly_upload_count')->nullable();
            $table->unsignedBigInteger('attachment_weekly_upload_bytes')->nullable();
            $table->text('admin_identity_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'attachment_upload_enabled',
                'attachment_upload_expires_at',
                'attachment_weekly_upload_count',
                'attachment_weekly_upload_bytes',
                'admin_identity_note',
            ]);
        });
    }
};
