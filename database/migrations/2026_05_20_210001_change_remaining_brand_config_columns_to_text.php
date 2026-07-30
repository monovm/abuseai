<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brand columns cast as `encrypted:array` on the model store a base64
 * ciphertext blob — not JSON. The first three columns (smtp_config,
 * whmcs_config, virtualizor_config) were converted from jsonb to text in
 * 2026_04_02_400002_change_brand_config_columns_to_text. The newer
 * provider-config columns added since then (solusvm, proxmox, blesta,
 * hostbill, generic_http) kept the jsonb type, so any save trips the
 * database's JSON validation. This migration brings them in line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->text('solusvm_config')->nullable()->change();
            $table->text('proxmox_config')->nullable()->change();
            $table->text('blesta_config')->nullable()->change();
            $table->text('hostbill_config')->nullable()->change();
            $table->text('generic_provider_config')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->jsonb('solusvm_config')->nullable()->change();
            $table->jsonb('proxmox_config')->nullable()->change();
            $table->jsonb('blesta_config')->nullable()->change();
            $table->jsonb('hostbill_config')->nullable()->change();
            $table->jsonb('generic_provider_config')->nullable()->change();
        });
    }
};
