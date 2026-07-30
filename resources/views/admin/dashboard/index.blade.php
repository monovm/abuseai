@extends('layouts.admin')
@section('header', 'Dashboard')
@section('title', 'Dashboard - ' . config('app.name', 'Abuse AI'))

@section('content')
    {{-- KPI Cards --}}
    <style>
        .kpi a, a.kpi { color:inherit; text-decoration:none; display:block; transition:transform .08s ease, box-shadow .08s ease; }
        a.kpi:hover, .card-link:hover { transform:translateY(-1px); box-shadow:0 4px 10px rgba(0,0,0,0.06); }
        a.card-link { color:inherit; text-decoration:none; display:block; }
    </style>
    <div class="kpi-grid">
        <a class="kpi" href="{{ route('admin.cases.index', ['status' => 'open']) }}">
            <div class="kpi-label">Open Cases</div>
            <div class="kpi-value" style="color:#2563eb;">{{ number_format($openCases) }}</div>
        </a>
        <a class="kpi" href="{{ route('admin.cases.index', ['severity' => 'critical']) }}">
            <div class="kpi-label">Critical</div>
            <div class="kpi-value" style="color:#dc2626;">{{ number_format($criticalCases) }}</div>
        </a>
        <a class="kpi" href="{{ route('admin.cases.index', ['sla' => 'breached']) }}">
            <div class="kpi-label">SLA Breached</div>
            <div class="kpi-value" style="color:{{ $slaBreached > 0 ? '#dc2626' : '#10b981' }};">{{ number_format($slaBreached) }}</div>
        </a>
        <a class="kpi" href="{{ route('admin.reports.index') }}">
            <div class="kpi-label">Today's Reports</div>
            <div class="kpi-value">{{ number_format($todayReports) }}</div>
        </a>
        <a class="kpi" href="{{ route('admin.cases.index') }}">
            <div class="kpi-label">Total Cases</div>
            <div class="kpi-value">{{ number_format($totalCases) }}</div>
        </a>
        <a class="kpi" href="{{ route('admin.reports.index') }}">
            <div class="kpi-label">Total Reports</div>
            <div class="kpi-value">{{ number_format($totalReports) }}</div>
        </a>
        <a class="kpi" href="{{ route('admin.reports.index', ['status' => 'unprocessed']) }}"
           title="Reports that reached no terminal outcome — investigate">
            <div class="kpi-label">Unprocessed</div>
            <div class="kpi-value" style="color:{{ $unprocessedReports > 0 ? '#dc2626' : '#10b981' }};">{{ number_format($unprocessedReports) }}</div>
        </a>
        <a class="kpi" href="{{ route('admin.customers.index') }}">
            <div class="kpi-label">Suspended</div>
            <div class="kpi-value" style="color:#f59e0b;">{{ number_format($suspendedCustomers) }}</div>
        </a>
    </div>

    {{-- IP Inventory & Reputation Row --}}
    <div class="resp-grid-3" style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:12px; margin-bottom:16px;">
        <a class="card card-link" href="{{ route('admin.ips.index') }}" style="margin-bottom:0;">
            <div class="card-title" style="margin-bottom:8px;">IP Inventory</div>
            <div style="font-size:28px; font-weight:700;">{{ number_format($totalIps) }}</div>
            <div style="font-size:12px; color:#6b7280; margin-top:4px;">
                <span style="color:#10b981;">{{ number_format($cleanIps) }} clean</span>
                &middot;
                <span style="color:#dc2626;">{{ number_format($flaggedIps) }} flagged</span>
            </div>
        </a>
        <a class="card card-link" href="{{ route('admin.reputation.index') }}" style="margin-bottom:0;">
            <div class="card-title" style="margin-bottom:8px;">Overall Reputation</div>
            @php
                $repScore = round($avgReputation ?? 100);
                $repColor = $repScore >= 80 ? '#10b981' : ($repScore >= 50 ? '#f59e0b' : '#dc2626');
                $repLabel = $repScore >= 80 ? 'Good' : ($repScore >= 50 ? 'Fair' : 'Poor');
            @endphp
            <div style="font-size:28px; font-weight:700; color:{{ $repColor }};">{{ $repScore }}/100</div>
            <div style="font-size:12px; color:#6b7280; margin-top:4px;">{{ $repLabel }} &mdash; avg across {{ number_format($totalIps) }} IPs</div>
        </a>
        <div class="card" style="margin-bottom:0;">
            <div class="card-title" style="margin-bottom:8px;">Top Flagged IPs</div>
            @if(count($topIps) > 0)
                @foreach(array_slice($topIps, 0, 3, true) as $ip => $count)
                    <a href="{{ route('admin.cases.index', ['q' => $ip]) }}" style="font-size:12px; display:flex; justify-content:space-between; padding:2px 0; gap:8px; text-decoration:none; color:inherit;">
                        <code style="font-size:11px; word-break:break-all; color:#2563eb;">{{ $ip }}</code>
                        <span class="badge badge-high" style="white-space:nowrap;">{{ $count }} cases</span>
                    </a>
                @endforeach
            @else
                <div style="font-size:12px; color:#6b7280;">No flagged IPs</div>
            @endif
        </div>
    </div>

    {{-- Charts Row --}}
    <div class="resp-grid-2" style="display:grid; grid-template-columns:2fr 1fr; gap:12px; margin-bottom:16px;">
        <div class="card" style="margin-bottom:0;">
            <div class="card-title" style="margin-bottom:8px;">Daily Cases &amp; Reports (Last 14 Days)</div>
            <div style="position:relative; height:200px;"><canvas id="chartDailyCases"></canvas></div>
        </div>
        <div class="card" style="margin-bottom:0;">
            <div class="card-title" style="margin-bottom:8px;">Cases by Brand</div>
            @if(count($casesByBrandLabeled) > 0)
                <div style="position:relative; height:200px;"><canvas id="chartByBrand"></canvas></div>
            @else
                <div style="text-align:center; color:#6b7280; padding:40px 0; font-size:13px;">No brand data</div>
            @endif
        </div>
    </div>

    <div class="resp-grid-2" style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:16px;">
        <div class="card" style="margin-bottom:0;">
            <div class="card-title" style="margin-bottom:8px;">Cases by Type</div>
            <div style="position:relative; height:220px;"><canvas id="chartByType"></canvas></div>
        </div>
        <div class="card" style="margin-bottom:0;">
            <div class="card-title" style="margin-bottom:8px;">Cases by Severity</div>
            <div style="position:relative; height:220px;"><canvas id="chartBySeverity"></canvas></div>
        </div>
    </div>

    {{-- Recent Cases --}}
    <div class="card">
        <div class="card-header">
            <div class="card-title">Recent Cases</div>
            <a href="{{ route('admin.cases.index') }}" class="btn btn-secondary btn-sm">View All</a>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Case #</th>
                        <th>Type</th>
                        <th>Severity</th>
                        <th>Status</th>
                        <th>Target IP</th>
                        <th>Reports</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recentCases as $case)
                        <tr>
                            <td style="white-space:nowrap;">
                                <a href="{{ route('admin.cases.show', $case) }}" style="color:#2563eb; text-decoration:none; font-weight:500;">
                                    {{ $case->case_number }}
                                </a>
                            </td>
                            <td>{{ $case->abuse_type?->value ?? '-' }}</td>
                            <td>
                                @if($case->severity_level)
                                    <span class="badge badge-{{ $case->severity_level->value }}">{{ ucfirst($case->severity_level->value) }}</span>
                                @else
                                    -
                                @endif
                            </td>
                            <td>
                                @if($case->status)
                                    <span class="badge badge-{{ $case->status->value }}">{{ ucfirst($case->status->value) }}</span>
                                @else
                                    -
                                @endif
                            </td>
                            <td><code style="font-size:11px;">{{ $case->target_ip ?? '-' }}</code></td>
                            <td>{{ $case->report_count }}</td>
                            <td style="white-space:nowrap; font-size:12px; color:#6b7280;">{{ $case->created_at->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" style="text-align:center; color:#6b7280; padding:24px;">No cases yet</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Chart.js: prefer the bundled copy from resources/js/app.js (window.Chart);
         fall back to the CDN if the bundle hasn't been rebuilt on this deploy. --}}
    <script>
        if (typeof Chart === 'undefined') {
            var s = document.createElement('script');
            s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4';
            s.onload = function () { window.dispatchEvent(new Event('chart-ready')); };
            document.head.appendChild(s);
        } else {
            window.dispatchEvent(new Event('chart-ready'));
        }
    </script>
    <script>
        window.addEventListener('chart-ready', function () {
        const colors = ['#2563eb','#dc2626','#f59e0b','#10b981','#8b5cf6','#ec4899','#06b6d4','#84cc16','#f97316','#6366f1'];
        const severityColors = { low: '#3b82f6', medium: '#f59e0b', high: '#f97316', critical: '#dc2626' };

        new Chart(document.getElementById('chartDailyCases'), {
            type: 'bar',
            data: {
                labels: {!! json_encode($dailyLabels) !!},
                datasets: [
                    {
                        label: 'Reports',
                        data: {!! json_encode($dailyReports) !!},
                        backgroundColor: '#f59e0b',
                        borderRadius: 4,
                    },
                    {
                        label: 'Cases',
                        data: {!! json_encode($dailyCases) !!},
                        backgroundColor: '#2563eb',
                        borderRadius: 4,
                    },
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } },
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } }
            }
        });

        @if(count($casesByBrandLabeled) > 0)
        new Chart(document.getElementById('chartByBrand'), {
            type: 'doughnut',
            data: {
                labels: {!! json_encode(array_keys($casesByBrandLabeled)) !!},
                datasets: [{ data: {!! json_encode(array_values($casesByBrandLabeled)) !!}, backgroundColor: colors }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                const pct = total > 0 ? ((ctx.parsed / total) * 100).toFixed(1) : 0;
                                return ctx.label + ': ' + ctx.parsed + ' (' + pct + '%)';
                            }
                        }
                    }
                }
            }
        });
        @endif

        new Chart(document.getElementById('chartByType'), {
            type: 'doughnut',
            data: {
                labels: {!! json_encode(array_keys($casesByType)) !!},
                datasets: [{ data: {!! json_encode(array_values($casesByType)) !!}, backgroundColor: colors }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right', labels: { boxWidth: 12, font: { size: 11 } } } } }
        });

        const sevLabels = {!! json_encode(array_keys($casesBySeverity)) !!};
        const sevData = {!! json_encode(array_values($casesBySeverity)) !!};
        const sevColors = sevLabels.map(l => severityColors[l] || '#6b7280');
        new Chart(document.getElementById('chartBySeverity'), {
            type: 'doughnut',
            data: {
                labels: sevLabels.map(l => l.charAt(0).toUpperCase() + l.slice(1)),
                datasets: [{ data: sevData, backgroundColor: sevColors }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right', labels: { boxWidth: 12, font: { size: 11 } } } } }
        });
        }); // chart-ready listener
    </script>
@endsection
