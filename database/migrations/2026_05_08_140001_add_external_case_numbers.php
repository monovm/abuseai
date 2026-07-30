<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reporter-side case / ticket / reference numbers extracted from
        // the report content (police case numbers, CERT tickets, FBL
        // reference IDs, DMCA notice IDs, etc.). Stored as an array of
        // {label, value} objects so a single report can carry several:
        //   [{"label": "Police Case", "value": "12-3456/2025"},
        //    {"label": "CERT Ticket", "value": "CERT-2025-789"}]
        //
        // Searchable from the case + report queues so agents can
        // jump straight from a follow-up email mentioning the police
        // case number to the existing case.
        Schema::table('abuse_reports', function (Blueprint $table) {
            $table->jsonb('external_case_numbers')->nullable()->after('extra_target_ips');
        });

        Schema::table('abuse_cases', function (Blueprint $table) {
            $table->jsonb('external_case_numbers')->nullable()->after('extra_target_ips');
        });
    }

    public function down(): void
    {
        Schema::table('abuse_reports', function (Blueprint $table) {
            $table->dropColumn('external_case_numbers');
        });
        Schema::table('abuse_cases', function (Blueprint $table) {
            $table->dropColumn('external_case_numbers');
        });
    }
};
