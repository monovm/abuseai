@extends('layouts.portal')
@section('title', $case->case_number . ' - ' . config('app.name', 'Abuse AI'))

@section('content')
    <div style="margin-bottom:1rem;">
        <a href="/portal/cases" class="text-sm" style="color:#2563eb;">&larr; Back to cases</a>
    </div>

    @if(session('success'))
        <div class="alert-success">{{ session('success') }}</div>
    @endif

    <div class="card">
        <div style="display:flex; justify-content:space-between; align-items:start;">
            <div>
                <h2 style="font-size:1.125rem; font-weight:600;">{{ $case->case_number }}</h2>
                <div class="text-sm text-muted">{{ ucfirst($case->abuse_type->value) }} &middot; Opened {{ $case->created_at->format('M j, Y g:ia') }}</div>
            </div>
            <span class="badge badge-{{ $case->status->value }}">{{ ucfirst($case->status->value) }}</span>
        </div>

        <div class="resp-grid-2" style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-top:1rem; font-size:0.8125rem;">
            <div>
                <div class="text-muted" style="font-size:0.6875rem; text-transform:uppercase;">Target IP</div>
                <div>{{ $case->target_ip ?? '-' }}</div>
            </div>
            <div>
                <div class="text-muted" style="font-size:0.6875rem; text-transform:uppercase;">Target Domain</div>
                <div>{{ $case->target_domain ?? '-' }}</div>
            </div>
            <div>
                <div class="text-muted" style="font-size:0.6875rem; text-transform:uppercase;">Severity</div>
                <div><span class="badge badge-{{ $case->severity_level->value }}">{{ ucfirst($case->severity_level->value) }}</span></div>
            </div>
            <div>
                <div class="text-muted" style="font-size:0.6875rem; text-transform:uppercase;">Last Updated</div>
                <div>{{ $case->updated_at->format('M j, Y g:ia') }}</div>
            </div>
        </div>
    </div>

    {{-- Activity --}}
    <div class="card">
        <h3 style="font-size:0.9375rem; font-weight:600; margin-bottom:0.75rem;">Activity</h3>
        @forelse($actions as $action)
            <div style="padding:0.5rem 0; border-bottom:1px solid #e5e7eb; font-size:0.8125rem;">
                <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:4px;">
                    <strong>{{ ucfirst(str_replace('_', ' ', $action->action_type->value)) }}</strong>
                    <span class="text-muted" style="white-space:nowrap;">{{ $action->created_at->format('M j, g:ia') }}</span>
                </div>
                @if($action->note)
                    <div class="text-muted">{{ $action->note }}</div>
                @endif
            </div>
        @empty
            <div class="text-sm text-muted">No activity to display.</div>
        @endforelse
    </div>

    {{-- Appeal form (only if case is actioned/suspended) --}}
    @if(in_array($case->status->value, ['actioned', 'investigating']))
        <div class="card">
            <h3 style="font-size:0.9375rem; font-weight:600; margin-bottom:0.75rem;">Submit Appeal</h3>
            <form method="POST" action="/portal/cases/{{ $case->id }}/appeal">
                @csrf
                <div style="margin-bottom:0.75rem;">
                    <label for="appeal_text">Explain why this action should be reviewed</label>
                    <textarea name="appeal_text" id="appeal_text" required minlength="20" placeholder="Please provide details about why you believe this suspension should be reconsidered...">{{ old('appeal_text') }}</textarea>
                    @error('appeal_text')
                        <div style="color:#dc2626; font-size:0.75rem; margin-top:0.25rem;">{{ $message }}</div>
                    @enderror
                </div>
                <button type="submit" class="btn btn-primary">Submit Appeal</button>
            </form>
        </div>
    @endif
@endsection
