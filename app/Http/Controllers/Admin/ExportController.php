<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ExportService;
use Illuminate\Http\Request;

class ExportController extends Controller
{
    public function __construct(
        protected ExportService $export,
    ) {}

    public function cases(Request $request)
    {
        return $this->export->exportCases($request->only(['status', 'abuse_type', 'severity', 'from', 'to']));
    }

    public function reports(Request $request)
    {
        return $this->export->exportReports($request->only(['source', 'abuse_type', 'from', 'to']));
    }
}
