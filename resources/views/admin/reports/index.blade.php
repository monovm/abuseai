@extends('layouts.admin')
@section('header', 'Reports')
@section('title', 'Reports - ' . config('app.name', 'Abuse AI'))

@section('content')
    <div style="display:flex; justify-content:flex-end; margin-bottom:1rem;">
        <a href="{{ route('admin.export.reports') }}?{{ request()->getQueryString() }}" class="btn btn-secondary btn-sm">Export CSV</a>
    </div>

    <div class="kpi-grid">
        <div class="kpi">
            <div class="kpi-label">Total Reports</div>
            <div class="kpi-value">{{ number_format($totalReports) }}</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Today</div>
            <div class="kpi-value" style="color:#2563eb;">{{ number_format($todayReports) }}</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Unlinked</div>
            <div class="kpi-value" style="color:#f59e0b;">{{ number_format($unlinkedReports) }}</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Noise / Not Abuse</div>
            <div class="kpi-value" style="color:#dc2626;">{{ number_format($noiseReports) }}</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Duplicates</div>
            <div class="kpi-value" style="color:#6b7280;">{{ number_format($duplicateReports) }}</div>
        </div>
    </div>

    @livewire('admin.report-queue')
@endsection
