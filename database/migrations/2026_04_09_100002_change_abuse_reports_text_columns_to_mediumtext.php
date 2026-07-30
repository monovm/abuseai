<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('abuse_reports', function (Blueprint $table) {
            $table->mediumText('raw_payload')->change();
            $table->mediumText('evidence')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('abuse_reports', function (Blueprint $table) {
            $table->text('raw_payload')->change();
            $table->text('evidence')->nullable()->change();
        });
    }
};
