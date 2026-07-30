<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel'); // 'email', 'slack', 'browser'
            $table->string('event'); // 'case_opened', 'case_critical', 'case_actioned', 'ai_flagged', 'job_failed'
            $table->boolean('enabled')->default(true);
            $table->jsonb('config')->nullable(); // channel-specific config (e.g. slack webhook URL)
            $table->timestamps();

            $table->unique(['user_id', 'channel', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
