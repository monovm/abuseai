<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ip_addresses', function (Blueprint $table) {
            $table->integer('abuseipdb_score')->nullable()->after('status');       // 0-100 confidence score
            $table->integer('abuseipdb_reports')->nullable()->after('abuseipdb_score'); // total reports count
            $table->jsonb('abuseipdb_data')->nullable()->after('abuseipdb_reports');    // full check response
            $table->timestamp('abuseipdb_checked_at')->nullable()->after('abuseipdb_data');
        });
    }

    public function down(): void
    {
        Schema::table('ip_addresses', function (Blueprint $table) {
            $table->dropColumn(['abuseipdb_score', 'abuseipdb_reports', 'abuseipdb_data', 'abuseipdb_checked_at']);
        });
    }
};
