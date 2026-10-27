<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provider identity is the verified OIDC subject, never the provider email.
 * Social login does not change users.verified_status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_social_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('provider_subject', 255);
            $table->timestamp('linked_at');
            $table->timestamps();

            $table->unique(['provider', 'provider_subject'], 'social_provider_subject_unique');
            $table->unique(['user_id', 'provider'], 'social_user_provider_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_social_accounts');
    }
};
