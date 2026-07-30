<div>
    {{-- Actions bar --}}
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
        <div></div>
        <a href="/admin/cases/create" class="btn btn-primary btn-sm">+ Create Case</a>
    </div>

    {{-- Target-shape chips --}}
    <div style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:10px;">
        @php $targets = [['', 'Any target'], ['ip', 'IP'], ['domain', 'Domain']]; @endphp
        @foreach($targets as [$value, $label])
            <button type="button" wire:click="$set('filterTarget', '{{ $value }}')"
                style="padding:5px 12px; border-radius:999px; font-size:12px; font-weight:500; cursor:pointer; border:1px solid {{ $filterTarget === $value ? '#2563eb' : '#e5e7eb' }}; background:{{ $filterTarget === $value ? '#2563eb' : '#fff' }}; color:{{ $filterTarget === $value ? '#fff' : '#374151' }};">
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- Filters --}}
    <div class="filters">
        <select wire:model.live="filterStatus">
            <option value="">All Statuses</option>
            @foreach($statuses as $s)
                <option value="{{ $s->value }}">{{ ucfirst($s->value) }}</option>
            @endforeach
        </select>
        <select wire:model.live="filterType">
            <option value="">All Types</option>
            @foreach($types as $t)
                <option value="{{ $t->value }}">{{ $t->label() }}</option>
            @endforeach
        </select>
        <select wire:model.live="filterSeverity">
            <option value="">All Severities</option>
            @foreach($severities as $sv)
                <option value="{{ $sv->value }}">{{ ucfirst($sv->value) }}</option>
            @endforeach
        </select>
        <select wire:model.live="filterSla">
            <option value="">All SLA</option>
            <option value="breached">SLA Breached</option>
        </select>
        <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search cases..." style="flex:1; min-width:0; max-width:300px;">
        @if(count($selectedCases))
            <button wire:click="bulkClose" class="btn btn-secondary btn-sm" onclick="return confirm('Close selected cases?')">Close Selected ({{ count($selectedCases) }})</button>
        @endif
    </div>

    {{-- Table --}}
    <div class="card" style="padding:0; overflow:hidden;">
        <div class="table-wrap">
        <table class="table">
            <thead>
                @php
                    $pageIds = $cases->pluck('id')->map(fn ($id) => (string) $id)->all();
                    $allOnPageSelected = ! empty($pageIds) && empty(array_diff($pageIds, $selectedCases));
                @endphp
                <tr>
                    <th style="width:30px;">
                        <input type="checkbox"
                            aria-label="Select all cases on this page"
                            @checked($allOnPageSelected)
                            wire:click="toggleSelectAllOnPage({{ json_encode($pageIds) }})">
                    </th>
                    <x-sortable-th column="case_number" :sortBy="$sortBy" :sortDir="$sortDir">Case #</x-sortable-th>
                    <x-sortable-th column="status" :sortBy="$sortBy" :sortDir="$sortDir">Status</x-sortable-th>
                    <x-sortable-th column="abuse_type" :sortBy="$sortBy" :sortDir="$sortDir">Type</x-sortable-th>
                    <x-sortable-th column="severity_score" :sortBy="$sortBy" :sortDir="$sortDir">Score</x-sortable-th>
                    <x-sortable-th column="target_ip" :sortBy="$sortBy" :sortDir="$sortDir">Target</x-sortable-th>
                    <x-sortable-th column="report_count" :sortBy="$sortBy" :sortDir="$sortDir">Reports</x-sortable-th>
                    <x-sortable-th column="created_at" :sortBy="$sortBy" :sortDir="$sortDir">Created</x-sortable-th>
                    <x-sortable-th column="sla_deadline" :sortBy="$sortBy" :sortDir="$sortDir">SLA</x-sortable-th>
                </tr>
            </thead>
            <tbody>
                @forelse($cases as $case)
                    <tr>
                        <td><input type="checkbox" wire:model.live="selectedCases" value="{{ $case->id }}"></td>
                        <td><a href="/admin/cases/{{ $case->id }}" style="color:var(--primary); text-decoration:none; font-weight:500;">{{ $case->case_number }}</a></td>
                        <td><span class="badge badge-{{ $case->status->value }}">{{ ucfirst($case->status->value) }}</span></td>
                        <td>{{ $case->abuse_type->label() }}</td>
                        <td>
                            <span class="badge badge-{{ $case->severity_level->value }}">
                                {{ number_format($case->severity_score, 1) }}
                            </span>
                        </td>
                        <td class="text-sm">{{ $case->target_ip ?? $case->target_domain ?? '-' }}</td>
                        <td>{{ $case->report_count }}</td>
                        <td class="text-sm text-muted">@reldate($case->created_at)</td>
                        <td class="text-sm {{ $case->sla_deadline && $case->sla_deadline->isPast() ? 'text-danger' : 'text-muted' }}">
                            {{ $case->sla_deadline?->diffForHumans() ?? '-' }}
                        </td>
                    </tr>
                @empty
                    <x-empty-state colspan="9" title="No cases match these filters"
                        message="Try clearing a filter, or wait for a new report to land." />
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    <div class="mt-4">{{ $cases->links() }}</div>
</div>
