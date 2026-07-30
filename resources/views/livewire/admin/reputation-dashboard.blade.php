<div>
    <x-flash-messages />

    {{-- KPIs --}}
    <div class="kpi-grid">
        <div class="kpi">
            <div class="kpi-label">Total Active</div>
            <div class="kpi-value">{{ number_format($stats['total']) }}</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Scanned</div>
            <div class="kpi-value" style="color:#2563eb;">{{ number_format($stats['scanned']) }}</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Good (80+)</div>
            <div class="kpi-value" style="color:#10b981;">{{ number_format($stats['good']) }}</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Fair (50-79)</div>
            <div class="kpi-value" style="color:#f59e0b;">{{ number_format($stats['fair']) }}</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Poor (30-49)</div>
            <div class="kpi-value" style="color:#f97316;">{{ number_format($stats['poor']) }}</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Critical (&lt;30)</div>
            <div class="kpi-value" style="color:#dc2626;">{{ number_format($stats['critical']) }}</div>
        </div>
    </div>

    {{-- Legend (collapsible, sits at top so users see how scores are derived) --}}
    <details style="margin-bottom:12px; background:#f9fafb; border:1px solid var(--border); border-radius:8px;">
        <summary style="cursor:pointer; padding:10px 14px; font-weight:600; font-size:13px; user-select:none;">How reputation works</summary>
        <div style="padding:0 14px 12px; font-size:13px; line-height:1.8;">
            <div><strong style="color:#10b981;">Good (80-100):</strong> Clean IP &mdash; no action needed</div>
            <div><strong style="color:#f59e0b;">Fair (50-79):</strong> Minor issues detected &mdash; monitor</div>
            <div><strong style="color:#f97316;">Poor (30-49):</strong> Multiple issues &mdash; investigate</div>
            <div><strong style="color:#dc2626;">Critical (&lt;30):</strong> Severe issues &mdash; case auto-created</div>
            <div class="text-muted" style="margin-top:8px; font-size:12px;">
                Score = 100 minus penalties from: AbuseIPDB (max &minus;50), DNSBL blacklists (max &minus;40), VirusTotal (max &minus;30), Shodan vulns (max &minus;25), open cases (max &minus;20). PBL/dynamic IP listings are informational and don't affect score.
            </div>
        </div>
    </details>

    {{-- Filters --}}
    <div class="filters" style="flex-wrap:wrap;">
        <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search IPs, servers..." style="min-width:0; flex:1; max-width:220px;">
        <select wire:model.live="filterReputation">
            <option value="">All Reputations</option>
            <option value="critical">Critical (&lt;30)</option>
            <option value="poor">Poor (30-49)</option>
            <option value="fair">Fair (50-79)</option>
            <option value="good">Good (80+)</option>
            <option value="unscanned">Not yet scanned</option>
        </select>
        <select wire:model.live="filterDnsbl">
            <option value="">All DNSBL</option>
            <option value="listed">Blacklisted</option>
            <option value="clean">Clean</option>
        </select>
        <select wire:model.live="filterSnds">
            <option value="">All SNDS</option>
            <option value="red">Red</option>
            <option value="yellow">Yellow</option>
            <option value="green">Green</option>
        </select>
        <select wire:model.live="filterAbuseipdb">
            <option value="">All AbuseIPDB</option>
            <option value="high">High (&gt;50%)</option>
            <option value="medium">Medium (1-50%)</option>
            <option value="clean">Clean (0%)</option>
        </select>
        @if($hasActiveFilters)
            <button wire:click="resetFilters" class="btn btn-secondary btn-sm" title="Clear all filters">
                Clear Filters
            </button>
        @endif
    </div>

    {{-- Detail Panel --}}
    @if($selectedIp)
        <div class="card" style="margin-bottom:16px; border-color:#2563eb; border-width:2px;">
            <div class="card-header" style="margin-bottom:12px;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <span class="card-title" style="font-family:monospace; font-size:16px;">{{ $selectedIp->ip_address }}</span>
                    @if($selectedIp->server_name)
                        <span class="text-muted text-sm">{{ $selectedIp->server_name }}</span>
                    @endif
                    @if($selectedIp->reputation_updated_at)
                        <span class="badge" style="background:{{ $selectedIp->reputation_color }}22; color:{{ $selectedIp->reputation_color }}; font-weight:600;">
                            {{ number_format((float) $selectedIp->reputation_score, 0) }}/100 &mdash; {{ $selectedIp->reputation_label }}
                        </span>
                    @endif
                </div>
                <button wire:click="closeDetail" class="btn btn-secondary btn-sm">Close</button>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px;" class="resp-grid-3">
                {{-- Customer Info --}}
                <div class="intel-card">
                    <div class="intel-card-title">Customer</div>
                    @if($selectedIp->customer)
                        <div style="font-size:13px;">
                            <div><strong>{{ $selectedIp->customer->company ?? $selectedIp->customer->email }}</strong></div>
                            <div class="text-muted">{{ $selectedIp->customer->email }}</div>
                            @if($selectedIp->customer->is_suspended)
                                <span class="badge badge-critical" style="margin-top:4px;">Suspended</span>
                            @endif
                        </div>
                    @else
                        <span class="text-muted text-sm">No customer linked</span>
                    @endif
                </div>

                {{-- Overall Score --}}
                <div class="intel-card">
                    <div class="intel-card-title">Reputation Score</div>
                    @if($selectedIp->reputation_updated_at)
                        <div style="display:flex; align-items:center; gap:10px;">
                            <div class="intel-stat" style="color:{{ $selectedIp->reputation_color }};">{{ number_format((float) $selectedIp->reputation_score, 1) }}</div>
                            <div>
                                <div style="width:100px; height:10px; background:#e5e7eb; border-radius:5px; overflow:hidden;">
                                    <div style="width:{{ (float) $selectedIp->reputation_score }}%; height:100%; background:{{ $selectedIp->reputation_color }}; border-radius:5px;"></div>
                                </div>
                                <div class="text-muted" style="font-size:11px; margin-top:2px;">Last scan: {{ $selectedIp->reputation_updated_at->diffForHumans() }}</div>
                            </div>
                        </div>
                    @else
                        <span class="text-muted text-sm">Not yet scanned</span>
                    @endif
                </div>

                {{-- Quick Actions --}}
                <div class="intel-card">
                    <div class="intel-card-title">Actions</div>
                    <div style="display:flex; gap:6px; flex-wrap:wrap;">
                        <button wire:click="scanIp('{{ $selectedIp->id }}')" class="btn btn-primary btn-sm"
                            wire:loading.attr="disabled" wire:target="scanIp('{{ $selectedIp->id }}')">
                            <span wire:loading.remove wire:target="scanIp('{{ $selectedIp->id }}')">Re-scan</span>
                            <span wire:loading wire:target="scanIp('{{ $selectedIp->id }}')">Scanning...</span>
                        </button>
                        @if($selectedIp->customer)
                            <a href="/admin/customers/{{ $selectedIp->customer->id }}" class="btn btn-secondary btn-sm">View Customer</a>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Detailed Breakdown --}}
            @php $details = $selectedIp->reputation_details ?? []; @endphp
            @if(!empty($details))
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:12px;" class="resp-grid-2">
                    {{-- AbuseIPDB Details --}}
                    <div class="intel-card">
                        <div class="intel-card-title">AbuseIPDB</div>
                        @php $abuseipdb = $details['abuseipdb'] ?? null; @endphp
                        @if($abuseipdb)
                            <div style="font-size:13px;">
                                <div style="display:flex; justify-content:space-between; margin-bottom:4px;">
                                    <span>Confidence Score</span>
                                    <strong style="color:{{ ($abuseipdb['score'] ?? 0) > 50 ? '#dc2626' : (($abuseipdb['score'] ?? 0) > 0 ? '#f59e0b' : '#10b981') }};">
                                        {{ $abuseipdb['score'] ?? 0 }}%
                                    </strong>
                                </div>
                                <div style="display:flex; justify-content:space-between; margin-bottom:4px;">
                                    <span>Total Reports</span>
                                    <strong>{{ $abuseipdb['reports'] ?? 0 }}</strong>
                                </div>
                                @if(isset($abuseipdb['penalty']))
                                    <div style="display:flex; justify-content:space-between; margin-bottom:4px;">
                                        <span>Score Penalty</span>
                                        <strong style="color:#dc2626;">-{{ $abuseipdb['penalty'] }}</strong>
                                    </div>
                                @endif
                                @if(isset($abuseipdb['last_reported']))
                                    <div style="display:flex; justify-content:space-between;">
                                        <span>Last Reported</span>
                                        <span class="text-muted">{{ $abuseipdb['last_reported'] }}</span>
                                    </div>
                                @endif
                            </div>
                        @else
                            <span class="text-muted text-sm">No data available</span>
                        @endif
                    </div>

                    {{-- DNSBL Details --}}
                    <div class="intel-card">
                        <div class="intel-card-title">DNSBL Blacklists</div>
                        @php $dnsbl = $details['dnsbl'] ?? null; @endphp
                        @if($dnsbl)
                            <div style="font-size:13px;">
                                <div style="display:flex; justify-content:space-between; margin-bottom:4px;">
                                    <span>Actionable Listings</span>
                                    <strong style="color:{{ ($dnsbl['actionable_count'] ?? 0) > 0 ? '#dc2626' : '#10b981' }};">
                                        {{ $dnsbl['actionable_count'] ?? 0 }}
                                    </strong>
                                </div>
                                @if(isset($dnsbl['penalty']))
                                    <div style="display:flex; justify-content:space-between; margin-bottom:4px;">
                                        <span>Score Penalty</span>
                                        <strong style="color:#dc2626;">-{{ $dnsbl['penalty'] }}</strong>
                                    </div>
                                @endif
                                @if(!empty($dnsbl['listed_on']))
                                    <div style="margin-top:6px;">
                                        <span class="text-muted" style="font-size:11px;">Listed on:</span>
                                        <div style="display:flex; flex-wrap:wrap; gap:4px; margin-top:3px;">
                                            @foreach($dnsbl['listed_on'] as $list)
                                                <span class="badge badge-critical">{{ $list }}</span>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                                @if(!empty($dnsbl['clean_on']))
                                    <div style="margin-top:6px;">
                                        <span class="text-muted" style="font-size:11px;">Clean on:</span>
                                        <div style="display:flex; flex-wrap:wrap; gap:4px; margin-top:3px;">
                                            @foreach(array_slice($dnsbl['clean_on'], 0, 6) as $list)
                                                <span class="badge badge-resolved">{{ $list }}</span>
                                            @endforeach
                                            @if(count($dnsbl['clean_on']) > 6)
                                                <span class="badge badge-closed">+{{ count($dnsbl['clean_on']) - 6 }} more</span>
                                            @endif
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @else
                            <span class="text-muted text-sm">No data available</span>
                        @endif
                    </div>

                    {{-- SNDS Details --}}
                    <div class="intel-card">
                        <div class="intel-card-title">Microsoft SNDS</div>
                        @php $snds = $details['snds'] ?? null; @endphp
                        @if($snds && ($snds['found'] ?? false))
                            <div style="font-size:13px;">
                                <div style="display:flex; justify-content:space-between; margin-bottom:4px;">
                                    <span>Filter Result</span>
                                    @php $filter = $snds['filter_result'] ?? 'unknown'; @endphp
                                    <strong style="color:{{ $filter === 'red' ? '#dc2626' : ($filter === 'yellow' ? '#f59e0b' : '#10b981') }};">
                                        {{ ucfirst($filter) }}
                                    </strong>
                                </div>
                                @if(isset($snds['blocked']))
                                    <div style="display:flex; justify-content:space-between; margin-bottom:4px;">
                                        <span>Blocked</span>
                                        <strong style="color:{{ $snds['blocked'] ? '#dc2626' : '#10b981' }};">{{ $snds['blocked'] ? 'Yes' : 'No' }}</strong>
                                    </div>
                                @endif
                                @if(isset($snds['trap_hits']))
                                    <div style="display:flex; justify-content:space-between; margin-bottom:4px;">
                                        <span>Spam Trap Hits</span>
                                        <strong>{{ $snds['trap_hits'] }}</strong>
                                    </div>
                                @endif
                                @if(isset($snds['sample_helo']))
                                    <div style="display:flex; justify-content:space-between; margin-bottom:4px;">
                                        <span>HELO</span>
                                        <span class="text-muted" style="font-size:11px; font-family:monospace;">{{ $snds['sample_helo'] }}</span>
                                    </div>
                                @endif
                                @if(isset($snds['penalty']))
                                    <div style="display:flex; justify-content:space-between;">
                                        <span>Score Penalty</span>
                                        <strong style="color:#dc2626;">-{{ $snds['penalty'] }}</strong>
                                    </div>
                                @endif
                            </div>
                        @else
                            <span class="text-muted text-sm">Not found in SNDS</span>
                        @endif
                    </div>

                    {{-- Open Cases --}}
                    <div class="intel-card">
                        <div class="intel-card-title">Open Cases</div>
                        @php $cases = $details['open_cases'] ?? null; @endphp
                        @if($cases)
                            <div style="font-size:13px;">
                                <div style="display:flex; justify-content:space-between; margin-bottom:4px;">
                                    <span>Open Cases</span>
                                    <strong style="color:{{ ($cases['count'] ?? 0) > 0 ? '#dc2626' : '#10b981' }};">{{ $cases['count'] ?? 0 }}</strong>
                                </div>
                                @if(isset($cases['penalty']))
                                    <div style="display:flex; justify-content:space-between; margin-bottom:4px;">
                                        <span>Score Penalty</span>
                                        <strong style="color:#dc2626;">-{{ $cases['penalty'] }}</strong>
                                    </div>
                                @endif
                                @if(!empty($cases['case_numbers']))
                                    <div style="margin-top:6px;">
                                        <span class="text-muted" style="font-size:11px;">Cases:</span>
                                        <div style="display:flex; flex-wrap:wrap; gap:4px; margin-top:3px;">
                                            @foreach($cases['case_numbers'] as $caseNumber)
                                                <a href="/admin/cases" class="badge badge-open" style="text-decoration:none;">{{ $caseNumber }}</a>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @else
                            <span class="text-muted text-sm">No case data</span>
                        @endif
                    </div>
                </div>

                {{-- Additional details: VirusTotal, Shodan if present --}}
                @if(isset($details['virustotal']) || isset($details['shodan']))
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:12px;" class="resp-grid-2">
                        @if(isset($details['virustotal']))
                            <div class="intel-card">
                                <div class="intel-card-title">VirusTotal</div>
                                @php $vt = $details['virustotal']; @endphp
                                <div style="font-size:13px;">
                                    @if(isset($vt['detections']))
                                        <div style="display:flex; justify-content:space-between; margin-bottom:4px;">
                                            <span>Detections</span>
                                            <strong style="color:{{ $vt['detections'] > 0 ? '#dc2626' : '#10b981' }};">{{ $vt['detections'] }}</strong>
                                        </div>
                                    @endif
                                    @if(isset($vt['penalty']))
                                        <div style="display:flex; justify-content:space-between;">
                                            <span>Score Penalty</span>
                                            <strong style="color:#dc2626;">-{{ $vt['penalty'] }}</strong>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endif
                        @if(isset($details['shodan']))
                            <div class="intel-card">
                                <div class="intel-card-title">Shodan</div>
                                @php $shodan = $details['shodan']; @endphp
                                <div style="font-size:13px;">
                                    @if(isset($shodan['vulns']))
                                        <div style="display:flex; justify-content:space-between; margin-bottom:4px;">
                                            <span>Vulnerabilities</span>
                                            <strong style="color:{{ $shodan['vulns'] > 0 ? '#dc2626' : '#10b981' }};">{{ $shodan['vulns'] }}</strong>
                                        </div>
                                    @endif
                                    @if(isset($shodan['penalty']))
                                        <div style="display:flex; justify-content:space-between;">
                                            <span>Score Penalty</span>
                                            <strong style="color:#dc2626;">-{{ $shodan['penalty'] }}</strong>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </div>
                @endif
            @else
                <div style="margin-top:12px; text-align:center; padding:20px; color:#6b7280; font-size:13px;">
                    No detailed reputation data available. Click <strong>Re-scan</strong> to collect data.
                </div>
            @endif
        </div>
    @endif

    {{-- Table --}}
    <div class="card" style="padding:0; overflow-x:auto;">
        <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <x-sortable-th column="ip_address" :sortBy="$sortField" :sortDir="$sortDirection">IP Address</x-sortable-th>
                    <x-sortable-th column="server_name" :sortBy="$sortField" :sortDir="$sortDirection">Server</x-sortable-th>
                    <x-sortable-th column="reputation_score" :sortBy="$sortField" :sortDir="$sortDirection">Reputation</x-sortable-th>
                    <x-sortable-th column="abuseipdb_score" :sortBy="$sortField" :sortDir="$sortDirection">AbuseIPDB</x-sortable-th>
                    <th>DNSBL</th>
                    <th>SNDS</th>
                    <th>Cases</th>
                    <x-sortable-th column="reputation_updated_at" :sortBy="$sortField" :sortDir="$sortDirection">Last Scan</x-sortable-th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($ips as $ip)
                    <tr style="{{ $selectedIpId === $ip->id ? 'background:#eff6ff;' : '' }}">
                        <td style="font-family:monospace; font-weight:500;">{{ $ip->ip_address }}</td>
                        <td class="text-sm">{{ $ip->server_name ?? '-' }}</td>
                        <td>
                            @if($ip->reputation_updated_at)
                                @php $score = (float) $ip->reputation_score; @endphp
                                <div style="display:flex; align-items:center; gap:6px;">
                                    <div style="width:60px; height:8px; background:#e5e7eb; border-radius:4px; overflow:hidden;">
                                        <div style="width:{{ $score }}%; height:100%; background:{{ $ip->reputation_color }}; border-radius:4px;"></div>
                                    </div>
                                    <span style="font-weight:600; color:{{ $ip->reputation_color }}; font-size:13px;">{{ number_format($score, 0) }}</span>
                                    <span class="text-muted" style="font-size:11px;">{{ $ip->reputation_label }}</span>
                                </div>
                            @else
                                <span class="text-muted">&mdash;</span>
                            @endif
                        </td>
                        <td class="text-sm">
                            @if($ip->abuseipdb_score !== null)
                                <span style="color:{{ $ip->abuseipdb_score > 50 ? '#dc2626' : ($ip->abuseipdb_score > 0 ? '#f59e0b' : '#10b981') }};">
                                    {{ $ip->abuseipdb_score }}%
                                </span>
                            @else
                                <span class="text-muted">&mdash;</span>
                            @endif
                        </td>
                        <td class="text-sm">
                            @php
                                $dnsbl = $ip->reputation_details['dnsbl'] ?? null;
                                $actionable = $dnsbl['actionable_count'] ?? 0;
                            @endphp
                            @if($dnsbl)
                                @if($actionable > 0)
                                    <span style="color:#dc2626; font-weight:600;">{{ $actionable }} lists</span>
                                @else
                                    <span style="color:#10b981;">Clean</span>
                                @endif
                            @else
                                <span class="text-muted">&mdash;</span>
                            @endif
                        </td>
                        <td class="text-sm">
                            @php $snds = $ip->reputation_details['snds'] ?? null; @endphp
                            @if($snds && ($snds['found'] ?? false))
                                @php $filter = $snds['filter_result'] ?? 'unknown'; @endphp
                                <span style="color:{{ $filter === 'red' ? '#dc2626' : ($filter === 'yellow' ? '#f59e0b' : '#10b981') }};">
                                    {{ ucfirst($filter) }}
                                </span>
                                @if($snds['blocked'] ?? false) <span class="badge badge-critical" style="font-size:9px;">Blocked</span> @endif
                                @if(($snds['trap_hits'] ?? 0) > 0) <span class="text-muted">({{ $snds['trap_hits'] }} traps)</span> @endif
                            @else
                                <span class="text-muted">&mdash;</span>
                            @endif
                        </td>
                        <td class="text-sm">
                            @php $openCases = $ip->reputation_details['open_cases']['count'] ?? 0; @endphp
                            @if($openCases > 0)
                                <span style="color:#dc2626;">{{ $openCases }}</span>
                            @else
                                0
                            @endif
                            @if($ip->case_created_from_reputation)
                                <span class="badge badge-closed" style="font-size:9px;">auto</span>
                            @endif
                        </td>
                        <td class="text-sm text-muted">{{ $ip->reputation_updated_at?->diffForHumans() ?? 'Never' }}</td>
                        <td>
                            <div style="display:flex; gap:6px;">
                                <button wire:click="showDetail('{{ $ip->id }}')" class="btn btn-secondary btn-sm" aria-label="Show reputation details for {{ $ip->ip_address }}">
                                    {{ $selectedIpId === $ip->id ? 'Hide' : 'Details' }}
                                </button>
                                <button wire:click="scanIp('{{ $ip->id }}')" class="btn btn-secondary btn-sm"
                                    wire:loading.attr="disabled" wire:target="scanIp('{{ $ip->id }}')">
                                    <span wire:loading.remove wire:target="scanIp('{{ $ip->id }}')">Scan</span>
                                    <span wire:loading wire:target="scanIp('{{ $ip->id }}')">…</span>
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" style="text-align:center; padding:2rem; color:#6b7280;">No IPs found matching your filters</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    <div class="mt-4">{{ $ips->links() }}</div>


</div>
