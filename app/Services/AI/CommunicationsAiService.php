<?php

namespace App\Services\AI;

use App\Models\AbuseCase;

class CommunicationsAiService
{
    public function __construct(
        protected ClaudeService $claude,
    ) {}

    public function draftReporterReply(AbuseCase $case, string $replyType = 'ack'): ?string
    {
        $context = json_encode([
            'case_number' => $case->case_number,
            'type' => $case->abuse_type->value,
            'status' => $case->status->value,
            'reply_type' => $replyType,
            'summary' => $case->ai_summary,
        ]);

        return $this->claude->complete(
            config('ai.prompts.communications.reporter_reply'),
            $context,
        );
    }

    public function draftCustomerNotice(AbuseCase $case, string $actionTaken): ?string
    {
        $context = json_encode([
            'case_number' => $case->case_number,
            'type' => $case->abuse_type->value,
            'severity' => $case->severity_level->value,
            'target_ip' => $case->target_ip,
            'target_domain' => $case->target_domain,
            'action_taken' => $actionTaken,
            'report_count' => $case->report_count,
        ]);

        return $this->claude->complete(
            config('ai.prompts.communications.customer_notice'),
            $context,
        );
    }

    public function handleAppeal(AbuseCase $case, string $appealText): ?array
    {
        $context = json_encode([
            'case_number' => $case->case_number,
            'type' => $case->abuse_type->value,
            'severity_score' => $case->severity_score,
            'ai_summary' => $case->ai_summary,
            'appeal_text' => $appealText,
        ]);

        return $this->claude->completeJson(
            config('ai.prompts.communications.appeal_handler'),
            $context,
        );
    }

    public function summariseCase(AbuseCase $case): ?string
    {
        $actions = $case->actions()
            ->latest()
            ->take(10)
            ->get(['action_type', 'note', 'created_at']);

        $context = json_encode([
            'case_number' => $case->case_number,
            'type' => $case->abuse_type->value,
            'status' => $case->status->value,
            'severity_score' => $case->severity_score,
            'report_count' => $case->report_count,
            'target_ip' => $case->target_ip,
            'target_domain' => $case->target_domain,
            'first_seen' => $case->first_seen_at?->toIso8601String(),
            'last_seen' => $case->last_seen_at?->toIso8601String(),
            'actions' => $actions->toArray(),
        ]);

        return $this->claude->complete(
            config('ai.prompts.communications.case_summariser'),
            $context,
        );
    }
}
