<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ip_addresses', function (Blueprint $table) {
            // Composite index covering the exact query: WHERE status = 'active' ORDER BY ip_address
            $table->index(['status', 'ip_address'], 'ip_addresses_status_ip_index');
        });
    }

    public function down(): void
    {
        Schema::table('ip_addresses', function (Blueprint $table) {
            $table->dropIndex('ip_addresses_status_ip_index');
        });
    }
};
