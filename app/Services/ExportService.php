<?php

namespace App\Services;

use App\Models\AbuseCase;
use App\Models\AbuseReport;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportService
{
    public function exportCases(?array $filters = []): StreamedResponse
    {
        $query = AbuseCase::with(['customer', 'brand']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['abuse_type'])) {
            $query->where('abuse_type', $filters['abuse_type']);
        }
        if (! empty($filters['severity'])) {
            $query->where('severity_level', $filters['severity']);
        }
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }

        return $this->streamCsv('cases', $query, [
            'Case Number', 'Status', 'Abuse Type', 'Severity', 'Score',
            'Target IP', 'Target Domain', 'Report Count',
            'First Seen', 'Last Seen', 'Actioned At', 'Resolved At', 'Closed At',
            'Customer', 'Brand', 'AI Summary', 'Created At',
        ], function ($case) {
            return [
                $case->case_number,
                $case->status->value ?? $case->status,
                is_string($case->abuse_type) ? $case->abuse_type : $case->abuse_type->value,
                is_string($case->severity_level) ? $case->severity_level : $case->severity_level->value,
                $case->severity_score,
                $case->target_ip,
                $case->target_domain,
                $case->report_count,
                $case->first_seen_at?->toIso8601String(),
                $case->last_seen_at?->toIso8601String(),
                $case->actioned_at?->toIso8601String(),
                $case->resolved_at?->toIso8601String(),
                $case->closed_at?->toIso8601String(),
                $case->customer?->email ?? $case->customer?->company,
                $case->brand?->name,
                $case->ai_summary,
                $case->created_at->toIso8601String(),
            ];
        });
    }

    public function exportReports(?array $filters = []): StreamedResponse
    {
        $query = AbuseReport::with(['reporter', 'case']);

        if (! empty($filters['source'])) {
            $query->where('source', $filters['source']);
        }
        if (! empty($filters['abuse_type'])) {
            $query->where('abuse_type', $filters['abuse_type']);
        }
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }

        return $this->streamCsv('reports', $query, [
            'ID', 'Case Number', 'Source', 'Abuse Type', 'Target IP',
            'Target Domain', 'Target URL', 'Reporter', 'Reporter Email',
            'Noise Score', 'AI Type', 'AI Confidence', 'Is Duplicate',
            'Reported At', 'Created At',
        ], function ($report) {
            return [
                $report->id,
                $report->case?->case_number,
                $report->source,
                is_string($report->abuse_type) ? $report->abuse_type : $report->abuse_type->value,
                $report->target_ip,
                $report->target_domain,
                $report->target_url,
                $report->reporter?->name,
                $report->reporter?->email,
                $report->ai_noise_score,
                $report->ai_classification['type'] ?? null,
                $report->ai_classification['confidence'] ?? null,
                $report->is_duplicate ? 'Yes' : 'No',
                $report->reported_at?->toIso8601String(),
                $report->created_at->toIso8601String(),
            ];
        });
    }

    protected function streamCsv(string $name, Builder $query, array $headers, callable $rowMapper): StreamedResponse
    {
        $filename = "abusedesk-{$name}-" . date('Y-m-d-His') . '.csv';

        return response()->streamDownload(function () use ($query, $headers, $rowMapper) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headers);

            $query->orderBy('created_at', 'desc')->chunk(500, function ($records) use ($handle, $rowMapper) {
                foreach ($records as $record) {
                    fputcsv($handle, $rowMapper($record));
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }
}
