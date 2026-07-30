<?php

namespace App\Livewire\Admin;

use App\Models\Reporter;
use Livewire\Component;
use Livewire\WithPagination;

class ReporterManager extends Component
{
    use WithPagination;

    public string $search = '';
    public string $filter = '';

    protected $queryString = [
        'search' => ['as' => 'q'],
        'filter' => ['as' => 'f'],
    ];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingFilter(): void
    {
        $this->resetPage();
    }

    public function toggleLawEnforcement(string $reporterId): void
    {
        $reporter = Reporter::find($reporterId);
        if (! $reporter) {
            return;
        }
        $reporter->is_law_enforcement = ! $reporter->is_law_enforcement;
        if ($reporter->is_law_enforcement) {
            $reporter->is_trusted = true;
        }
        $reporter->save();

        session()->flash('reporter-message', $reporter->is_law_enforcement
            ? "Marked {$reporter->email} as law enforcement."
            : "Removed law enforcement flag from {$reporter->email}.");
    }

    public function toggleTrusted(string $reporterId): void
    {
        $reporter = Reporter::find($reporterId);
        if (! $reporter) {
            return;
        }
        $reporter->is_trusted = ! $reporter->is_trusted;
        $reporter->save();
    }

    public function toggleBlocked(string $reporterId): void
    {
        $reporter = Reporter::find($reporterId);
        if (! $reporter) {
            return;
        }
        $reporter->is_blocked = ! $reporter->is_blocked;
        $reporter->save();
    }

    public function render()
    {
        $query = Reporter::query();

        if ($this->search) {
            $term = "%{$this->search}%";
            $query->where(function ($q) use ($term) {
                $q->where('email', 'like', $term)
                    ->orWhere('name', 'like', $term);
            });
        }

        if ($this->filter === 'law_enforcement') {
            $query->where('is_law_enforcement', true);
        } elseif ($this->filter === 'trusted') {
            $query->where('is_trusted', true);
        } elseif ($this->filter === 'blocked') {
            $query->where('is_blocked', true);
        }

        // Load follow-up counts in one pass so we don't miss any inbound
        // communication — follow-ups on existing cases are stored as
        // CaseActions ("email_reply"), not as AbuseReport rows, so the plain
        // reports() count under-reports a reporter's actual traffic.
        $reporters = $query
            ->withCount([
                'reports',
                'reports as reports_linked_count' => fn ($q) => $q->whereNotNull('case_id')->where('is_duplicate', false),
                'reports as reports_duplicate_count' => fn ($q) => $q->where('is_duplicate', true),
            ])
            ->orderByDesc('report_count')
            ->paginate(25);

        $emails = $reporters->pluck('email')->filter()->unique()->values();
        $followupCounts = [];
        if ($emails->isNotEmpty()) {
            // Cross-DB-safe: pull matching rows and group in PHP. Bounded by the
            // 25 reporters on the page so this stays cheap.
            $followupCounts = \App\Models\CaseAction::query()
                ->where('payload->type', 'email_reply')
                ->whereIn('payload->from', $emails->all())
                ->get(['payload'])
                ->groupBy(fn ($a) => $a->payload['from'] ?? null)
                ->map(fn ($group) => $group->count())
                ->toArray();
        }

        return view('livewire.admin.reporter-manager', [
            'reporters' => $reporters,
            'followupCounts' => $followupCounts,
            'leDomains' => (array) config('abusedesk.law_enforcement.reporter_domains', []),
        ]);
    }
}
