<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inbox_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type'); // case_followup, report_followup
            $table->uuid('case_id')->nullable();
            $table->uuid('report_id')->nullable();
            $table->string('title');
            $table->text('excerpt')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->foreign('case_id')->references('id')->on('abuse_cases')->cascadeOnDelete();
            $table->foreign('report_id')->references('id')->on('abuse_reports')->cascadeOnDelete();
            $table->index(['read_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_notifications');
    }
};
