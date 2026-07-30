<?php

namespace App\Services\AI;

use App\Models\AbuseCase;
use App\Models\Customer;

class IntelligenceAiService
{
    public function __construct(
        protected ClaudeService $claude,
    ) {}

    public function rescoreCase(AbuseCase $case): ?array
    {
        $context = $this->buildCaseContext($case);

        return $this->claude->completeJson(
            config('ai.prompts.intelligence.rescorer'),
            $context,
        );
    }

    public function detectPatterns(array $cases): ?array
    {
        $summaries = collect($cases)->map(fn ($c) => [
            'id' => $c->id,
            'case_number' => $c->case_number,
            'type' => $c->abuse_type->value,
            'target_ip' => $c->target_ip,
            'target_domain' => $c->target_domain,
            'severity_score' => $c->severity_score,
            'report_count' => $c->report_count,
            'first_seen' => $c->first_seen_at?->toIso8601String(),
        ])->toJson();

        return $this->claude->completeJson(
            config('ai.prompts.intelligence.pattern_detector'),
            $summaries,
        );
    }

    public function analyseEvidence(string $evidence): ?string
    {
        return $this->claude->complete(
            config('ai.prompts.intelligence.evidence_analyser'),
            $evidence,
        );
    }

    public function profileCustomer(Customer $customer): ?array
    {
        $profile = json_encode([
            'email' => $customer->email,
            'company' => $customer->company,
            'abuse_score' => $customer->abuse_score,
            'is_suspended' => $customer->is_suspended,
            'total_cases' => $customer->cases()->count(),
            'open_cases' => $customer->cases()->where('status', 'open')->count(),
            'recent_cases' => $customer->cases()
                ->latest()
                ->take(5)
                ->get(['case_number', 'abuse_type', 'severity_score', 'status', 'created_at'])
                ->toArray(),
        ]);

        return $this->claude->completeJson(
            config('ai.prompts.intelligence.customer_profiler'),
            $profile,
        );
    }

    protected function buildCaseContext(AbuseCase $case): string
    {
        $reports = $case->reports()
            ->take(10)
            ->get(['abuse_type', 'source', 'target_ip', 'target_domain', 'evidence', 'ai_classification', 'reported_at', 'attachment_paths']);

        // Pull text out of every report's attachments so the rescorer sees
        // PDFs / forwarded .eml content, not just the description.
        $attachmentPaths = $reports
            ->flatMap(fn ($r) => $r->attachment_paths ?? [])
            ->all();
        $attachmentsText = ! empty($attachmentPaths)
            ? \App\Support\Attachments\AttachmentTextExtractor::fromPaths($attachmentPaths)
            : '';

        // Drop the raw paths from the reports payload — the AI doesn't need them.
        $reportsPayload = $reports->map(function ($r) {
            $arr = $r->toArray();
            unset($arr['attachment_paths']);
            return $arr;
        })->toArray();

        return json_encode([
            'case_number' => $case->case_number,
            'type' => $case->abuse_type->value,
            'current_score' => $case->severity_score,
            'report_count' => $case->report_count,
            'target_ip' => $case->target_ip,
            'target_domain' => $case->target_domain,
            'first_seen' => $case->first_seen_at?->toIso8601String(),
            'last_seen' => $case->last_seen_at?->toIso8601String(),
            'reports' => $reportsPayload,
            'attachments_text' => $attachmentsText !== '' ? $attachmentsText : null,
        ]);
    }
}
