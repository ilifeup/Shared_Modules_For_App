<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_content_access_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 16);
            $table->string('resource_code', 120);
            $table->string('audience', 32);
            $table->boolean('allowed');
            $table->timestamps();
            $table->unique(['scope', 'resource_code', 'audience'], 'content_access_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_content_access_rules');
    }
};
