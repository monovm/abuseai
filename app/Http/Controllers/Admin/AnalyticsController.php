<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CaseStatus;
use App\Http\Controllers\Controller;
use App\Models\AbuseCase;
use App\Models\AbuseReport;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    public function index()
    {
        $totalCases = AbuseCase::count();
        $openCases = AbuseCase::whereIn('status', [CaseStatus::Open, CaseStatus::Investigating])->count();
        $totalReports = AbuseReport::count();
        $suspendedCustomers = Customer::where('is_suspended', true)->count();

        // Cases by type
        $casesByType = AbuseCase::select('abuse_type', DB::raw('count(*) as total'))
            ->groupBy('abuse_type')
            ->pluck('total', 'abuse_type')
            ->toArray();

        // Cases by status
        $casesByStatus = AbuseCase::select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        // Reports per day (last 30 days)
        $reportsPerDay = AbuseReport::select(DB::raw('DATE(created_at) as date'), DB::raw('count(*) as total'))
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('total', 'date')
            ->toArray();

        // Top abused IPs
        $topIps = AbuseCase::select('target_ip', DB::raw('count(*) as total'))
            ->whereNotNull('target_ip')
            ->groupBy('target_ip')
            ->orderByDesc('total')
            ->take(10)
            ->pluck('total', 'target_ip')
            ->toArray();

        return view('admin.analytics.index', compact(
            'totalCases', 'openCases', 'totalReports', 'suspendedCustomers',
            'casesByType', 'casesByStatus', 'reportsPerDay', 'topIps',
        ));
    }
}
