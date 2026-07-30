<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add the column nullable so we can backfill existing rows safely.
        Schema::table('ip_addresses', function (Blueprint $table) {
            $table->unsignedTinyInteger('prefix_length')->nullable()->after('ip_address');
        });

        // 2. Backfill: every existing row represents a single host.
        //    IPv4 hosts => /32, IPv6 hosts => /128.
        DB::table('ip_addresses')
            ->whereNull('prefix_length')
            ->where('ip_address', 'like', '%:%')
            ->update(['prefix_length' => 128]);

        DB::table('ip_addresses')
            ->whereNull('prefix_length')
            ->update(['prefix_length' => 32]);

        // 3. Swap the unique constraint — single IP string is no longer unique on
        //    its own, because you can own both the network address and the block
        //    that contains it (e.g. 10.0.0.0 host and 10.0.0.0/24 block).
        Schema::table('ip_addresses', function (Blueprint $table) {
            $table->unsignedTinyInteger('prefix_length')->nullable(false)->change();
            $table->dropUnique(['ip_address']);
            $table->unique(['ip_address', 'prefix_length']);
            $table->index('prefix_length');
        });
    }

    public function down(): void
    {
        Schema::table('ip_addresses', function (Blueprint $table) {
            $table->dropIndex(['prefix_length']);
            $table->dropUnique(['ip_address', 'prefix_length']);
            $table->unique('ip_address');
            $table->dropColumn('prefix_length');
        });
    }
};
