<?php

namespace App\Http\Controllers\Api;

use App\Events\ReportReceived;
use App\Http\Controllers\Controller;
use App\Http\Requests\ApiReportRequest;
use App\Jobs\Ingestion\ProcessWebhookReport;
use App\Services\Ingestion\Parsers\ArfParser;
use App\Services\Ingestion\ReportNormalizerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(
        protected ReportNormalizerService $normalizer,
    ) {}

    /**
     * Accept a single abuse report.
     * Supports: JSON body or ARF (RFC 5965) via Content-Type.
     */
    public function store(Request $request): JsonResponse
    {
        $contentType = $request->header('Content-Type', '');

        // ARF content — parse with ARF parser
        if (str_contains($contentType, 'report') ||
            str_contains($contentType, 'feedback-report') ||
            str_contains($contentType, 'message/rfc822')) {
            return $this->storeArf($request);
        }

        // Standard JSON
        return $this->storeJson(app(ApiReportRequest::class));
    }

    /**
     * Standard JSON report.
     */
    protected function storeJson(ApiReportRequest $request): JsonResponse
    {
        $reporter = $request->get('reporter');

        $report = $this->normalizer->fromApi(
            $request->validated(),
            $reporter,
            $request->ip(),
        );

        ReportReceived::dispatch($report);

        $report->refresh();
        $caseNumber = $report->case?->case_number;

        return response()->json([
            'id' => $report->id,
            'case_number' => $caseNumber,
            'message' => $caseNumber ? "Report received. Case: {$caseNumber}" : 'Report received',
        ], 201);
    }

    /**
     * ARF (RFC 5965) formatted report.
     */
    protected function storeArf(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();

        if (empty($rawBody)) {
            return response()->json(['error' => 'Empty body'], 400);
        }

        $reporter = $request->get('reporter');

        $parser = app(ArfParser::class);
        $parsed = $parser->parse($rawBody);

        if (! $parsed) {
            return response()->json(['error' => 'Failed to parse ARF report'], 422);
        }

        $report = $this->normalizer->fromApi([
            'abuse_type' => $parsed['abuse_type'],
            'target_ip' => $parsed['source_ip'],
            'target_domain' => $parsed['reported_domain'],
            'description' => $parsed['human_readable'] ?? $rawBody,
            'evidence' => $parsed['human_readable'] ?? $rawBody,
            'headers' => $parsed['headers'],
        ], $reporter, $request->ip());

        // Store extracted IOCs
        $report->update([
            'enrichment' => [
                'iocs' => [
                    'ips' => $parsed['extracted_ips'],
                    'domains' => $parsed['extracted_domains'],
                    'urls' => $parsed['extracted_urls'],
                ],
                'arf_format' => $parsed['format'],
                'feedback_report' => $parsed['feedback_report'],
            ],
        ]);

        ReportReceived::dispatch($report);

        return response()->json([
            'id' => $report->id,
            'message' => 'ARF report received',
            'parsed_type' => $parsed['abuse_type'],
        ], 201);
    }

    /**
     * Bulk report (up to 100 JSON reports).
     */
    public function bulk(Request $request): JsonResponse
    {
        $request->validate([
            'reports' => ['required', 'array', 'max:100'],
            'reports.*.abuse_type' => ['required', 'string'],
            'reports.*.description' => ['required', 'string', 'max:10000'],
            'reports.*.target_ip' => ['nullable', 'ip'],
            'reports.*.target_domain' => ['nullable', 'string', 'max:255'],
        ]);

        $reporter = $request->get('reporter');
        $ids = [];

        foreach ($request->input('reports') as $data) {
            $report = $this->normalizer->fromApi($data, $reporter, $request->ip());
            ReportReceived::dispatch($report);
            $ids[] = $report->id;
        }

        return response()->json([
            'count' => count($ids),
            'ids' => $ids,
            'message' => 'Reports received',
        ], 201);
    }
}
