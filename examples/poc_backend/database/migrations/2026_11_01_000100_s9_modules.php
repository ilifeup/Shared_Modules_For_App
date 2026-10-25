<?php

use App\Services\Booking\LegacyBookingBackfill;
use App\Support\S9DataGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * S9 nine modules (M03, M08, M09, M11, M14, M15, M16, M17, M19).
 *
 * Additive only: new tables and new nullable/defaulted columns. S5 calendar_events
 * stays the single authority for every real occurrence (S9-L05 A) and S6
 * event_bookings with UNIQUE(calendar_event_id,user_id,active) stays the member
 * booking anchor (S9-L06 A). The seat ledger is event_booking_attendees; existing
 * confirmed bookings are backfilled (idempotent, verified) so old and new entry
 * points share one count. down() removes only what this migration added and refuses
 * to run while S9 data exists unless the operator accepts the loss (S9DataGuard).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- M03 identity verification -------------------------------------------------
        Schema::create('verification_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->text('description')->nullable();
            $table->boolean('requires_evidence')->default(false);
            $table->unsignedTinyInteger('required_approvals')->default(1);
            $table->unsignedInteger('validity_days')->nullable();   // null = permanent (D04)
            $table->boolean('allow_multiple')->default(true);         // D09
            $table->string('revoke_notify', 10)->default('app');      // none | app | email (D10)
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('verification_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('verification_type_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('pending'); // pending | approved | rejected | withdrawn
            $table->text('note')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('verification_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('verification_request_id')->constrained()->cascadeOnDelete();
            $table->string('path');           // private local disk
            $table->string('original_name');
            $table->string('mime', 120);
            $table->unsignedInteger('size');
            $table->timestamps();
        });

        Schema::create('verification_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('verification_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision', 10); // approve | reject
            $table->string('comment')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['verification_request_id', 'reviewer_id']); // one vote per reviewer (D06)
        });

        Schema::create('member_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('verification_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('verification_request_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('granted_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoke_reason')->nullable();
            $table->boolean('is_public')->default(true); // D08
            $table->timestamps();

            $table->index(['user_id', 'verification_type_id']);
        });

        // ---- M09 calendar ----------------------------------------------------------------
        Schema::create('event_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->string('color', 7)->default('#3366CC');            // M09-D10
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('allow_guest_booking')->default(false);     // M08-D01
            $table->boolean('allow_proxy_booking')->default(false);     // M08-D02
            $table->foreignId('required_verification_type_id')->nullable()
                ->constrained('verification_types')->nullOnDelete();    // M08-D10
            $table->timestamps();
        });

        Schema::create('event_series', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('frequency', 10);          // weekly | monthly
            $table->string('timezone', 64)->default('Asia/Taipei');
            $table->string('local_start_time', 5);    // HH:MM in the series time zone
            $table->unsignedInteger('duration_minutes');
            $table->date('starts_on');
            $table->date('until_on');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('calendar_events', function (Blueprint $table) {
            $table->foreignId('event_category_id')->nullable()->after('audience')->constrained()->nullOnDelete();
            $table->foreignId('event_series_id')->nullable()->after('event_category_id')->constrained('event_series')->restrictOnDelete();
            $table->timestamp('series_original_start_at')->nullable()->after('event_series_id');
            $table->boolean('is_series_exception')->default(false)->after('series_original_start_at');
            $table->string('timezone', 64)->default('Asia/Taipei')->after('ends_at');
            $table->string('floor')->nullable()->after('location');
            $table->string('studio')->nullable()->after('floor');
            // M08: seats one booking may take (D05), waitlist (D03), relative deadlines (L02 A).
            $table->unsignedSmallInteger('booking_max_seats')->default(1)->after('booking_closes_at');
            $table->boolean('waitlist_enabled')->default(true)->after('booking_max_seats');
            $table->unsignedInteger('booking_close_offset_minutes')->nullable()->after('waitlist_enabled');
            $table->timestamp('cancel_closes_at')->nullable()->after('booking_close_offset_minutes');
            $table->unsignedInteger('cancel_offset_minutes')->nullable()->after('cancel_closes_at');
            // M11 check-in settings per occurrence (D06, D15, D20, D26).
            $table->boolean('checkin_enabled')->default(false)->after('cancel_offset_minutes');
            $table->boolean('checkout_enabled')->default(false)->after('checkin_enabled');
            $table->unsignedInteger('checkin_open_before_minutes')->default(60)->after('checkout_enabled');
            $table->unsignedInteger('checkin_close_after_end_minutes')->default(0)->after('checkin_open_before_minutes');
            $table->boolean('checkin_gps_required')->nullable()->after('checkin_close_after_end_minutes'); // null = App default
            $table->unsignedInteger('checkin_gps_radius_m')->nullable()->after('checkin_gps_required');

            $table->unique(['event_series_id', 'series_original_start_at']);
        });

        Schema::create('private_calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->text('notes')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('timezone', 64)->default('Asia/Taipei');
            $table->string('recurrence', 10)->nullable(); // weekly | monthly (M09-D23)
            $table->date('recurrence_until')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'starts_at']);
        });

        Schema::create('google_calendar_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete(); // one Google account (D16)
            $table->string('google_calendar_id')->nullable();
            $table->text('refresh_token')->nullable();   // encrypted cast
            $table->text('access_token')->nullable();    // encrypted cast
            $table->timestamp('access_token_expires_at')->nullable();
            $table->string('status', 20)->default('active'); // active | disconnected | error
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamp('sync_from')->nullable();   // first sync: now and later only (D24)
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('google_calendar_event_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('google_calendar_connection_id')->constrained('google_calendar_connections', 'id', 'gcal_mappings_connection_foreign')->cascadeOnDelete();
            $table->string('source_type', 20);      // booking (calendar_events.id) | private
            $table->unsignedBigInteger('source_id');
            $table->string('google_event_id', 64);
            $table->string('status', 20)->default('pending'); // pending | synced | removed | failed
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('last_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['google_calendar_connection_id', 'source_type', 'source_id'], 'gcal_mappings_source_unique'); // D25
            $table->index('status');
        });

        // ---- M08 bookings: groups, attendees (seat ledger), waitlist -----------------------
        Schema::create('event_booking_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_event_id')->constrained()->restrictOnDelete();
            $table->foreignId('legacy_booking_id')->nullable()->unique()->constrained('event_bookings')->restrictOnDelete();
            $table->foreignId('booker_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_guest')->default(false);
            $table->string('contact_name', 80);
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->string('status', 20); // confirmed | waitlisted | cancelled | event_cancelled
            $table->unsignedSmallInteger('seat_count');
            $table->uuid('client_request_id')->nullable()->unique(); // retry idempotency
            $table->timestamp('booked_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();

            $table->index(['calendar_event_id', 'status']);
            $table->index(['booker_user_id', 'booked_at']);
            $table->index('contact_email');
            $table->index('contact_phone');
        });

        Schema::create('event_booking_attendees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_booking_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('calendar_event_id')->constrained()->restrictOnDelete();
            $table->foreignId('member_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 80);
            $table->string('status', 20); // confirmed | waitlisted | cancelled
            // 1 while confirmed or waitlisted, NULL once cancelled: one place per member and occurrence.
            $table->unsignedTinyInteger('active')->nullable()->default(1);
            $table->timestamps();

            $table->unique(['calendar_event_id', 'member_user_id', 'active'], 'booking_attendees_member_active_unique');
            $table->index(['calendar_event_id', 'status']);
        });

        Schema::create('event_waitlist_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_event_id')->constrained()->restrictOnDelete();
            $table->foreignId('event_booking_group_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('status', 20)->default('waiting'); // waiting | promoted | cancelled | skipped
            $table->string('skip_reason')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['calendar_event_id', 'sequence']);
            $table->index(['calendar_event_id', 'status', 'sequence']);
        });

        // ---- M11 check-in ------------------------------------------------------------------
        Schema::create('checkin_locations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('token', 40)->unique(); // printed in the fixed Location QR
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('checkin_location_events', function (Blueprint $table) {
            $table->foreignId('checkin_location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('calendar_event_id')->constrained()->cascadeOnDelete();
            $table->primary(['checkin_location_id', 'calendar_event_id'], 'checkin_location_events_primary');
        });

        Schema::create('event_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_event_id')->constrained()->restrictOnDelete();
            $table->foreignId('event_booking_attendee_id')->constrained()->restrictOnDelete();
            $table->string('status', 20); // checked_in | checked_out | revoked
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('checked_out_at')->nullable();
            $table->string('source', 20);  // event_qr | staff_scan | location_qr | admin
            $table->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['calendar_event_id', 'event_booking_attendee_id'], 'event_attendances_attendee_unique'); // D14 / G07
            $table->index(['calendar_event_id', 'status']);
            $table->index('updated_at');
        });

        // Admin corrections with their reasons (D12, D16, D27). Personal data of the
        // check-in, so it is deleted together with the attendance at retention (D28);
        // the append-only audit log only keeps a de-identified entry.
        Schema::create('event_attendance_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_attendance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->boolean('late')->default(false);
            $table->string('reason', 255)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        // ---- M16 tags --------------------------------------------------------------------
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->string('normalized_name', 50)->unique(); // D05
            $table->string('color', 7)->nullable();          // null = automatic colour (D21)
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('taggables', function (Blueprint $table) {
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete(); // deleting a tag never deletes content
            $table->string('taggable_type', 20); // article | event (allowlist)
            $table->unsignedBigInteger('taggable_id');
            $table->timestamp('created_at')->nullable();

            $table->primary(['tag_id', 'taggable_type', 'taggable_id']);
            $table->index(['taggable_type', 'taggable_id']);
        });

        // ---- M19 bookmarks (no FK to content: placeholders survive deletion) ------------------
        Schema::create('bookmarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // D22
            $table->string('content_type', 20);
            $table->unsignedBigInteger('content_id');
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'content_type', 'content_id']);
            $table->index(['user_id', 'created_at']);
        });

        // ---- M17 link clicks (no full IP stored) --------------------------------------------
        Schema::create('link_clicks', function (Blueprint $table) {
            $table->id();
            $table->uuid('click_id')->unique();
            $table->string('content_type', 20);
            $table->unsignedBigInteger('content_id');
            $table->string('channel', 10);            // web | app
            $table->string('source', 40);
            $table->string('medium', 40)->nullable();
            $table->string('campaign', 80)->nullable();
            $table->string('device_class', 10);       // mobile | desktop | tablet | unknown
            $table->char('dedupe_hash', 64);          // daily-rotating, per link
            $table->boolean('is_bot')->default(false);
            $table->boolean('is_abnormal')->default(false);
            $table->date('stat_date');
            $table->timestamp('clicked_at');
            $table->timestamp('opened_at')->nullable();

            $table->index('clicked_at');
            $table->index(['content_type', 'content_id', 'stat_date']);
            $table->index(['dedupe_hash', 'clicked_at']);
        });

        Schema::create('link_daily_stats', function (Blueprint $table) {
            $table->id();
            $table->date('stat_date');
            $table->string('content_type', 20);
            $table->unsignedBigInteger('content_id');
            $table->string('source', 40);
            $table->string('device_class', 10);
            $table->unsignedInteger('total_clicks')->default(0);
            $table->unsignedInteger('dedup_clicks')->default(0);
            $table->unsignedInteger('opens')->default(0);
            $table->unsignedInteger('bot_excluded')->default(0);
            $table->unsignedInteger('abnormal_excluded')->default(0);
            $table->timestamps();

            $table->unique(['stat_date', 'content_type', 'content_id', 'source', 'device_class'], 'link_daily_stats_key');
            $table->index(['content_type', 'content_id']);
        });

        // ---- M15 marketing ---------------------------------------------------------------
        Schema::create('marketing_placements', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 80);
            $table->unsignedTinyInteger('max_items')->default(3); // D13
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('marketing_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->text('summary')->nullable();
            $table->string('image_path')->nullable();   // existing local media disk
            $table->string('button_text', 30);
            $table->string('target_type', 20);          // article | event | notice | url
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('target_url', 2048)->nullable();
            $table->string('status', 20)->default('draft'); // draft | published | disabled
            $table->integer('priority')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('last_error')->nullable();   // D29 brief display error
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'starts_at', 'ends_at']);
        });

        Schema::create('marketing_campaign_placement', function (Blueprint $table) {
            $table->foreignId('marketing_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marketing_placement_id')->constrained()->cascadeOnDelete();
            $table->primary(['marketing_campaign_id', 'marketing_placement_id'], 'campaign_placement_primary');
        });

        DB::table('marketing_placements')->insert([
            ['code' => 'home_banner', 'name' => '首頁橫幅', 'max_items' => 3, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        LegacyBookingBackfill::run();
        $this->permissions();
    }

    public function down(): void
    {
        // Refuses while S9 data exists unless S9_ROLLBACK_ACCEPT_DATA_LOSS=yes (after a backup).
        S9DataGuard::assertRollbackAllowed();

        foreach ([
            'marketing_campaign_placement', 'marketing_campaigns', 'marketing_placements',
            'link_daily_stats', 'link_clicks', 'bookmarks', 'taggables', 'tags',
            'event_attendance_corrections', 'event_attendances', 'checkin_location_events', 'checkin_locations',
            'event_waitlist_entries', 'event_booking_attendees', 'event_booking_groups',
            'google_calendar_event_mappings', 'google_calendar_connections', 'private_calendar_events',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropForeign(['event_series_id']);
            $table->dropForeign(['event_category_id']);
            $table->dropUnique(['event_series_id', 'series_original_start_at']);
            $table->dropColumn([
                'event_category_id', 'event_series_id', 'series_original_start_at', 'is_series_exception', 'timezone', 'floor', 'studio',
                'booking_max_seats', 'waitlist_enabled', 'booking_close_offset_minutes', 'cancel_closes_at',
                'cancel_offset_minutes', 'checkin_enabled', 'checkout_enabled', 'checkin_open_before_minutes',
                'checkin_close_after_end_minutes', 'checkin_gps_required', 'checkin_gps_radius_m',
            ]);
        });

        foreach (['event_series', 'event_categories', 'member_verifications', 'verification_reviews',
            'verification_evidence', 'verification_requests', 'verification_types'] as $table) {
            Schema::dropIfExists($table);
        }

        // Permissions and roles are left in place (harmless without the screens).
    }

    private function permissions(): void
    {
        $admin = Role::findOrCreate('admin', 'web');
        foreach (['verifications.review', 'verifications.manage', 'checkin.manage', 'tags.manage', 'marketing.manage', 'roles.manage'] as $name) {
            Permission::findOrCreate($name, 'web');
            $admin->givePermissionTo($name);
        }

        // Granted separately, never automatically to every admin (M17-D23, M11 staff).
        Permission::findOrCreate('analytics.view', 'web');
        Permission::findOrCreate('checkin.staff', 'web');
        Role::findOrCreate('analyst', 'web')->givePermissionTo('analytics.view');
        Role::findOrCreate('checkin_staff', 'web')->givePermissionTo('checkin.staff');
        Role::findOrCreate('verification_reviewer', 'web')->givePermissionTo('verifications.review');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
