<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AbuseCase;
use Illuminate\Http\JsonResponse;

class CaseStatusController extends Controller
{
    public function show(string $number): JsonResponse
    {
        $case = AbuseCase::where('case_number', $number)->firstOrFail();

        return response()->json([
            'case_number' => $case->case_number,
            'status' => $case->status->value,
            'abuse_type' => $case->abuse_type->value,
            'severity_level' => $case->severity_level->value,
            'created_at' => $case->created_at->toIso8601String(),
            'updated_at' => $case->updated_at->toIso8601String(),
        ]);
    }
}
