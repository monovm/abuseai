<div>
    @if(! $isConfigured)
        <div style="background:#fef3c7; border:1px solid #f59e0b; color:#92400e; padding:1rem; border-radius:6px; margin-bottom:1rem;">
            <strong>AbuseIPDB API key not configured.</strong> Add <code>ABUSEIPDB_KEY=your_key</code> to your .env file.
            Get a free API key at <a href="https://www.abuseipdb.com" target="_blank" rel="noopener noreferrer" style="color:#92400e; text-decoration:underline; font-weight:600;">abuseipdb.com</a>.
        </div>
    @endif

    <x-flash-messages />

    {{-- KPIs --}}
    <div class="kpi-grid" style="margin-bottom:1rem;">
        <div class="kpi">
            <div class="kpi-label">Active IPs</div>
            <div class="kpi-value">{{ $totalActive }}</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Checked</div>
            <div class="kpi-value" style="color:var(--primary);">{{ $totalChecked }}</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Reported</div>
            <div class="kpi-value" style="color:var(--danger);">{{ $totalReported }}</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Clean</div>
            <div class="kpi-value" style="color:var(--success);">{{ $totalClean }}</div>
        </div>
    </div>

    {{-- Quick Check --}}
    <div class="card">
        <div class="card-title" style="margin-bottom:0.75rem;">Quick IP Check</div>
        <div style="display:flex; gap:0.5rem; align-items:start;">
            <div style="flex:1;">
                <input type="text" wire:model="singleIp" placeholder="Enter any IPv4 or IPv6 (e.g. 1.2.3.4 or 2001:db8::1)" style="width:100%; padding:0.375rem 0.625rem; border:1px solid var(--border); border-radius:6px; font-size:0.8125rem; font-family:monospace;">
                @error('singleIp') <div style="color:#dc2626; font-size:0.75rem; margin-top:0.25rem;">{{ $message }}</div> @enderror
            </div>
            <button wire:click="checkSingleIp" class="btn btn-primary btn-sm" {{ ! $isConfigured ? 'disabled' : '' }}>
                <span wire:loading.remove wire:target="checkSingleIp">Check IP</span>
                <span wire:loading wire:target="checkSingleIp">Checking...</span>
            </button>
        </div>

        @if($singleResult)
            <div style="margin-top:1rem; padding:1rem; background:#f9fafb; border-radius:6px; font-size:0.8125rem;">
                <div class="resp-grid-4" style="display:grid; grid-template-columns:repeat(4, 1fr); gap:1rem; margin-bottom:0.75rem;">
                    <div>
                        <div class="text-muted" style="font-size:0.6875rem; text-transform:uppercase;">Score</div>
                        <div style="font-size:1.25rem; font-weight:700; color:{{ $singleResult['abuseConfidenceScore'] > 50 ? 'var(--danger)' : ($singleResult['abuseConfidenceScore'] > 0 ? 'var(--warning)' : 'var(--success)') }}">
                            {{ $singleResult['abuseConfidenceScore'] }}%
                        </div>
                    </div>
                    <div>
                        <div class="text-muted" style="font-size:0.6875rem; text-transform:uppercase;">Reports</div>
                        <div style="font-weight:600;">{{ $singleResult['totalReports'] ?? 0 }}</div>
                    </div>
                    <div>
                        <div class="text-muted" style="font-size:0.6875rem; text-transform:uppercase;">Country</div>
                        <div>{{ $singleResult['countryCode'] ?? 'N/A' }}</div>
                    </div>
                    <div>
                        <div class="text-muted" style="font-size:0.6875rem; text-transform:uppercase;">ISP</div>
                        <div>{{ $singleResult['isp'] ?? 'N/A' }}</div>
                    </div>
                </div>
                <div class="resp-grid-3" style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:1rem;">
                    <div>
                        <div class="text-muted" style="font-size:0.6875rem; text-transform:uppercase;">Usage Type</div>
                        <div>{{ $singleResult['usageType'] ?? 'N/A' }}</div>
                    </div>
                    <div>
                        <div class="text-muted" style="font-size:0.6875rem; text-transform:uppercase;">Domain</div>
                        <div>{{ $singleResult['domain'] ?? 'N/A' }}</div>
                    </div>
                    <div>
                        <div class="text-muted" style="font-size:0.6875rem; text-transform:uppercase;">Last Reported</div>
                        <div>{{ $singleResult['lastReportedAt'] ?? 'Never' }}</div>
                    </div>
                </div>
            </div>
        @endif
    </div>

    {{-- Inventory Check --}}
    <div class="card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.75rem;">
            <div class="card-title">IP Inventory — AbuseIPDB Status</div>
            <div class="text-sm text-muted">
                Bulk scans run on a schedule. Click <strong>Re-scan</strong> on a row below to refresh that IP now.
            </div>
        </div>

        <div class="filters">
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search IPs..." style="min-width:0; flex:1; max-width:300px;">
            <select wire:model.live="filterScore">
                <option value="">All IPs</option>
                <option value="reported">Reported (score > 0)</option>
                <option value="clean">Clean (score = 0)</option>
                <option value="unchecked">Not yet checked</option>
            </select>
        </div>

        <div style="padding:0; overflow:hidden; border:1px solid var(--border); border-radius:8px;">
            <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>IP Address</th>
                        <th>Server</th>
                        <th>Customer</th>
                        <th>AbuseIPDB Score</th>
                        <th>Reports</th>
                        <th>Last Checked</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($ips as $ip)
                        <tr>
                            <td style="font-family:monospace; font-weight:500;">{{ $ip->ip_address }}</td>
                            <td>{{ $ip->server_name ?? '-' }}</td>
                            <td class="text-sm">{{ $ip->customer?->email ?? '-' }}</td>
                            <td>
                                @if($ip->abuseipdb_checked_at)
                                    @php $score = $ip->abuseipdb_score ?? 0; @endphp
                                    <span style="font-weight:600; color:{{ $score > 50 ? 'var(--danger)' : ($score > 0 ? 'var(--warning)' : 'var(--success)') }}">
                                        {{ $score }}%
                                    </span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                @if($ip->abuseipdb_checked_at)
                                    {{ $ip->abuseipdb_reports ?? 0 }}
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-sm text-muted">
                                {{ $ip->abuseipdb_checked_at?->diffForHumans() ?? 'Never' }}
                            </td>
                            <td>
                                <div class="flex gap-2">
                                    <button wire:click="checkIp('{{ $ip->id }}')" class="btn btn-secondary btn-sm" {{ ! $isConfigured ? 'disabled' : '' }}
                                        wire:loading.attr="disabled" wire:target="checkIp('{{ $ip->id }}')">
                                        <span wire:loading.remove wire:target="checkIp('{{ $ip->id }}')">Check</span>
                                        <span wire:loading wire:target="checkIp('{{ $ip->id }}')">...</span>
                                    </button>
                                    @if(($ip->abuseipdb_score ?? 0) > 0)
                                        <button wire:click="createCaseForIp('{{ $ip->id }}')" class="btn btn-danger btn-sm" onclick="return confirm('Create abuse case for {{ $ip->ip_address }}?')">
                                            Case
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" style="text-align:center; padding:2rem; color:var(--text-muted);">No IPs found</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </div>

        <div class="mt-4">{{ $ips->links() }}</div>
    </div>
</div>
