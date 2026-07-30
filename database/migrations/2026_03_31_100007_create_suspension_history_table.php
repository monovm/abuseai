<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suspension_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('customer_id');
            $table->uuid('case_id');
            $table->foreignId('suspended_by')->nullable();
            $table->text('suspension_reason');
            $table->foreignId('unsuspended_by')->nullable();
            $table->text('unsuspension_reason')->nullable();
            $table->timestamp('suspended_at');
            $table->timestamp('unsuspended_at')->nullable();
            $table->jsonb('whmcs_response')->nullable();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('case_id')->references('id')->on('abuse_cases')->cascadeOnDelete();
            $table->foreign('suspended_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('unsuspended_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suspension_history');
    }
};
