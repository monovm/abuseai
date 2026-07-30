<?php

use App\Http\Controllers\Api\CaseStatusController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

// Reporter API (X-API-Key or Bearer token)
Route::prefix('v1')->middleware(['auth.apikey', 'reporter.ratelimit'])->group(function () {
    Route::post('/reports', [ReportController::class, 'store']);       // JSON or ARF (RFC 5965)
    Route::post('/reports/bulk', [ReportController::class, 'bulk']);   // JSON array
    Route::get('/cases/{number}', [CaseStatusController::class, 'show']);
});

// Webhook receivers (HMAC signature verified per provider).
// throttle:60,1 caps unsigned/forged-signature attacks at 60/min/IP so a
// flood of bogus payloads can't burn HMAC compute or fill the log.
Route::prefix('v1')->middleware('throttle:60,1')->group(function () {
    Route::post('/webhook/{provider}', [WebhookController::class, 'receive']);
    // Supported providers: abuseipdb, spamhaus, google_fbl, microsoft_fbl, spamcop, generic_arf
});

// Email inbound endpoint (for MTA pipe / forwarding). Auth is via shared
// secret in X-Inbound-Secret (config/abusedesk.php → webhooks.inbound_email_secret).
// Without the secret configured, the endpoint returns 503.
Route::prefix('v1')->middleware('throttle:120,1')->group(function () {
    Route::post('/inbound-email', [WebhookController::class, 'receiveEmail']);
});
