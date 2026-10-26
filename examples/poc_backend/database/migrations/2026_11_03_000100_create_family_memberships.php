<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('manager_user_id')->constrained('users')->restrictOnDelete();
            $table->string('relationship', 40)->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            // MySQL permits multiple NULLs in a UNIQUE index: historical revoked
            // relationships do not block a new manager, but only one active row
            // can exist for each member. SQLite supports generated columns too.
            $table->unsignedBigInteger('active_member_user_id')->nullable()->storedAs(
                "case when status = 'active' then member_user_id else null end"
            );
            $table->unique('active_member_user_id', 'family_one_active_manager');
            $table->index(['manager_user_id', 'status']);
            $table->timestamps();
        });

        Schema::create('family_membership_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('membership_id')->constrained('family_memberships')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->json('before_data')->nullable();
            $table->json('after_data')->nullable();
            $table->timestamps();
            $table->index(['membership_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_membership_audits');
        Schema::dropIfExists('family_memberships');
    }
};
