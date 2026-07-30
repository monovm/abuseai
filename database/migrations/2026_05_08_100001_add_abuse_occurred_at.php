<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('abuse_reports', function (Blueprint $table) {
            // When the abuse activity itself occurred, as extracted from
            // email body / attachment text. Distinct from reported_at,
            // which is when the report landed at our desk.
            $table->timestamp('abuse_occurred_at')->nullable()->after('reported_at');
            $table->index('abuse_occurred_at');
        });

        Schema::table('abuse_cases', function (Blueprint $table) {
            // The earliest abuse_occurred_at across every report linked
            // to this case — the answer to "when did this attack start?"
            $table->timestamp('abuse_occurred_at')->nullable()->after('last_seen_at');
            $table->index('abuse_occurred_at');
        });
    }

    public function down(): void
    {
        Schema::table('abuse_reports', function (Blueprint $table) {
            $table->dropIndex(['abuse_occurred_at']);
            $table->dropColumn('abuse_occurred_at');
        });
        Schema::table('abuse_cases', function (Blueprint $table) {
            $table->dropIndex(['abuse_occurred_at']);
            $table->dropColumn('abuse_occurred_at');
        });
    }
};
