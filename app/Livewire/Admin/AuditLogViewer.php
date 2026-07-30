<?php

namespace App\Livewire\Admin;

use App\Models\AuditLog;
use Livewire\Component;
use Livewire\WithPagination;

class AuditLogViewer extends Component
{
    use WithPagination;

    public string $search = '';
    public string $filterEntity = '';
    public string $filterEvent = '';
    public bool $onlyAiCalls = false;

    protected $queryString = [
        'search' => ['as' => 'q'],
        'filterEntity' => ['as' => 'entity'],
        'filterEvent' => ['as' => 'event'],
        'onlyAiCalls' => ['as' => 'ai'],
    ];

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function render()
    {
        $query = AuditLog::query()->with('actor:id,name')->latest('created_at');

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('entity_id', 'like', "%{$this->search}%")
                  ->orWhere('event', 'like', "%{$this->search}%")
                  ->orWhere('ai_prompt', 'like', "%{$this->search}%")
                  ->orWhere('ai_response', 'like', "%{$this->search}%");
            });
        }

        if ($this->filterEntity) {
            $query->where('entity_type', $this->filterEntity);
        }

        if ($this->filterEvent) {
            $query->where('event', $this->filterEvent);
        }

        if ($this->onlyAiCalls) {
            $query->aiCalls();
        }

        $entityTypes = AuditLog::distinct()->pluck('entity_type')->sort()->values();
        $events = AuditLog::distinct()->pluck('event')->sort()->values();

        return view('livewire.admin.audit-log-viewer', [
            'logs' => $query->paginate(50),
            'entityTypes' => $entityTypes,
            'events' => $events,
            'totalLogs' => AuditLog::count(),
            'totalAiCalls' => AuditLog::aiCalls()->count(),
        ]);
    }
}
