@extends('layouts.admin')
@section('header', 'Case Queue')
@section('title', 'Cases - ' . config('app.name', 'Abuse AI'))

@section('content')
    <div style="display:flex; justify-content:flex-end; margin-bottom:1rem;">
        <a href="{{ route('admin.export.cases', request()->query()) }}" class="btn btn-secondary btn-sm">Export CSV</a>
    </div>

    <div class="kpi-grid">
        <div class="kpi">
            <div class="kpi-label">Open Cases</div>
            <div class="kpi-value">{{ $openCount }}</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Critical</div>
            <div class="kpi-value" style="color:var(--danger);">{{ $criticalCount }}</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">SLA Breached</div>
            <div class="kpi-value" style="color:var(--warning);">{{ $slaBreachedCount }}</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Today's Reports</div>
            <div class="kpi-value">{{ $todayReportCount }}</div>
        </div>
    </div>

    @livewire('admin.case-queue')
@endsection
