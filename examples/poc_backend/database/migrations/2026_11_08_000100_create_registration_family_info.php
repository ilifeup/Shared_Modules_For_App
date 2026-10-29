<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional, self-declared family context for an independent registration.
 * Not an identity match, existing family membership, or delegation grant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_family_info', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('family_name', 255);
            $table->string('relationship', 80)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_family_info');
    }
};
