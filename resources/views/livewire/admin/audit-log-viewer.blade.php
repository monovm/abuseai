<div>
    <div class="kpi-grid">
        <div class="kpi">
            <div class="kpi-label">Total Entries</div>
            <div class="kpi-value">{{ number_format($totalLogs) }}</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">AI Calls</div>
            <div class="kpi-value" style="color:#5b21b6;">{{ number_format($totalAiCalls) }}</div>
        </div>
    </div>

    <div class="filters">
        <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search logs..." style="min-width:0; flex:1; max-width:300px;">
        <select wire:model.live="filterEntity">
            <option value="">All Entity Types</option>
            @foreach($entityTypes as $type)
                <option value="{{ $type }}">{{ $type }}</option>
            @endforeach
        </select>
        <select wire:model.live="filterEvent">
            <option value="">All Events</option>
            @foreach($events as $event)
                <option value="{{ $event }}">{{ $event }}</option>
            @endforeach
        </select>
        <label style="font-size:13px; display:flex; align-items:center; gap:4px;">
            <input type="checkbox" wire:model.live="onlyAiCalls"> AI Calls Only
        </label>
    </div>

    <div class="card" style="padding:0; overflow:hidden;">
        <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Entity</th>
                    <th>Event</th>
                    <th>Changes</th>
                    <th>Actor</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>
                @forelse($logs as $log)
                    <tr>
                        <td style="white-space:nowrap;" class="text-sm text-muted">{{ $log->created_at->format('M j, g:ia') }}</td>
                        <td>
                            <span class="badge badge-open">{{ $log->entity_type }}</span>
                            <div class="text-sm text-muted" style="font-family:monospace; font-size:10px; margin-top:2px;">{{ Str::limit($log->entity_id, 12) }}</div>
                        </td>
                        <td>
                            <strong>{{ $log->event }}</strong>
                            @if($log->ai_prompt)
                                <span class="badge" style="background:#ede9fe; color:#5b21b6; margin-left:4px;">AI</span>
                            @endif
                        </td>
                        <td class="text-sm" style="max-width:300px;">
                            @if($log->ai_prompt)
                                <details x-data="{ full: false }">
                                    <summary style="cursor:pointer; color:#5b21b6; font-size:12px;">View AI prompt/response</summary>
                                    <div style="margin-top:8px;">
                                        <div style="font-size:10px; color:#5b21b6; text-transform:uppercase; margin-bottom:2px; display:flex; justify-content:space-between; align-items:center;">
                                            <span>Prompt</span>
                                            <button x-on:click.prevent="full = !full" style="background:none; border:none; color:#5b21b6; font-size:10px; cursor:pointer; text-decoration:underline;" x-text="full ? 'Show less' : 'Show full'"></button>
                                        </div>
                                        <div style="padding:8px; background:#f9fafb; border-radius:4px; font-family:monospace; font-size:11px; max-height:200px; overflow-y:auto; white-space:pre-wrap;" :style="full && 'max-height: 600px;'">
                                            <span x-show="!full">{{ Str::limit($log->ai_prompt, 500) }}</span>
                                            <span x-show="full" x-cloak>{{ $log->ai_prompt }}</span>
                                        </div>
                                    </div>
                                    @if($log->ai_response)
                                        <div style="margin-top:4px;">
                                            <div style="font-size:10px; color:#065f46; text-transform:uppercase; margin-bottom:2px;">Response</div>
                                            <div style="padding:8px; background:#f0fdf4; border-radius:4px; font-family:monospace; font-size:11px; max-height:200px; overflow-y:auto; white-space:pre-wrap;" :style="full && 'max-height: 600px;'">
                                                <span x-show="!full">{{ Str::limit($log->ai_response, 500) }}</span>
                                                <span x-show="full" x-cloak>{{ $log->ai_response }}</span>
                                            </div>
                                        </div>
                                    @endif
                                </details>
                            @elseif($log->old_values || $log->new_values)
                                <details>
                                    <summary style="cursor:pointer; color:#2563eb; font-size:12px;">View changes</summary>
                                    @if($log->old_values)
                                        <div style="margin-top:8px;">
                                            <div style="font-size:10px; color:#dc2626; text-transform:uppercase; margin-bottom:2px;">Old</div>
                                            <div style="padding:6px; background:#fef2f2; border-radius:4px; font-family:monospace; font-size:11px; max-height:150px; overflow-y:auto;">
                                                @foreach($log->old_values as $key => $value)
                                                    <div><strong>{{ $key }}:</strong> {{ is_array($value) ? json_encode($value) : $value }}</div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                    @if($log->new_values)
                                        <div style="margin-top:4px;">
                                            <div style="font-size:10px; color:#059669; text-transform:uppercase; margin-bottom:2px;">New</div>
                                            <div style="padding:6px; background:#f0fdf4; border-radius:4px; font-family:monospace; font-size:11px; max-height:150px; overflow-y:auto;">
                                                @foreach($log->new_values as $key => $value)
                                                    <div><strong>{{ $key }}:</strong> {{ is_array($value) ? json_encode($value) : $value }}</div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                </details>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="text-sm text-muted">{{ $log->actor?->name ?? ($log->actor_id ? 'Deleted user' : 'System') }}</td>
                        <td class="text-sm text-muted" style="font-family:monospace;">{{ $log->ip_address ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="text-align:center; padding:2rem; color:#6b7280;">No audit log entries</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    <div class="mt-4">{{ $logs->links() }}</div>
</div>
