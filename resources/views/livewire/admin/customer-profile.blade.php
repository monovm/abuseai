<div>
    @if(session('customer-success'))
        <div style="padding:10px 14px; background:#d1fae5; border:1px solid #6ee7b7; border-radius:6px; margin-bottom:12px; font-size:13px; color:#065f46;">{{ session('customer-success') }}</div>
    @endif
    @if(session('customer-error'))
        <div style="padding:10px 14px; background:#fef2f2; border:1px solid #fecaca; border-radius:6px; margin-bottom:12px; font-size:13px; color:#991b1b;">{{ session('customer-error') }}</div>
    @endif

    <div class="card">
        <div class="card-header">
            <div class="card-title">{{ $customer->email }}</div>
            <div style="display:flex; align-items:center; gap:8px;">
                <span class="badge {{ $customer->is_suspended ? 'badge-critical' : 'badge-resolved' }}">
                    {{ $customer->is_suspended ? 'Suspended' : 'Active' }}
                </span>
                @if($customer->is_suspended)
                    <button wire:click="unsuspend"
                        wire:loading.attr="disabled" wire:target="unsuspend"
                        onclick="return confirm('Unsuspend {{ addslashes($customer->email) }}? Their service(s) will be restored.')"
                        class="btn btn-secondary btn-sm">
                        <span wire:loading.remove wire:target="unsuspend">Unsuspend</span>
                        <span wire:loading wire:target="unsuspend">Working…</span>
                    </button>
                @endif
            </div>
        </div>
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:1rem; font-size:0.8125rem;">
            <div>
                <div class="text-muted" style="font-size:0.6875rem; text-transform:uppercase;">Brand</div>
                <div>
                    @if($customer->brand)
                        <span class="badge" style="background:#e0e7ff; color:#3730a3;">{{ $customer->brand->name }}</span>
                    @else
                        -
                    @endif
                </div>
            </div>
            <div>
                <div class="text-muted" style="font-size:0.6875rem; text-transform:uppercase;">Company</div>
                <div>{{ $customer->company ?? '-' }}</div>
            </div>
            <div>
                <div class="text-muted" style="font-size:0.6875rem; text-transform:uppercase;">WHMCS Client ID</div>
                <div>{{ $customer->external_id ?? '-' }}</div>
            </div>
            <div>
                <div class="text-muted" style="font-size:0.6875rem; text-transform:uppercase;">Abuse Score</div>
                <div style="font-weight:600;">{{ number_format($customer->abuse_score, 1) }}</div>
            </div>
            <div>
                <div class="text-muted" style="font-size:0.6875rem; text-transform:uppercase;">IP Addresses</div>
                <div>{{ count($customer->ip_addresses ?? []) }}</div>
            </div>
        </div>

        @if(! $customer->is_suspended)
            <div style="margin-top:14px; padding-top:14px; border-top:1px solid var(--border);">
                <label for="suspend-reason" class="text-muted" style="font-size:0.6875rem; text-transform:uppercase; display:block; margin-bottom:4px;">Suspend Customer</label>
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <input id="suspend-reason" type="text" wire:model="suspendReason"
                        placeholder="Reason (recorded in suspension history)"
                        style="flex:1; min-width:240px; padding:6px 10px; border:1px solid var(--border); border-radius:6px; font-size:13px;">
                    <button wire:click="suspend"
                        wire:loading.attr="disabled" wire:target="suspend"
                        onclick="return confirm('Suspend {{ addslashes($customer->email) }}? This is logged and notifies on-call.')"
                        class="btn btn-danger btn-sm">
                        <span wire:loading.remove wire:target="suspend">Suspend</span>
                        <span wire:loading wire:target="suspend">Working…</span>
                    </button>
                </div>
            </div>
        @endif
    </div>

    {{-- Internal notes --}}
    <div class="card">
        <div class="card-title" style="margin-bottom:0.75rem;">Internal Notes</div>
        <label for="customer-notes" class="text-muted" style="font-size:0.6875rem; text-transform:uppercase; display:block; margin-bottom:4px;">Visible to staff only — never sent to the customer.</label>
        <textarea id="customer-notes" wire:model="notes" rows="4"
            placeholder="Repeat-offender? Known reseller? Anything an agent should see before suspending."
            style="width:100%; padding:8px 10px; border:1px solid var(--border); border-radius:6px; font-size:0.8125rem; resize:vertical;"></textarea>
        <div style="margin-top:8px; display:flex; align-items:center; gap:8px;">
            <button wire:click="saveNotes" wire:loading.attr="disabled" wire:target="saveNotes" class="btn btn-secondary btn-sm">
                <span wire:loading.remove wire:target="saveNotes">Save notes</span>
                <span wire:loading wire:target="saveNotes">Saving…</span>
            </button>
        </div>
    </div>

    {{-- Cases --}}
    <div class="card">
        <div class="card-title" style="margin-bottom:0.75rem;">Cases ({{ $cases->count() }})</div>
        <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Case #</th>
                    <th>Type</th>
                    <th>Score</th>
                    <th>Status</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                @forelse($cases as $case)
                    <tr>
                        <td><a href="/admin/cases/{{ $case->id }}" style="color:var(--primary);">{{ $case->case_number }}</a></td>
                        <td>{{ ucfirst($case->abuse_type->value) }}</td>
                        <td><span class="badge badge-{{ $case->severity_level->value }}">{{ number_format($case->severity_score, 1) }}</span></td>
                        <td><span class="badge badge-{{ $case->status->value }}">{{ ucfirst($case->status->value) }}</span></td>
                        <td class="text-sm text-muted">@reldate($case->created_at)</td>
                    </tr>
                @empty
                    <x-empty-state colspan="5" title="No cases on this customer yet"
                        message="This customer hasn't had any abuse reports linked to them." />
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    {{-- Suspension History --}}
    @if($suspensions->isNotEmpty())
    <div class="card">
        <div class="card-title" style="margin-bottom:0.75rem;">Suspension History</div>
        @foreach($suspensions as $s)
            <div style="padding:0.625rem 0; border-bottom:1px solid var(--border); font-size:0.8125rem;">
                <div><strong>{{ $s->suspended_at->format('M j, Y g:ia') }}</strong> {{ $s->unsuspended_at ? '→ ' . $s->unsuspended_at->format('M j, g:ia') : '(active)' }}</div>
                <div class="text-muted">{{ $s->suspension_reason }}</div>
            </div>
        @endforeach
    </div>
    @endif
</div>
