<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $query = Customer::with('brand')->withCount('cases');

        if ($search = trim((string) $request->query('q', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('email', 'like', "%{$search}%")
                  ->orWhere('company', 'like', "%{$search}%")
                  ->orWhere('external_id', 'like', "%{$search}%");
            });
        }

        if ($request->query('status') === 'suspended') {
            $query->where('is_suspended', true);
        } elseif ($request->query('status') === 'active') {
            $query->where('is_suspended', false);
        }

        if ($request->query('risk') === 'high') {
            $query->where('abuse_score', '>=', 40);
        } elseif ($request->query('risk') === 'critical') {
            $query->where('abuse_score', '>=', 60);
        }

        // Highest-risk first so abuse work bubbles up; newest as tiebreaker so
        // a freshly-linked customer never disappears below 25 zero-score peers.
        $customers = $query->orderByDesc('abuse_score')
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        return view('admin.customers.index', [
            'customers' => $customers,
            'filters' => [
                'q' => $search,
                'status' => $request->query('status', ''),
                'risk' => $request->query('risk', ''),
            ],
        ]);
    }

    public function show(Customer $customer)
    {
        return view('admin.customers.show', compact('customer'));
    }
}
