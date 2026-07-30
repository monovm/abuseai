<?php

namespace App\Livewire\Admin;

use App\Enums\AbuseType;
use App\Enums\CaseStatus;
use App\Enums\SeverityLevel;
use App\Models\AbuseCase;
use App\Support\JsonColumnSearch;
use Livewire\Component;
use Livewire\WithPagination;

class CaseQueue extends Component
{
    use WithPagination;

    public string $filterStatus = '';
    public string $filterType = '';
    public string $filterSeverity = '';
    public string $filterSla = '';
    public string $filterTarget = '';
    public string $search = '';
    public string $sortBy = 'created_at';
    public string $sortDir = 'desc';
    public array $selectedCases = [];

    protected $queryString = [
        'filterStatus' => ['as' => 'status'],
        'filterType' => ['as' => 'type'],
        'filterSeverity' => ['as' => 'severity'],
        'filterSla' => ['as' => 'sla'],
        'filterTarget' => ['as' => 'target'],
        'search' => ['as' => 'q'],
    ];

    public function updatingSearch()
    {
        $this->resetPage();
    }

    /**
     * Columns the case queue can be sorted by. Whitelisted so a crafted
     * wire:click payload can't pivot the orderBy clause to an arbitrary
     * column or expression.
     */
    private const SORTABLE_COLUMNS = [
        'case_number', 'status', 'abuse_type', 'severity_score',
        'target_ip', 'report_count', 'created_at', 'sla_deadline',
    ];

    /**
     * Renamed from sortBy() to dodge the property/method name collision on
     * Livewire's frontend proxy — $wire.sortBy() resolves to the string
     * property $sortBy and is not callable.
     */
    public function applySort(string $column): void
    {
        if (! in_array($column, self::SORTABLE_COLUMNS, true)) {
            return;
        }
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'desc';
        }
    }

    public function bulkClose(): void
    {
        AbuseCase::whereIn('id', $this->selectedCases)
            ->update([
                'status' => CaseStatus::Closed,
                'closed_at' => now(),
            ]);

        $this->selectedCases = [];
    }

    public function toggleSelectAllOnPage(array $pageIds): void
    {
        $allSelected = ! empty($pageIds) && empty(array_diff($pageIds, $this->selectedCases));
        $this->selectedCases = $allSelected
            ? array_values(array_diff($this->selectedCases, $pageIds))
            : array_values(array_unique(array_merge($this->selectedCases, $pageIds)));
    }

    public function render()
    {
        $query = AbuseCase::with('customer');

        if ($this->filterStatus) {
            $query->where('status', $this->filterStatus);
        }
        if ($this->filterType) {
            $query->where('abuse_type', $this->filterType);
        }
        if ($this->filterSeverity) {
            $query->where('severity_level', $this->filterSeverity);
        }
        if ($this->filterSla === 'breached') {
            $query->whereNotNull('sla_deadline')
                ->where('sla_deadline', '<', now())
                ->whereNotIn('status', [CaseStatus::Closed, CaseStatus::Resolved]);
        }
        if ($this->filterTarget) {
            // Cases only carry target_ip / target_domain — target_url lives
            // on reports. We accept the same query-string keys so the
            // sidebar links can reuse one filter vocab, and silently no-op
            // on values that don't apply to cases.
            $column = match ($this->filterTarget) {
                'ip' => 'target_ip',
                'domain' => 'target_domain',
                default => null,
            };
            if ($column) {
                $query->whereNotNull($column)->where($column, '!=', '');
            }
        }
        if ($this->search) {
            $query->where(function ($q) {
                $needle = $this->search;
                $q->where('case_number', 'like', "%{$needle}%")
                  ->orWhere('target_ip', 'like', "%{$needle}%")
                  ->orWhere('target_domain', 'like', "%{$needle}%");

                // Match any external case number (police, CERT, DMCA, ISP
                // ticket, etc.) and extra IPs stored in the JSON columns.
                JsonColumnSearch::orWhereContains($q, 'external_case_numbers', $needle);
                JsonColumnSearch::orWhereContains($q, 'extra_target_ips', $needle);
            });
        }

        $cases = $query->orderBy($this->sortBy, $this->sortDir)->paginate(25);

        return view('livewire.admin.case-queue', [
            'cases' => $cases,
            'statuses' => CaseStatus::cases(),
            'types' => AbuseType::cases(),
            'severities' => SeverityLevel::cases(),
        ]);
    }
}
