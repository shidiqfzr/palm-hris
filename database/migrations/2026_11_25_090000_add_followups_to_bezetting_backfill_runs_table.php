<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Follow-up worklist of a saved Bezetting rebuild (DOMAIN.md §5.5 rule 9, SECURITY.md §4.4): movements not recorded in
 * the application, month gaps, and people who left the list, captured at approval because they cannot be rebuilt once
 * the history is written. NIK and month only, encrypted, cleared after hris.bezetting.followup_retention_days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bezetting_backfill_runs', function (Blueprint $table) {
            $table->longText('followups')->nullable()->after('payload_purged_at');
            $table->timestamp('followups_purged_at')->nullable()->after('followups');
        });
    }

    public function down(): void
    {
        Schema::table('bezetting_backfill_runs', function (Blueprint $table) {
            $table->dropColumn(['followups', 'followups_purged_at']);
        });
    }
};
