<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_member_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('manager_user_id')->constrained('users');
            $table->string('member_name');
            $table->string('relationship', 40);
            $table->date('birth_date');
            $table->string('address', 500);
            $table->string('contact_phone', 40)->nullable();
            $table->string('contact_email')->nullable();
            $table->string('evidence_disk', 40)->default('local');
            $table->string('evidence_path', 500);
            $table->string('status', 20)->default('pending');
            $table->boolean('payment_verified')->default(false);
            $table->foreignId('payment_verified_by')->nullable()->constrained('users');
            $table->timestamp('payment_verified_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->foreignId('created_member_user_id')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['manager_user_id', 'status']);
        });
        Schema::create('family_member_paid_credits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('manager_user_id')->constrained('users');
            $table->foreignId('verified_by')->constrained('users');
            $table->string('payment_reference', 120)->unique();
            $table->foreignId('used_application_id')->nullable()->unique()->constrained('family_member_applications');
            $table->timestamps();
            $table->index(['manager_user_id', 'used_application_id'], 'fam_paid_mgr_used_idx');
        });
        Schema::create('family_member_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedInteger('max_members')->default(2);
            $table->timestamps();
        });
        DB::table('family_member_settings')->insert([
            'id' => 1, 'max_members' => 2, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('family_member_paid_credits');
        Schema::dropIfExists('family_member_applications');
        Schema::dropIfExists('family_member_settings');
    }
};
