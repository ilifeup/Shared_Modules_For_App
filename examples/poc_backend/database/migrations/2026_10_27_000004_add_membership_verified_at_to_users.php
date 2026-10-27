<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('membership_verified_at')->nullable();
        });

        // One-time conversion of already verified accounts. Never derive ongoing
        // membership entitlement from the current contact-verification policy.
        DB::table('users')->whereNull('membership_verified_at')
            ->where(function ($query): void {
                $query->whereNotNull('email_verified_at')
                    ->orWhereNotNull('phone_verified_at')
                    ->orWhereNotNull('verification_grandfathered_at');
            })->update(['membership_verified_at' => DB::raw('COALESCE(verification_grandfathered_at, email_verified_at, phone_verified_at)')]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('membership_verified_at');
        });
    }
};
