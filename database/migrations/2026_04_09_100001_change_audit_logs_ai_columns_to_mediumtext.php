<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->mediumText('ai_prompt')->nullable()->change();
            $table->mediumText('ai_response')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->text('ai_prompt')->nullable()->change();
            $table->text('ai_response')->nullable()->change();
        });
    }
};
