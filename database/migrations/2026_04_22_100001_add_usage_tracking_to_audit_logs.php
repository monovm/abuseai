<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('provider', 32)->nullable()->after('event');
            $table->string('model', 128)->nullable()->after('provider');
            $table->unsignedInteger('input_tokens')->nullable()->after('model');
            $table->unsignedInteger('output_tokens')->nullable()->after('input_tokens');
            $table->decimal('cost_usd', 12, 6)->nullable()->after('output_tokens');

            $table->index(['event', 'created_at'], 'audit_logs_event_created_idx');
            $table->index(['provider', 'model'], 'audit_logs_provider_model_idx');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_event_created_idx');
            $table->dropIndex('audit_logs_provider_model_idx');
            $table->dropColumn(['provider', 'model', 'input_tokens', 'output_tokens', 'cost_usd']);
        });
    }
};
