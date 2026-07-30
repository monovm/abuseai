<div>
    <x-flash-messages />

    {{-- Reporter filter banner --}}
    @if($activeReporter ?? null)
        <div style="display:flex; align-items:center; justify-content:space-between; padding:10px 14px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; margin-bottom:12px; font-size:13px;">
            <span>Filtered by reporter: <strong>{{ $activeReporter->name ?: $activeReporter->email }}</strong> &middot; <span class="text-muted">{{ $activeReporter->email }}</span></span>
            <a href="{{ route('admin.reports.index') }}" style="color:#2563eb; font-size:12px;">Clear reporter filter</a>
        </div>
    @endif

    {{-- Target-shape chips --}}
    <div style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:10px;">
        @php $targets = [['', 'Any target'], ['ip', 'IP'], ['domain', 'Domain'], ['url', 'URL']]; @endphp
        @foreach($targets as [$value, $label])
            <button type="button" wire:click="$set('filterTarget', '{{ $value }}')"
                style="padding:5px 12px; border-radius:999px; font-size:12px; font-weight:500; cursor:pointer; border:1px solid {{ $filterTarget === $value ? '#2563eb' : '#e5e7eb' }}; background:{{ $filterTarget === $value ? '#2563eb' : '#fff' }}; color:{{ $filterTarget === $value ? '#fff' : '#374151' }};">
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- Filters --}}
    <div class="filters">
        <button type="button" wire:click="filterLawEnforcement" title="Show only law enforcement requests"
            style="display:inline-flex; align-items:center; gap:5px; padding:6px 12px; border-radius:6px; font-size:13px; font-weight:500; cursor:pointer; white-space:nowrap; {{ $filterType === \App\Enums\AbuseType::LawEnforcement->value ? 'background:#4338ca; color:#fff; border:1px solid #4338ca;' : 'background:#eef2ff; color:#4338ca; border:1px solid #c7d2fe;' }}">
            ⚖️ Law Enforcement
        </button>
        <select wire:model.live="filterSource">
            <option value="">All Sources</option>
            @foreach($sources as $src)
                <option value="{{ $src }}">{{ ucfirst($src) }}</option>
            @endforeach
        </select>
        <select wire:model.live="filterType">
            <option value="">All Types</option>
            @foreach($types as $t)
                <option value="{{ $t->value }}">{{ $t->label() }}</option>
            @endforeach
        </select>
        <select wire:model.live="filterStatus">
            <option value="">All Statuses</option>
            <option value="linked">Linked (case opened)</option>
            <option value="duplicate">Duplicate</option>
            <option value="noise">Noise (AI filtered)</option>
            <option value="no_target">No Target</option>
            <option value="ip_not_ours">IP Not Ours</option>
            <option value="unprocessed" style="color:#dc2626;">Unprocessed (stuck)</option>
        </select>
        <select wire:model.live="filterNoise">
            <option value="">All Reports</option>
            <option value="clean">Clean (actionable)</option>
            <option value="noise">Noise (score > 0.8)</option>
            <option value="not_abuse">Not Abuse</option>
            <option value="duplicate">Duplicates</option>
            <option value="unlinked">Unlinked (no case)</option>
        </select>
        <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search reports..." style="width:220px; min-width:0; flex:1; max-width:300px;">
    </div>

    {{-- Bulk Actions Bar --}}
    @if(count($selectedReports) > 0)
        <div style="display:flex; align-items:center; gap:12px; padding:10px 16px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; margin-bottom:12px;">
            <span style="font-size:14px; font-weight:500; color:#1e40af;">{{ count($selectedReports) }} report(s) selected</span>
            <button wire:click="bulkMarkNotAbuse" class="btn btn-warning btn-sm">Mark as Not Abuse</button>
            <button wire:click="bulkDelete" class="btn btn-sm" style="background:#dc2626; color:#fff; border:none; padding:6px 14px; border-radius:6px; font-size:13px; cursor:pointer;" onclick="return confirm('Delete selected unlinked reports?')">Delete Unlinked</button>
        </div>
    @endif

    {{-- Table --}}
    <div class="card" style="padding:0; overflow:hidden;">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width:40px; text-align:center;">
                            <input type="checkbox" wire:model.live="selectAll" wire:change="toggleSelectAll">
                        </th>
                        <x-sortable-th column="reported_at" :sortBy="$sortBy" :sortDir="$sortDir">Report Date</x-sortable-th>
                        <x-sortable-th column="created_at" :sortBy="$sortBy" :sortDir="$sortDir">Received</x-sortable-th>
                        <x-sortable-th column="case_id" :sortBy="$sortBy" :sortDir="$sortDir">Status</x-sortable-th>
                        <x-sortable-th column="case_id" :sortBy="$sortBy" :sortDir="$sortDir">Case</x-sortable-th>
                        <x-sortable-th column="source" :sortBy="$sortBy" :sortDir="$sortDir">Source</x-sortable-th>
                        <x-sortable-th column="abuse_type" :sortBy="$sortBy" :sortDir="$sortDir">Type</x-sortable-th>
                        <x-sortable-th column="target_ip" :sortBy="$sortBy" :sortDir="$sortDir">Target</x-sortable-th>
                        <x-sortable-th column="reporter_id" :sortBy="$sortBy" :sortDir="$sortDir">Reporter</x-sortable-th>
                        <x-sortable-th column="ai_noise_score" :sortBy="$sortBy" :sortDir="$sortDir">Noise</x-sortable-th>
                        <th>AI Classification</th>
                        <th>Flags</th>
                    </tr>
                </thead>
                @forelse($reports as $report)
                    <tbody x-data="{ open: false }" wire:key="report-row-{{ $report->id }}">
                        <tr @click="open = !open" title="Click to view details"
                            style="cursor:pointer;{{ $report->abuse_type === \App\Enums\AbuseType::LawEnforcement ? ' background:#e0e7ff;' : '' }}">
                            <td style="text-align:center;" @click.stop>
                                <input type="checkbox" wire:model.live="selectedReports" value="{{ $report->id }}">
                            </td>
                            <td style="white-space:nowrap; font-size:12px; color:#6b7280;">
                                {{ $report->reported_at?->format('M d, H:i') ?? '—' }}
                            </td>
                            <td style="white-space:nowrap; font-size:12px; color:#6b7280;">
                                {{ $report->created_at->diffForHumans() }}
                            </td>
                            <td>
                                @php
                                    $status = $report->processing_status;
                                    $statusMeta = [
                                        'linked' =>       ['bg' => '#d1fae5', 'fg' => '#065f46', 'label' => 'Linked'],
                                        'duplicate' =>    ['bg' => '#fef3c7', 'fg' => '#92400e', 'label' => 'Duplicate'],
                                        'noise' =>        ['bg' => '#fed7aa', 'fg' => '#9a3412', 'label' => 'Noise'],
                                        'no_target' =>    ['bg' => '#e5e7eb', 'fg' => '#6b7280', 'label' => 'No Target'],
                                        'ip_not_ours' =>  ['bg' => '#e5e7eb', 'fg' => '#6b7280', 'label' => 'IP Not Ours'],
                                        'errored' =>      ['bg' => '#fecaca', 'fg' => '#991b1b', 'label' => 'Errored'],
                                        'unprocessed' =>  ['bg' => '#fecaca', 'fg' => '#991b1b', 'label' => 'Unprocessed'],
                                    ][$status] ?? ['bg' => '#f3f4f6', 'fg' => '#374151', 'label' => $status];
                                    $statusTitle = match($status) {
                                        'linked' => 'Attached to case ' . ($report->case?->case_number ?? ''),
                                        'duplicate' => 'Marked duplicate of another report',
                                        'noise' => $report->metadata['not_abuse_reason'] ?? 'AI classified as non-abuse',
                                        'no_target' => 'No target IP or domain extractable',
                                        'ip_not_ours' => ($report->metadata['skipped_reason'] ?? 'IP not in active inventory'),
                                        'errored' => $report->metadata['processing_error'] ?? 'Pipeline threw an exception',
                                        'unprocessed' => 'Pipeline never produced a terminal outcome — investigate',
                                        default => $status,
                                    };
                                @endphp
                                <span class="badge" style="background:{{ $statusMeta['bg'] }}; color:{{ $statusMeta['fg'] }}; white-space:nowrap;" title="{{ $statusTitle }}">
                                    {{ $statusMeta['label'] }}
                                </span>
                            </td>
                            <td>
                                @if($report->case)
                                    <a href="/admin/cases/{{ $report->case_id }}" @click.stop style="color:#2563eb; text-decoration:none; font-weight:500;">
                                        {{ $report->case->case_number }}
                                    </a>
                                @else
                                    @php
                                        $meta = $report->metadata ?? [];
                                        $noCase = $meta['flagged_as_not_abuse'] ?? false
                                            ? 'AI: not abuse'
                                            : ($report->is_duplicate
                                                ? 'Duplicate'
                                                : ($meta['skipped_reason'] ?? 'No case'));
                                    @endphp
                                    <span style="color:#dc2626; font-size:11px;" title="{{ $meta['not_abuse_reason'] ?? $meta['skipped_reason'] ?? 'No case created' }}">{{ $noCase }}</span>
                                @endif
                            </td>
                            <td>
                                <span class="badge" style="background:#f3f4f6; color:#374151;">{{ ucfirst($report->source) }}</span>
                            </td>
                            <td>{{ $report->abuse_type?->label() ?? '-' }}</td>
                            <td class="text-sm">
                                @if($report->target_ip)
                                    <code style="font-size:11px;">{{ $report->target_ip }}</code>
                                @elseif($report->target_domain)
                                    {{ $report->target_domain }}
                                @elseif($report->target_url)
                                    <code style="font-size:11px; word-break:break-all;">{{ $report->target_url }}</code>
                                @else
                                    <span style="color:#9ca3af;">-</span>
                                @endif
                            </td>
                            <td class="text-sm">
                                @if($report->reporter)
                                    {{ $report->reporter->name ?? $report->reporter->email }}
                                @else
                                    <span style="color:#9ca3af;">-</span>
                                @endif
                            </td>
                            <td>
                                @if($report->ai_noise_score !== null)
                                    @php
                                        $ns = (float) $report->ai_noise_score;
                                        $nColor = $ns >= 0.8 ? '#dc2626' : ($ns >= 0.5 ? '#f59e0b' : '#10b981');
                                    @endphp
                                    <span style="font-size:12px; font-weight:600; color:{{ $nColor }};">{{ number_format($ns, 2) }}</span>
                                @else
                                    <span style="color:#9ca3af; font-size:12px;">-</span>
                                @endif
                            </td>
                            <td class="text-sm">
                                @if($report->ai_classification)
                                    @php $cls = $report->ai_classification; @endphp
                                    <span>{{ $cls['type'] ?? '-' }}</span>
                                    @if(isset($cls['confidence']))
                                        <span style="color:#9ca3af; font-size:11px;">({{ number_format($cls['confidence'] * 100) }}%)</span>
                                    @endif
                                @else
                                    <span style="color:#9ca3af;">Pending</span>
                                @endif
                            </td>
                            <td>
                                @if($report->is_duplicate)
                                    <span class="badge" style="background:#fef3c7; color:#92400e;">Duplicate</span>
                                @endif
                                @if($report->metadata['flagged_as_not_abuse'] ?? false)
                                    <span class="badge" style="background:#fecaca; color:#991b1b;">Not Abuse</span>
                                @elseif($report->metadata['flagged_as_noise'] ?? false)
                                    <span class="badge" style="background:#fed7aa; color:#9a3412;">Noise</span>
                                @endif
                                @if($report->metadata['skipped_reason'] ?? false)
                                    <span class="badge" style="background:#e5e7eb; color:#6b7280;">{{ str_replace('_', ' ', ucfirst($report->metadata['skipped_reason'])) }}</span>
                                @endif
                            </td>
                        </tr>

                        {{-- Expandable detail row --}}
                        <tr x-show="open" x-cloak>
                            <td colspan="12" style="padding:0; border-top:none;">
                                <div style="padding:16px 20px; background:#f9fafb; border-top:1px solid #e5e7eb; border-bottom:2px solid #e5e7eb;">
                                    @include('partials.report-detail', ['report' => $report])

                                    <div style="margin-top:12px; padding-top:12px; border-top:1px solid #e5e7eb; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                                        @if(! $report->case_id)
                                            <button wire:click="createCaseFromReport('{{ $report->id }}')"
                                                wire:loading.attr="disabled" wire:target="createCaseFromReport"
                                                onclick="return confirm('Create a case from this report?')"
                                                class="btn btn-primary btn-sm">
                                                <span wire:loading.remove wire:target="createCaseFromReport">Create Case</span>
                                                <span wire:loading wire:target="createCaseFromReport">Creating…</span>
                                            </button>
                                        @endif
                                        <button wire:click="retriageReport('{{ $report->id }}')"
                                            wire:loading.attr="disabled" wire:target="retriageReport"
                                            onclick="return confirm('Re-run AI triage on this report? This will spend tokens.')"
                                            class="btn btn-purple btn-sm">
                                            <span wire:loading.remove wire:target="retriageReport">Re-run AI Triage</span>
                                            <span wire:loading wire:target="retriageReport">Working…</span>
                                        </button>

                                        @if($editIpReportId === (string) $report->id)
                                            <form wire:submit.prevent="attachReportIp" style="display:flex; gap:6px; align-items:flex-start;">
                                                <div>
                                                    <input type="text" wire:model="manualReportIp"
                                                        placeholder="e.g. 192.0.2.10"
                                                        style="padding:4px 8px; border:1px solid #e5e7eb; border-radius:4px; font-size:12px; min-width:160px;">
                                                    @error('manualReportIp')
                                                        <div style="color:#dc2626; font-size:11px; margin-top:2px;">{{ $message }}</div>
                                                    @enderror
                                                </div>
                                                <button type="submit" class="btn btn-primary btn-sm"
                                                    wire:loading.attr="disabled" wire:target="attachReportIp">
                                                    <span wire:loading.remove wire:target="attachReportIp">Save IP</span>
                                                    <span wire:loading wire:target="attachReportIp">…</span>
                                                </button>
                                                <button type="button" wire:click="cancelReportIpForm" class="btn btn-secondary btn-sm">Cancel</button>
                                            </form>
                                        @else
                                            <button wire:click="openReportIpForm('{{ $report->id }}')" class="btn btn-secondary btn-sm">
                                                {{ $report->target_ip ? 'Edit Target IP' : 'Attach Target IP' }}
                                            </button>
                                        @endif
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                @empty
                    <tbody>
                        <x-empty-state colspan="12" title="No reports match these filters"
                            message="Adjust filters above, or wait for a feed/email/API report to land." />
                    </tbody>
                @endforelse
            </table>
        </div>
    </div>

    <div class="mt-4">{{ $reports->links() }}</div>
</div>
