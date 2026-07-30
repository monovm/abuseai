@extends('layouts.admin')
@section('header', 'Analytics')
@section('title', 'Analytics - ' . config('app.name', 'Abuse AI'))

@section('content')
    <div class="kpi-grid">
        <div class="kpi">
            <div class="kpi-label">Total Cases</div>
            <div class="kpi-value">{{ number_format($totalCases) }}</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Open Cases</div>
            <div class="kpi-value" style="color:var(--primary);">{{ number_format($openCases) }}</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Total Reports</div>
            <div class="kpi-value">{{ number_format($totalReports) }}</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Suspended Customers</div>
            <div class="kpi-value" style="color:var(--danger);">{{ number_format($suspendedCustomers) }}</div>
        </div>
    </div>

    <div class="resp-grid-2" style="display:grid; grid-template-columns:1fr 1fr; gap:1rem;">
        {{-- Cases by Type --}}
        <div class="card">
            <div class="card-title" style="margin-bottom:0.75rem;">Cases by Type</div>
            <div style="position:relative; height:220px;"><canvas id="chartByType"></canvas></div>
        </div>

        {{-- Cases by Status --}}
        <div class="card">
            <div class="card-title" style="margin-bottom:0.75rem;">Cases by Status</div>
            <div style="position:relative; height:220px;"><canvas id="chartByStatus"></canvas></div>
        </div>

        {{-- Reports per Day --}}
        <div class="card" style="grid-column:span 2;">
            <div class="card-title" style="margin-bottom:0.75rem;">Reports per Day (Last 30 Days)</div>
            <div style="position:relative; height:200px;"><canvas id="chartReportsPerDay"></canvas></div>
        </div>

        {{-- Top Abused IPs --}}
        <div class="card" style="grid-column:span 2;">
            <div class="card-title" style="margin-bottom:0.75rem;">Top Abused IPs</div>
            <table class="table">
                <thead>
                    <tr><th>IP Address</th><th>Cases</th></tr>
                </thead>
                <tbody>
                    @foreach($topIps as $ip => $count)
                        <tr><td>{{ $ip }}</td><td>{{ $count }}</td></tr>
                    @endforeach
                    @if(empty($topIps))
                        <tr><td colspan="2" style="text-align:center; color:var(--text-muted);">No data</td></tr>
                    @endif
                </tbody>
            </table>
        </div>
    </div>

    {{-- Chart.js: prefer the bundled copy (window.Chart from app.js); fall back
         to the CDN if the deploy hasn't run npm run build yet. --}}
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
        const colors = ['#2563eb','#dc2626','#f59e0b','#10b981','#8b5cf6','#ec4899','#06b6d4','#84cc16'];

        new Chart(document.getElementById('chartByType'), {
            type: 'doughnut',
            data: {
                labels: {!! json_encode(array_keys($casesByType)) !!},
                datasets: [{ data: {!! json_encode(array_values($casesByType)) !!}, backgroundColor: colors }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right' } } }
        });

        new Chart(document.getElementById('chartByStatus'), {
            type: 'doughnut',
            data: {
                labels: {!! json_encode(array_keys($casesByStatus)) !!},
                datasets: [{ data: {!! json_encode(array_values($casesByStatus)) !!}, backgroundColor: colors }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right' } } }
        });

        new Chart(document.getElementById('chartReportsPerDay'), {
            type: 'bar',
            data: {
                labels: {!! json_encode(array_keys($reportsPerDay)) !!},
                datasets: [{ label: 'Reports', data: {!! json_encode(array_values($reportsPerDay)) !!}, backgroundColor: '#2563eb' }]
            },
            options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } }, plugins: { legend: { display: false } } }
        });
        }); // chart-ready listener
    </script>
@endsection
