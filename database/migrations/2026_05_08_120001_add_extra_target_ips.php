<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('abuse_reports', function (Blueprint $table) {
            // Additional target IPs the AI extracted from the report
            // beyond the primary target_ip. These are listed alongside
            // the primary on the report/case detail UI but do NOT spawn
            // separate cases — keeps "one report, one case" intact.
            $table->jsonb('extra_target_ips')->nullable()->after('target_ip');
        });

        Schema::table('abuse_cases', function (Blueprint $table) {
            $table->jsonb('extra_target_ips')->nullable()->after('target_ip');
        });
    }

    public function down(): void
    {
        Schema::table('abuse_reports', function (Blueprint $table) {
            $table->dropColumn('extra_target_ips');
        });
        Schema::table('abuse_cases', function (Blueprint $table) {
            $table->dropColumn('extra_target_ips');
        });
    }
};
