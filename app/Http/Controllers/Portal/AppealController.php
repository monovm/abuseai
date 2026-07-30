<?php

namespace App\Http\Controllers\Portal;

use App\Enums\ActionType;
use App\Http\Controllers\Controller;
use App\Jobs\AI\HandleAppeal;
use App\Models\AbuseCase;
use App\Models\CaseAction;
use Illuminate\Http\Request;

class AppealController extends Controller
{
    public function store(Request $request, AbuseCase $case)
    {
        $request->validate([
            'appeal_text' => ['required', 'string', 'min:20', 'max:5000'],
        ]);

        $user = $request->user();
        $customer = $case->customer;

        if (! $customer || $customer->email !== $user->email) {
            abort(403);
        }

        // Record the appeal
        CaseAction::create([
            'case_id' => $case->id,
            'action_type' => ActionType::AppealReceived,
            'payload' => ['appeal_text' => $request->input('appeal_text')],
            'note' => 'Customer appeal submitted via portal',
            'created_at' => now(),
        ]);

        // Dispatch AI appeal handler
        HandleAppeal::dispatch($case, $request->input('appeal_text'));

        return redirect()
            ->back()
            ->with('success', 'Your appeal has been submitted and is being reviewed.');
    }
}
