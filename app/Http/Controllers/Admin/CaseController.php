<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CaseStatus;
use App\Enums\SeverityLevel;
use App\Http\Controllers\Controller;
use App\Models\AbuseCase;
use App\Models\AbuseReport;

class CaseController extends Controller
{
    public function index()
    {
        return view('admin.cases.index', [
            'openCount' => AbuseCase::whereIn('status', [CaseStatus::Open, CaseStatus::Investigating])->count(),
            'criticalCount' => AbuseCase::where('severity_level', SeverityLevel::Critical)->whereNotIn('status', [CaseStatus::Closed, CaseStatus::Resolved])->count(),
            'slaBreachedCount' => AbuseCase::whereNotNull('sla_deadline')->where('sla_deadline', '<', now())->whereNotIn('status', [CaseStatus::Closed, CaseStatus::Resolved])->count(),
            'todayReportCount' => AbuseReport::whereDate('created_at', today())->count(),
        ]);
    }

    public function show(AbuseCase $case)
    {
        return view('admin.cases.show', compact('case'));
    }
}
