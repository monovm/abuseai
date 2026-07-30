<div>
    @if(session('reporter-message'))
        <div style="background:#d1fae5; color:#065f46; padding:8px 12px; border-radius:6px; font-size:13px; margin-bottom:12px;">
            {{ session('reporter-message') }}
        </div>
    @endif

    <div class="filters" style="margin-bottom:12px;">
        <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search reporters…" style="min-width:240px;">
        <select wire:model.live="filter">
            <option value="">All reporters</option>
            <option value="law_enforcement">Law enforcement</option>
            <option value="trusted">Trusted</option>
            <option value="blocked">Blocked</option>
        </select>
    </div>

    <details style="margin-bottom:12px;">
        <summary style="cursor:pointer; font-size:12px; color:#6b7280;">
            Law enforcement auto-tag domains ({{ count($leDomains) }})
        </summary>
        <div style="margin-top:6px; font-size:11px; color:#6b7280;">
            Reporters whose email domain matches any of these patterns are auto-tagged on their next report. Edit via
            <code>config/abusedesk.php</code> or the <code>LAW_ENFORCEMENT_DOMAINS</code> env variable.
            <div style="margin-top:6px; display:flex; flex-wrap:wrap; gap:4px;">
                @foreach($leDomains as $d)
                    <code style="background:#f3f4f6; padding:2px 6px; border-radius:4px; font-size:11px;">{{ $d }}</code>
                @endforeach
            </div>
        </div>
    </details>

    <div class="card" style="padding:0; overflow:hidden;">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Type</th>
                        <th>Reputation</th>
                        <th title="Reports (live) · Follow-ups · Ingested (counter)">Messages</th>
                        <th title="Verified law-enforcement reporter (auto-tagged from configured domains, or manually marked).">Law&nbsp;Enforcement</th>
                        <th title="Trusted reporters bypass the AI noise filter and their reports are weighted higher.">Trusted</th>
                        <th title="Blocked reporters' reports are rejected at ingestion.">Blocked</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($reporters as $r)
                        <tr>
                            <td>{{ $r->name ?: '-' }}</td>
                            <td class="text-sm">{{ $r->email }}</td>
                            <td><span class="badge badge-open">{{ $r->type }}</span></td>
                            <td>{{ number_format($r->reputation_score, 1) }}</td>
                            <td class="text-sm" style="white-space:nowrap;">
                                @php
                                    $followups = (int) ($followupCounts[$r->email] ?? 0);
                                    $live = (int) $r->reports_count;
                                    $stored = (int) $r->report_count;
                                    $totalTouchpoints = $live + $followups;
                                    $missing = max(0, $stored - $live);
                                @endphp
                                <a href="{{ route('admin.reports.index', ['reporter' => $r->id]) }}"
                                   style="color:#2563eb; text-decoration:none; font-weight:600;"
                                   title="{{ $live }} abuse_reports rows from {{ $r->email }}">
                                    {{ number_format($live) }}
                                </a>
                                <span class="text-muted" style="font-size:11px;">reports</span>
                                @if($followups > 0)
                                    &middot; <span title="Follow-up replies stored as case actions" style="color:#7c3aed;">{{ number_format($followups) }}</span>
                                    <span class="text-muted" style="font-size:11px;">follow-ups</span>
                                @endif
                                @if($missing > 0)
                                    <div style="font-size:11px; color:#b45309; margin-top:2px;"
                                         title="The stored counter reports {{ number_format($stored) }} ingested; {{ number_format($missing) }} are not currently in abuse_reports. Likely cause: duplicate reporter rows or historical rows pruned.">
                                        ⚠ {{ number_format($stored) }} ingested (Δ {{ number_format($missing) }})
                                    </div>
                                @elseif($stored > 0 && $live === 0 && $followups === 0)
                                    <div style="font-size:11px; color:#6b7280; margin-top:2px;">
                                        counter: {{ number_format($stored) }}
                                    </div>
                                @endif
                            </td>
                            <td>
                                @if($r->is_law_enforcement)
                                    <button wire:click="toggleLawEnforcement('{{ $r->id }}')"
                                        aria-pressed="true"
                                        onclick="return confirm('Remove law-enforcement flag from {{ addslashes($r->email) }}?')"
                                        class="badge"
                                        style="border:none; cursor:pointer; background:#ede9fe; color:#5b21b6; display:inline-flex; align-items:center; gap:4px;"
                                        title="Verified law-enforcement reporter — click to remove">
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2 4 5v6c0 5 3.5 9.5 8 11 4.5-1.5 8-6 8-11V5l-8-3z"/></svg>
                                        Law Enforcement
                                    </button>
                                @else
                                    <button wire:click="toggleLawEnforcement('{{ $r->id }}')"
                                        aria-pressed="false"
                                        onclick="return confirm('Mark {{ addslashes($r->email) }} as law enforcement? Their reports will receive priority handling.')"
                                        class="badge-mark"
                                        title="Mark as law enforcement">
                                        + Mark
                                    </button>
                                @endif
                            </td>
                            <td>
                                @if($r->is_trusted)
                                    <button wire:click="toggleTrusted('{{ $r->id }}')"
                                        aria-pressed="true"
                                        onclick="return confirm('Remove trusted status from {{ addslashes($r->email) }}?')"
                                        class="badge badge-resolved"
                                        style="border:none; cursor:pointer;"
                                        title="Trusted reporter — click to remove">
                                        Trusted
                                    </button>
                                @else
                                    <button wire:click="toggleTrusted('{{ $r->id }}')"
                                        aria-pressed="false"
                                        onclick="return confirm('Mark {{ addslashes($r->email) }} as trusted? Their reports will bypass the noise filter.')"
                                        class="badge-mark"
                                        title="Mark as trusted">
                                        + Mark
                                    </button>
                                @endif
                            </td>
                            <td>
                                @if($r->is_blocked)
                                    <button wire:click="toggleBlocked('{{ $r->id }}')"
                                        aria-pressed="true"
                                        onclick="return confirm('Unblock {{ addslashes($r->email) }}? Their reports will be processed again.')"
                                        class="badge badge-critical"
                                        style="border:none; cursor:pointer;"
                                        title="Blocked — click to unblock">
                                        Blocked
                                    </button>
                                @else
                                    <button wire:click="toggleBlocked('{{ $r->id }}')"
                                        aria-pressed="false"
                                        onclick="return confirm('Block reporter {{ addslashes($r->email) }}? All future reports from them will be rejected.')"
                                        class="badge-mark"
                                        title="Block reporter">
                                        + Block
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" style="text-align:center; padding:2rem; color:var(--text-muted);">No reporters</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-4">{{ $reporters->links() }}</div>
</div>
