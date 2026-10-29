<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedTinyInteger('verified_status')->default(1)->after('is_active');
        });

        // Do not infer membership status from contact verification timestamps.
        // Existing accounts require a separately reviewed status migration.
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('verified_status'));
    }
};
