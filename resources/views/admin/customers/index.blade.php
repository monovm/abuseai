@extends('layouts.admin')
@section('header', 'Customers')
@section('title', 'Customers - ' . config('app.name', 'Abuse AI'))

@section('content')
    <form method="GET" class="filters">
        <input type="text" name="q" value="{{ $filters['q'] }}" placeholder="Search email, company, WHMCS ID..." style="width:280px; max-width:100%;">
        <select name="status">
            <option value="">All statuses</option>
            <option value="active" @selected($filters['status'] === 'active')>Active</option>
            <option value="suspended" @selected($filters['status'] === 'suspended')>Suspended</option>
        </select>
        <select name="risk">
            <option value="">Any risk</option>
            <option value="high" @selected($filters['risk'] === 'high')>High (&ge; 40)</option>
            <option value="critical" @selected($filters['risk'] === 'critical')>Critical (&ge; 60)</option>
        </select>
        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
        @if($filters['q'] || $filters['status'] || $filters['risk'])
            <a href="{{ route('admin.customers.index') }}" class="btn btn-secondary btn-sm">Clear</a>
        @endif
    </form>

    <div class="card" style="padding:0; overflow:hidden;">
        <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Email</th>
                    <th>Brand</th>
                    <th>WHMCS Client</th>
                    <th>Company</th>
                    <th>Abuse Score</th>
                    <th>Cases</th>
                    <th>Status</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                @forelse($customers as $customer)
                    <tr>
                        <td><a href="/admin/customers/{{ $customer->id }}" style="color:var(--primary); text-decoration:none;">{{ $customer->email }}</a></td>
                        <td>
                            @if($customer->brand)
                                <span class="badge" style="background:#e0e7ff; color:#3730a3;">{{ $customer->brand->name }}</span>
                            @else
                                <span class="text-muted">-</span>
                            @endif
                        </td>
                        <td class="text-sm">{{ $customer->external_id ?? '-' }}</td>
                        <td>{{ $customer->company ?? '-' }}</td>
                        <td><strong>{{ number_format($customer->abuse_score, 1) }}</strong></td>
                        <td>{{ $customer->cases_count }}</td>
                        <td>
                            <span class="badge {{ $customer->is_suspended ? 'badge-critical' : 'badge-resolved' }}">
                                {{ $customer->is_suspended ? 'Suspended' : 'Active' }}
                            </span>
                        </td>
                        <td class="text-sm text-muted">{{ $customer->created_at->diffForHumans() }}</td>
                    </tr>
                @empty
                    <x-empty-state colspan="8" title="No customers match these filters"
                        message="Customers are auto-created when a report's target IP matches a WHMCS service or Virtualizor user." />
                @endforelse
            </tbody>
        </table>
        </div>
    </div>
    <div class="mt-4">{{ $customers->links() }}</div>
@endsection
