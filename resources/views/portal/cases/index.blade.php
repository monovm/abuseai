@extends('layouts.portal')
@section('title', 'My Cases - ' . config('app.name', 'Abuse AI'))

@section('content')
    <h2 style="font-size:1.25rem; margin-bottom:1rem;">My Cases</h2>

    @forelse($cases as $case)
        <div class="card">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                <div>
                    <a href="/portal/cases/{{ $case->id }}" style="font-weight:600; color:#2563eb; text-decoration:none;">{{ $case->case_number }}</a>
                    <span class="text-sm text-muted" style="margin-left:0.5rem;">{{ ucfirst($case->abuse_type->value) }}</span>
                </div>
                <span class="badge badge-{{ $case->status->value }}">{{ ucfirst($case->status->value) }}</span>
            </div>
            <div class="text-sm text-muted mt-4">
                Target: {{ $case->target_ip ?? $case->target_domain ?? '-' }} &middot;
                Opened: {{ $case->created_at->format('M j, Y') }}
            </div>
        </div>
    @empty
        <div class="card" style="text-align:center; color:#6b7280;">No cases found.</div>
    @endforelse

    <div class="mt-4">{{ $cases->links() }}</div>
@endsection
