<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ip_addresses', function (Blueprint $table) {
            $table->decimal('reputation_score', 5, 2)->default(100)->after('abuseipdb_checked_at'); // 100=clean, 0=worst
            $table->jsonb('reputation_details')->nullable()->after('reputation_score');
            $table->timestamp('reputation_updated_at')->nullable()->after('reputation_details');
            $table->boolean('case_created_from_reputation')->default(false)->after('reputation_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('ip_addresses', function (Blueprint $table) {
            $table->dropColumn(['reputation_score', 'reputation_details', 'reputation_updated_at', 'case_created_from_reputation']);
        });
    }
};
