<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->jsonb('whmcs_config')->nullable()->after('smtp_config');
            $table->jsonb('virtualizor_config')->nullable()->after('whmcs_config');
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn(['whmcs_config', 'virtualizor_config']);
        });
    }
};
