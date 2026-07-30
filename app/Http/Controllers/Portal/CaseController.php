<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\AbuseCase;
use Illuminate\Http\Request;

class CaseController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $cases = AbuseCase::whereHas('customer', function ($q) use ($user) {
            $q->where('email', $user->email);
        })->latest()->paginate(20);

        return view('portal.cases.index', compact('cases'));
    }

    public function show(Request $request, AbuseCase $case)
    {
        // Ensure the customer owns this case
        $user = $request->user();
        $customer = $case->customer;

        if (! $customer || $customer->email !== $user->email) {
            abort(403);
        }

        $actions = $case->actions()
            ->whereIn('action_type', ['status_changed', 'suspended', 'unsuspended', 'email_sent', 'appeal_received', 'appeal_decided'])
            ->latest('created_at')
            ->get();

        return view('portal.cases.show', compact('case', 'actions'));
    }
}
