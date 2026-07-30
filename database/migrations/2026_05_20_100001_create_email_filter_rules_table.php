<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('email_filter_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('brand_id')->nullable();
            $table->string('pattern');
            $table->string('match_field')->default('any'); // subject, body, any
            $table->boolean('is_active')->default(true);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->foreign('brand_id')->references('id')->on('brands')->cascadeOnDelete();
            $table->index(['is_active', 'brand_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_filter_rules');
    }
};
