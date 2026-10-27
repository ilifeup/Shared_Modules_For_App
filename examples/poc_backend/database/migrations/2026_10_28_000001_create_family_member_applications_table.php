<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->foreignId('created_member_user_id')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['manager_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_member_applications');
    }
};
