<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Family member identity remains users.id. Contact fields here are profile
 * information only: they may be shared and never grant verified membership.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_member_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->date('birth_date');
            $table->string('address', 500);
            $table->string('contact_phone', 40)->nullable();
            $table->string('contact_email', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_member_profiles');
    }
};
