<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add per-brand encrypted credential blobs for the new providers
 * (SolusVM, Proxmox VE, Blesta, HostBill). Each brand will populate
 * exactly one of these depending on its provider_type — the others
 * stay null. Keeping them as separate columns (rather than one
 * polymorphic blob) makes the brand admin form trivial to gate by
 * provider_type without parsing JSON to figure out which slot to read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->jsonb('solusvm_config')->nullable()->after('virtualizor_config');
            $table->jsonb('proxmox_config')->nullable()->after('solusvm_config');
            $table->jsonb('blesta_config')->nullable()->after('proxmox_config');
            $table->jsonb('hostbill_config')->nullable()->after('blesta_config');
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn(['solusvm_config', 'proxmox_config', 'blesta_config', 'hostbill_config']);
        });
    }
};
