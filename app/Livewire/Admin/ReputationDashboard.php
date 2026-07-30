<?php

namespace App\Livewire\Admin;

use App\Models\IpAddress;
use App\Services\IpReputationService;
use Livewire\Component;
use Livewire\WithPagination;

class ReputationDashboard extends Component
{
    use WithPagination;

    public string $search = '';
    public string $filterReputation = '';
    public string $filterDnsbl = '';
    public string $filterSnds = '';
    public string $filterAbuseipdb = '';
    public string $sortField = 'reputation_score';
    public string $sortDirection = 'asc';
    public ?string $selectedIpId = null;

    protected $queryString = [
        'search' => ['except' => ''],
        'filterReputation' => ['except' => ''],
        'filterDnsbl' => ['except' => ''],
        'filterSnds' => ['except' => ''],
        'filterAbuseipdb' => ['except' => ''],
        'sortField' => ['except' => 'reputation_score'],
        'sortDirection' => ['except' => 'asc'],
    ];

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function updatingFilterReputation()
    {
        $this->resetPage();
    }

    public function updatingFilterDnsbl()
    {
        $this->resetPage();
    }

    public function updatingFilterSnds()
    {
        $this->resetPage();
    }

    public function updatingFilterAbuseipdb()
    {
        $this->resetPage();
    }

    public function applySort(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }
        $this->resetPage();
    }

    public function showDetail(string $id): void
    {
        $this->selectedIpId = $id;
    }

    public function closeDetail(): void
    {
        $this->selectedIpId = null;
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'filterReputation', 'filterDnsbl', 'filterSnds', 'filterAbuseipdb']);
        $this->resetPage();
    }

    public function scanIp(string $id, IpReputationService $reputation): void
    {
        $ip = IpAddress::findOrFail($id);

        try {
            $result = $reputation->collectAndEvaluate($ip);

            $msg = "{$ip->ip_address}: Score {$result['score']}/100 ({$result['label']})";
            if ($result['case_created'] ?? false) {
                $msg .= " — Case {$result['case_number']} created!";
            }
            session()->flash('success', $msg);
        } catch (\Throwable $e) {
            session()->flash('error', "Scan failed for {$ip->ip_address}: " . $e->getMessage());
        }
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

        // Reputation filter
        if ($this->filterReputation === 'critical') {
            $query->where('reputation_score', '<', 30);
        } elseif ($this->filterReputation === 'poor') {
            $query->whereBetween('reputation_score', [30, 49.99]);
        } elseif ($this->filterReputation === 'fair') {
            $query->whereBetween('reputation_score', [50, 79.99]);
        } elseif ($this->filterReputation === 'good') {
            $query->where('reputation_score', '>=', 80);
        } elseif ($this->filterReputation === 'unscanned') {
            $query->whereNull('reputation_updated_at');
        }

        // DNSBL filter
        if ($this->filterDnsbl === 'listed') {
            $query->whereNotNull('reputation_details')
                  ->whereRaw("(reputation_details->'dnsbl'->>'actionable_count')::int > 0");
        } elseif ($this->filterDnsbl === 'clean') {
            $query->whereNotNull('reputation_details')
                  ->where(function ($q) {
                      $q->whereRaw("(reputation_details->'dnsbl'->>'actionable_count')::int = 0")
                        ->orWhereRaw("reputation_details->'dnsbl' IS NULL");
                  });
        }

        // SNDS filter
        if ($this->filterSnds === 'red') {
            $query->whereNotNull('reputation_details')
                  ->whereRaw("reputation_details->'snds'->>'filter_result' = 'red'");
        } elseif ($this->filterSnds === 'yellow') {
            $query->whereNotNull('reputation_details')
                  ->whereRaw("reputation_details->'snds'->>'filter_result' = 'yellow'");
        } elseif ($this->filterSnds === 'green') {
            $query->whereNotNull('reputation_details')
                  ->whereRaw("reputation_details->'snds'->>'filter_result' = 'green'");
        }

        // AbuseIPDB score filter
        if ($this->filterAbuseipdb === 'high') {
            $query->where('abuseipdb_score', '>', 50);
        } elseif ($this->filterAbuseipdb === 'medium') {
            $query->whereBetween('abuseipdb_score', [1, 50]);
        } elseif ($this->filterAbuseipdb === 'clean') {
            $query->where(function ($q) {
                $q->where('abuseipdb_score', 0)->orWhereNull('abuseipdb_score');
            });
        }

        // Sorting
        $allowedSorts = ['ip_address', 'server_name', 'reputation_score', 'abuseipdb_score', 'reputation_updated_at'];
        $sortField = in_array($this->sortField, $allowedSorts) ? $this->sortField : 'reputation_score';
        $query->orderByRaw("{$sortField} IS NULL, {$sortField} " . ($this->sortDirection === 'desc' ? 'DESC' : 'ASC'));

        // Selected IP detail
        $selectedIp = null;
        if ($this->selectedIpId) {
            $selectedIp = IpAddress::with('customer')->find($this->selectedIpId);
        }

        $hasActiveFilters = $this->search || $this->filterReputation || $this->filterDnsbl || $this->filterSnds || $this->filterAbuseipdb;

        return view('livewire.admin.reputation-dashboard', [
            'ips' => $query->paginate(50),
            'selectedIp' => $selectedIp,
            'hasActiveFilters' => $hasActiveFilters,
            'stats' => [
                'total' => IpAddress::active()->count(),
                'scanned' => IpAddress::active()->whereNotNull('reputation_updated_at')->count(),
                'good' => IpAddress::active()->where('reputation_score', '>=', 80)->count(),
                'fair' => IpAddress::active()->whereBetween('reputation_score', [50, 79.99])->count(),
                'poor' => IpAddress::active()->whereBetween('reputation_score', [30, 49.99])->count(),
                'critical' => IpAddress::active()->where('reputation_score', '<', 30)->count(),
            ],
        ]);
    }
}
