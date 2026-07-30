<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('abuse_cases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('case_number')->unique();
            $table->string('status')->default('open');
            $table->string('abuse_type');
            $table->decimal('severity_score', 5, 2)->default(0);
            $table->string('severity_level')->default('low');
            $table->ipAddress('target_ip')->nullable();
            $table->string('target_domain')->nullable();
            $table->string('target_service_id')->nullable();
            $table->uuid('customer_id')->nullable();
            $table->foreignId('assignee_id')->nullable();
            $table->integer('report_count')->default(1);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('actioned_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('ai_summary')->nullable();
            $table->jsonb('ai_pattern_flags')->nullable();
            $table->timestamp('sla_deadline')->nullable();
            $table->boolean('is_legal_hold')->default(false);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            $table->foreign('assignee_id')->references('id')->on('users')->nullOnDelete(); // foreignId = unsignedBigInt matching users.id

            $table->index('status');
            $table->index('abuse_type');
            $table->index('severity_score');
            $table->index('target_ip');
            $table->index('customer_id');
            $table->index('assignee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abuse_cases');
    }
};
