<?php

namespace App\Livewire\Admin;

use App\Models\IpAddress;
use App\Models\Reporter;
use App\Services\Enrichment\AbuseIpDbService;
use App\Services\Ingestion\ReportNormalizerService;
use Livewire\Component;
use Livewire\WithPagination;

class AbuseIpDbCheck extends Component
{
    use WithPagination;

    public string $search = '';
    public string $filterScore = '';
    public string $singleIp = '';
    public ?array $singleResult = null;
    public bool $scanning = false;
    public int $scanProgress = 0;
    public int $scanTotal = 0;

    public function checkSingleIp(AbuseIpDbService $service): void
    {
        if (! $service->isConfigured()) {
            session()->flash('error', 'AbuseIPDB API key not configured. Set ABUSEIPDB_KEY in .env');
            return;
        }

        if (! $this->singleIp || ! filter_var($this->singleIp, FILTER_VALIDATE_IP)) {
            $this->addError('singleIp', 'Enter a valid IP address.');
            return;
        }

        $result = $service->check($this->singleIp, 90, true);

        if ($result === null) {
            session()->flash('error', 'Failed to query AbuseIPDB. Check API key.');
            return;
        }

        $this->singleResult = $result;

        // Update IP record if in our inventory
        $ipRecord = IpAddress::findByIp($this->singleIp);
        if ($ipRecord) {
            $ipRecord->update([
                'abuseipdb_score' => $result['abuseConfidenceScore'] ?? 0,
                'abuseipdb_reports' => $result['totalReports'] ?? 0,
                'abuseipdb_data' => $result,
                'abuseipdb_checked_at' => now(),
            ]);
        }
    }

    public function checkIp(string $id, AbuseIpDbService $service): void
    {
        if (! $service->isConfigured()) {
            session()->flash('error', 'AbuseIPDB API key not configured.');
            return;
        }

        $ipRecord = IpAddress::findOrFail($id);
        $result = $service->check($ipRecord->ip_address, 90, true);

        if ($result === null) {
            session()->flash('error', "Failed to check {$ipRecord->ip_address}.");
            return;
        }

        $ipRecord->update([
            'abuseipdb_score' => $result['abuseConfidenceScore'] ?? 0,
            'abuseipdb_reports' => $result['totalReports'] ?? 0,
            'abuseipdb_data' => $result,
            'abuseipdb_checked_at' => now(),
        ]);

        session()->flash('success', "{$ipRecord->ip_address}: Score {$result['abuseConfidenceScore']}%, {$result['totalReports']} reports.");
    }

    public function createCaseForIp(string $id, AbuseIpDbService $service, ReportNormalizerService $normalizer): void
    {
        $ipRecord = IpAddress::findOrFail($id);

        if (! $ipRecord->abuseipdb_data || ($ipRecord->abuseipdb_score ?? 0) === 0) {
            session()->flash('error', 'No abuse reports to create case from. Check the IP first.');
            return;
        }

        $reporter = Reporter::firstOrCreate(
            ['email' => 'abuseipdb@feeds.abuseai'],
            ['name' => 'AbuseIPDB', 'type' => 'feed', 'is_trusted' => true],
        );

        $data = $ipRecord->abuseipdb_data;
        $categories = $data['reports'][0]['categories'] ?? [];
        $abuseType = $this->mapAbuseIpDbCategories($categories);

        $evidence = "AbuseIPDB Report for {$ipRecord->ip_address}\n"
            . "Confidence Score: {$data['abuseConfidenceScore']}%\n"
            . "Total Reports: {$data['totalReports']}\n"
            . "ISP: " . ($data['isp'] ?? 'N/A') . "\n"
            . "Country: " . ($data['countryCode'] ?? 'N/A') . "\n"
            . "Last Reported: " . ($data['lastReportedAt'] ?? 'N/A') . "\n";

        if (! empty($data['reports'])) {
            $evidence .= "\nRecent Reports:\n";
            foreach (array_slice($data['reports'], 0, 5) as $r) {
                $evidence .= "  - " . ($r['reportedAt'] ?? '') . " | Categories: " . implode(',', $r['categories'] ?? []) . " | " . ($r['comment'] ?? '') . "\n";
            }
        }

        // Create the report
        $report = $normalizer->fromFeed([
            'abuse_type' => $abuseType,
            'target_ip' => $ipRecord->ip_address,
            'evidence' => $evidence,
            'reported_at' => $data['lastReportedAt'] ?? now(),
        ], $reporter);

        // Create the case DIRECTLY (don't rely on queue)
        $caseCreator = app(\App\Services\CaseEngine\CaseCreatorService::class);
        $case = $caseCreator->findOrCreateCase($report);

        if ($case) {
            session()->flash('success', "Case {$case->case_number} created for {$ipRecord->ip_address}.");
        } else {
            session()->flash('error', "Report created but case was not created. The IP may not be in your active inventory.");
        }
    }

    protected function mapAbuseIpDbCategories(array $categories): string
    {
        $map = [
            3 => 'fraud', 4 => 'ddos', 5 => 'ddos',
            9 => 'spam', 10 => 'spam', 11 => 'spam',
            14 => 'phishing', 15 => 'malware', 16 => 'malware',
            18 => 'ddos', 19 => 'phishing', 20 => 'fraud',
        ];

        foreach ($categories as $cat) {
            if (isset($map[$cat])) {
                return $map[$cat];
            }
        }

        return 'other';
    }

    public function render()
    {
        $query = IpAddress::active()->with('customer');

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('ip_address', 'like', "%{$this->search}%")
                  ->orWhere('server_name', 'like', "%{$this->search}%");
            });
        }

        if ($this->filterScore === 'reported') {
            $query->where('abuseipdb_score', '>', 0);
        } elseif ($this->filterScore === 'clean') {
            $query->where('abuseipdb_score', 0);
        } elseif ($this->filterScore === 'unchecked') {
            $query->whereNull('abuseipdb_checked_at');
        }

        return view('livewire.admin.abuseipdb-check', [
            'ips' => $query->paginate(50),
            'totalActive' => IpAddress::active()->count(),
            'totalChecked' => IpAddress::active()->whereNotNull('abuseipdb_checked_at')->count(),
            'totalReported' => IpAddress::active()->where('abuseipdb_score', '>', 0)->count(),
            'totalClean' => IpAddress::active()->where('abuseipdb_score', 0)->count(),
            'isConfigured' => app(AbuseIpDbService::class)->isConfigured(),
        ]);
    }
}
