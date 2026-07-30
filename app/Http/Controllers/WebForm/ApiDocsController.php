<?php

namespace App\Http\Controllers\WebForm;

use App\Http\Controllers\Controller;

/**
 * Public API reference page. Endpoints are rendered against the request's
 * host so each branded report site documents its own URLs (e.g.
 * abuse.brand-a.example vs abuse.brand-b.example) without per-brand templating.
 */
class ApiDocsController extends Controller
{
    public function show()
    {
        return view('webform.api');
    }
}
