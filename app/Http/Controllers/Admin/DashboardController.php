<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CaseStatus;
use App\Enums\SeverityLevel;
use App\Http\Controllers\Controller;
use App\Models\AbuseCase;
use App\Models\AbuseReport;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\IpAddress;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        // KPI stats
        $totalCases = AbuseCase::count();
        $openCases = AbuseCase::whereIn('status', [CaseStatus::Open, CaseStatus::Investigating])->count();
        $criticalCases = AbuseCase::where('severity_level', SeverityLevel::Critical)
            ->whereNotIn('status', [CaseStatus::Closed, CaseStatus::Resolved])
            ->count();
        $todayReports = AbuseReport::whereDate('created_at', today())->count();
        $totalReports = AbuseReport::count();
        // Reports that reached no terminal outcome — indicates a pipeline miss.
        $unprocessedReports = AbuseReport::withStatus('unprocessed')->count();
        $suspendedCustomers = Customer::where('is_suspended', true)->count();

        // SLA breached
        $slaBreached = AbuseCase::whereNotNull('sla_deadline')
            ->where('sla_deadline', '<', now())
            ->whereNotIn('status', [CaseStatus::Closed, CaseStatus::Resolved])
            ->count();

        // IP Inventory summary
        $totalIps = IpAddress::where('status', 'active')->count();
        $cleanIps = IpAddress::where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('reputation_score')->orWhere('reputation_score', '>=', 80);
            })->count();
        $flaggedIps = IpAddress::where('status', 'active')
            ->whereNotNull('reputation_score')
            ->where('reputation_score', '<', 50)
            ->count();

        // Overall reputation (average of all active IPs with scores)
        $avgReputation = IpAddress::where('status', 'active')
            ->whereNotNull('reputation_score')
            ->avg('reputation_score');

        // Cases by brand
        $casesByBrand = AbuseCase::select('brand_id', DB::raw('count(*) as total'))
            ->whereNotNull('brand_id')
            ->groupBy('brand_id')
            ->pluck('total', 'brand_id')
            ->toArray();

        $brandNames = Brand::whereIn('id', array_keys($casesByBrand))->pluck('name', 'id')->toArray();
        $casesByBrandLabeled = [];
        foreach ($casesByBrand as $brandId => $count) {
            $casesByBrandLabeled[$brandNames[$brandId] ?? 'Unknown'] = $count;
        }

        // Add unbranded cases
        $unbrandedCases = AbuseCase::whereNull('brand_id')->count();
        if ($unbrandedCases > 0) {
            $casesByBrandLabeled['Unassigned'] = $unbrandedCases;
        }

        // Daily cases & reports (last 14 days) — build continuous date range with zero-fill
        $windowStart = now()->subDays(13)->startOfDay();
        $casesByDate = AbuseCase::select(DB::raw('DATE(created_at) as date'), DB::raw('count(*) as total'))
            ->where('created_at', '>=', $windowStart)
            ->groupBy('date')
            ->pluck('total', 'date')
            ->toArray();
        $reportsByDate = AbuseReport::select(DB::raw('DATE(created_at) as date'), DB::raw('count(*) as total'))
            ->where('created_at', '>=', $windowStart)
            ->groupBy('date')
            ->pluck('total', 'date')
            ->toArray();

        $dailyLabels = [];
        $dailyCases = [];
        $dailyReports = [];
        for ($i = 13; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $dailyLabels[] = $date;
            $dailyCases[] = (int) ($casesByDate[$date] ?? 0);
            $dailyReports[] = (int) ($reportsByDate[$date] ?? 0);
        }

        // Cases by type
        $casesByType = AbuseCase::select('abuse_type', DB::raw('count(*) as total'))
            ->groupBy('abuse_type')
            ->pluck('total', 'abuse_type')
            ->toArray();

        // Cases by severity
        $casesBySeverity = AbuseCase::select('severity_level', DB::raw('count(*) as total'))
            ->whereNotNull('severity_level')
            ->groupBy('severity_level')
            ->pluck('total', 'severity_level')
            ->toArray();

        // Recent cases (latest 10)
        $recentCases = AbuseCase::with('customer')
            ->orderByDesc('created_at')
            ->take(10)
            ->get();

        // Top abused IPs (last 30 days)
        $topIps = AbuseCase::select('target_ip', DB::raw('count(*) as total'))
            ->whereNotNull('target_ip')
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('target_ip')
            ->orderByDesc('total')
            ->take(5)
            ->pluck('total', 'target_ip')
            ->toArray();

        return view('admin.dashboard.index', compact(
            'totalCases', 'openCases', 'criticalCases', 'todayReports', 'totalReports',
            'unprocessedReports',
            'suspendedCustomers', 'slaBreached',
            'totalIps', 'cleanIps', 'flaggedIps', 'avgReputation',
            'casesByBrandLabeled', 'dailyLabels', 'dailyCases', 'dailyReports',
            'casesByType', 'casesBySeverity',
            'recentCases', 'topIps',
        ));
    }
}
