<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reporters', function (Blueprint $table) {
            $table->boolean('is_law_enforcement')->default(false)->after('is_blocked');
            $table->index('is_law_enforcement');
        });
    }

    public function down(): void
    {
        Schema::table('reporters', function (Blueprint $table) {
            $table->dropIndex(['is_law_enforcement']);
            $table->dropColumn('is_law_enforcement');
        });
    }
};
