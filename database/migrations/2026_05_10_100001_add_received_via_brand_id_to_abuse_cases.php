<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('abuse_cases', function (Blueprint $table) {
            // Brand the report was *received via* (recipient inbox / from-address
            // match). Used as the default "reply from" identity when answering
            // the reporter, while brand_id stays the infra-detected brand used
            // for case actions (suspend, customer notice, etc.).
            $table->foreignUuid('received_via_brand_id')->nullable()->after('brand_id')
                ->constrained('brands')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('abuse_cases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('received_via_brand_id');
        });
    }
};
