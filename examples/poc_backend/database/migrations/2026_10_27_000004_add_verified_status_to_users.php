<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // 1 = registered/unverified; 2 = registered/verified.
            // Contact verification timestamps are not membership state.
            $table->unsignedTinyInteger('verified_status')->default(1)->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('verified_status');
        });
    }
};
