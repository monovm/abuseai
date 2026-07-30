<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->string('trigger_event'); // report_received, score_changed, etc.
            $table->jsonb('conditions')->nullable();
            $table->jsonb('actions')->nullable();
            $table->integer('priority')->default(0);
            $table->jsonb('abuse_types')->nullable(); // null = all types
            $table->decimal('min_score', 5, 2)->nullable();
            $table->timestamp('last_triggered_at')->nullable();
            $table->integer('trigger_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_rules');
    }
};
