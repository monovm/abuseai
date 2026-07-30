<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->text('smtp_config')->nullable()->change();
            $table->text('whmcs_config')->nullable()->change();
            $table->text('virtualizor_config')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->jsonb('smtp_config')->nullable()->change();
            $table->jsonb('whmcs_config')->nullable()->change();
            $table->jsonb('virtualizor_config')->nullable()->change();
        });
    }
};
