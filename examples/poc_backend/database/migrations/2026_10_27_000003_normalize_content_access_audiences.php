<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('app_content_access_rules')) {
            return;
        }

        DB::transaction(function (): void {
            foreach (['member' => 'register_member', 'verified' => 'verified_member'] as $old => $new) {
                $rows = DB::table('app_content_access_rules')->where('audience', $old)->get();
                foreach ($rows as $row) {
                    $existing = DB::table('app_content_access_rules')
                        ->where('scope', $row->scope)
                        ->where('resource_code', $row->resource_code)
                        ->where('audience', $new)->first();
                    if ($existing) {
                        // Explicit denial wins when old and new names coexist.
                        DB::table('app_content_access_rules')->where('id', $existing->id)
                            ->update(['allowed' => (bool) $existing->allowed && (bool) $row->allowed, 'updated_at' => now()]);
                        DB::table('app_content_access_rules')->where('id', $row->id)->delete();
                    } else {
                        DB::table('app_content_access_rules')->where('id', $row->id)->update(['audience' => $new]);
                    }
                }
            }

            // The obsolete 'all' selector must not silently grant guest access.
            DB::table('app_content_access_rules')->where('audience', 'all')->delete();
        });
    }

    public function down(): void
    {
        // Membership authorization labels are not safely reversible.
    }
};
