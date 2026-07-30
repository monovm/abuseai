<?php

namespace App\Http\Controllers\WebForm;

use App\Events\ReportReceived;
use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitAbuseReportRequest;
use App\Models\Brand;
use App\Services\Ingestion\ReportNormalizerService;

class AbuseFormController extends Controller
{
    public function __construct(
        protected ReportNormalizerService $normalizer,
    ) {}

    public function show()
    {
        return view('webform.report');
    }

    public function store(SubmitAbuseReportRequest $request)
    {
        /** @var Brand|null $brand */
        $brand = $request->attributes->get('brand') ?? Brand::getDefault();

        $report = $this->normalizer->fromWebForm(
            $request->validated(),
            $request->ip(),
            $brand,
        );

        // Fire event — this now creates the case synchronously
        ReportReceived::dispatch($report);

        $report->refresh();
        $caseNumber = $report->case?->case_number;

        $message = $caseNumber
            ? "Report submitted. Case number: {$caseNumber}"
            : "Report submitted. Reference: {$report->id}";

        return redirect()
            ->route('report.show')
            ->with('success', $message);
    }
}
