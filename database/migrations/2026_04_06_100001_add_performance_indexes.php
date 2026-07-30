<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add performance indexes to abuse_reports, case_actions, reporters,
     * and audit_logs tables for common query patterns.
     */
    public function up(): void
    {
        // abuse_reports indexes
        Schema::table('abuse_reports', function (Blueprint $table) {
            $table->index(['case_id', 'created_at'], 'abuse_reports_case_id_created_at_index');
            $table->index(['target_ip', 'abuse_type'], 'abuse_reports_target_ip_abuse_type_index');
            $table->index(['reporter_id', 'created_at'], 'abuse_reports_reporter_id_created_at_index');
            $table->index('ai_noise_score', 'abuse_reports_ai_noise_score_index');
            $table->index('source', 'abuse_reports_source_index');
            $table->index('is_duplicate', 'abuse_reports_is_duplicate_index');
            $table->index('created_at', 'abuse_reports_created_at_index');
        });

        // case_actions indexes
        Schema::table('case_actions', function (Blueprint $table) {
            $table->index(['case_id', 'created_at'], 'case_actions_case_id_created_at_index');
            $table->index('action_type', 'case_actions_action_type_index');
            $table->index(['case_id', 'action_type'], 'case_actions_case_id_action_type_index');
        });

        // reporters indexes
        Schema::table('reporters', function (Blueprint $table) {
            $table->index('is_blocked', 'reporters_is_blocked_index');
            $table->index('type', 'reporters_type_index');
            $table->index('report_count', 'reporters_report_count_index');
        });

        // audit_logs indexes
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index('event', 'audit_logs_event_index');
            $table->index('created_at', 'audit_logs_created_at_index');
            $table->index(['actor_id', 'created_at'], 'audit_logs_actor_id_created_at_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('abuse_reports', function (Blueprint $table) {
            $table->dropIndex('abuse_reports_case_id_created_at_index');
            $table->dropIndex('abuse_reports_target_ip_abuse_type_index');
            $table->dropIndex('abuse_reports_reporter_id_created_at_index');
            $table->dropIndex('abuse_reports_ai_noise_score_index');
            $table->dropIndex('abuse_reports_source_index');
            $table->dropIndex('abuse_reports_is_duplicate_index');
            $table->dropIndex('abuse_reports_created_at_index');
        });

        Schema::table('case_actions', function (Blueprint $table) {
            $table->dropIndex('case_actions_case_id_created_at_index');
            $table->dropIndex('case_actions_action_type_index');
            $table->dropIndex('case_actions_case_id_action_type_index');
        });

        Schema::table('reporters', function (Blueprint $table) {
            $table->dropIndex('reporters_is_blocked_index');
            $table->dropIndex('reporters_type_index');
            $table->dropIndex('reporters_report_count_index');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_event_index');
            $table->dropIndex('audit_logs_created_at_index');
            $table->dropIndex('audit_logs_actor_id_created_at_index');
        });
    }
};
