<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Brand-level toggle for which infrastructure provider runs IP → service
 * lookups (and operator actions like Suspend) for that brand. Backfill:
 *  - Brands with both Virtualizor + WHMCS configured → whmcs_virtualizor
 *  - Brands with only WHMCS                          → whmcs
 *  - Brands with only Virtualizor                    → virtualizor
 *  - Brands with neither                             → none
 *
 * Reads the encrypted *_config columns directly is awkward in a
 * migration, so we treat presence of a non-null encrypted blob as
 * configured for backfill purposes — admins can correct via the brand
 * editor afterwards if they had stale connection data sitting around.
 *
 * Also adds a `generic_provider_config` JSON column for the
 * GenericHttpProvider's URL/header/body/mapping spec.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            // Default kept as 'none' so brands seeded by tests don't
            // accidentally pick up live provider lookups.
            $table->string('provider_type', 32)->default('none')->after('hostname_pattern');
            $table->jsonb('generic_provider_config')->nullable()->after('provider_type');
            $table->index('provider_type');
        });

        // Backfill from existing config presence.
        DB::table('brands')->orderBy('id')->each(function ($brand) {
            $hasWhmcs = ! empty($brand->whmcs_config);
            $hasVz = ! empty($brand->virtualizor_config);

            $type = match (true) {
                $hasWhmcs && $hasVz => 'whmcs_virtualizor',
                $hasWhmcs => 'whmcs',
                $hasVz => 'virtualizor',
                default => 'none',
            };

            DB::table('brands')->where('id', $brand->id)->update(['provider_type' => $type]);
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropIndex(['provider_type']);
            $table->dropColumn(['provider_type', 'generic_provider_config']);
        });
    }
};
