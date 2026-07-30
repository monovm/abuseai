<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('abuse_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('case_id')->nullable();
            $table->uuid('reporter_id');
            $table->text('raw_payload');
            $table->string('source'); // feed, api, form, email, manual
            $table->string('abuse_type');
            $table->ipAddress('target_ip')->nullable();
            $table->string('target_domain')->nullable();
            $table->text('target_url')->nullable();
            $table->text('evidence')->nullable();
            $table->jsonb('headers')->nullable();
            $table->timestamp('reported_at');
            $table->ipAddress('reporter_ip')->nullable();
            $table->boolean('is_duplicate')->default(false);
            $table->uuid('duplicate_of')->nullable();
            $table->jsonb('ai_classification')->nullable();
            $table->decimal('ai_noise_score', 3, 2)->nullable();
            $table->jsonb('enrichment')->nullable();
            $table->jsonb('attachment_paths')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->foreign('case_id')->references('id')->on('abuse_cases')->nullOnDelete();
            $table->foreign('reporter_id')->references('id')->on('reporters');
        });

        // Self-referencing FK added separately: on Postgres the FK must be
        // created after the table's primary key constraint is committed.
        Schema::table('abuse_reports', function (Blueprint $table) {
            $table->foreign('duplicate_of')->references('id')->on('abuse_reports')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abuse_reports');
    }
};
