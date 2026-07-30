<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('case_id');
            $table->foreignId('actor_id')->nullable(); // null = automated
            $table->string('action_type');
            $table->jsonb('payload')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at');

            $table->foreign('case_id')->references('id')->on('abuse_cases')->cascadeOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_actions');
    }
};
