<?php

use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\AttachmentController;
use App\Http\Controllers\Admin\CaseController as AdminCaseController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\ExportController;
use App\Http\Controllers\Portal\AppealController;
use App\Http\Controllers\Portal\CaseController as PortalCaseController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\WebForm\AbuseFormController;
use App\Http\Controllers\WebForm\ApiDocsController;
use App\Http\Controllers\WebForm\PartnerController;
use App\Http\Middleware\ResolveBrandFromHost;
use Illuminate\Support\Facades\Route;

// Root redirects to dashboard if logged in, otherwise to login
Route::get('/', function () {
    if (auth()->check()) {
        return redirect('/admin/dashboard');
    }
    return redirect('/login');
});

// Public abuse report form (per-brand via Host header)
Route::middleware(ResolveBrandFromHost::class)->group(function () {
    Route::get('/report', [AbuseFormController::class, 'show'])->name('report.show');
    Route::post('/report', [AbuseFormController::class, 'store'])
        ->middleware('throttle:10,60')
        ->name('report.store');

    // Partner self-service: register and receive an API token.
    Route::get('/partner', [PartnerController::class, 'show'])->name('partner.show');
    Route::post('/partner', [PartnerController::class, 'register'])
        ->middleware('throttle:5,60')
        ->name('partner.register');

    // Public API reference, branded against the current host.
    Route::get('/api', [ApiDocsController::class, 'show'])->name('api.docs');
});

// Dashboard redirect
Route::get('/dashboard', function () {
    return redirect('/admin/dashboard');
})->middleware(['auth'])->name('dashboard');

// Profile (Breeze)
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Admin routes
Route::prefix('admin')->middleware(['auth'])->group(function () {
    Route::get('/', fn () => redirect('/admin/dashboard'));

    // Dashboard overview
    Route::get('/dashboard', [App\Http\Controllers\Admin\DashboardController::class, 'index'])->name('admin.dashboard');

    // Cases
    Route::get('/cases', [AdminCaseController::class, 'index'])->name('admin.cases.index');
    Route::get('/cases/create', function () {
        return view('admin.cases.create');
    })->name('admin.cases.create');
    Route::get('/cases/{case}', [AdminCaseController::class, 'show'])->name('admin.cases.show');

    // Reports
    Route::get('/reports', function () {
        $totalReports = \App\Models\AbuseReport::count();
        $todayReports = \App\Models\AbuseReport::whereDate('created_at', today())->count();
        $unlinkedReports = \App\Models\AbuseReport::whereNull('case_id')->count();
        $noiseReports = \App\Models\AbuseReport::where(function ($q) {
            $q->where('ai_noise_score', '>', 0.8)
              ->orWhereJsonContains('metadata->flagged_as_not_abuse', true);
        })->count();
        $duplicateReports = \App\Models\AbuseReport::where('is_duplicate', true)->count();
        return view('admin.reports.index', compact('totalReports', 'todayReports', 'unlinkedReports', 'noiseReports', 'duplicateReports'));
    })->name('admin.reports.index');

    // Report attachments (download)
    Route::get('/reports/{report}/attachments/{index}', [AttachmentController::class, 'download'])
        ->whereNumber('index')
        ->name('admin.reports.attachments.download');

    // Customers
    Route::get('/customers', [CustomerController::class, 'index'])->name('admin.customers.index');
    Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('admin.customers.show');

    // Analytics
    Route::get('/analytics', [AnalyticsController::class, 'index'])->name('admin.analytics');

    // IP Inventory
    Route::get('/ips', function () {
        return view('admin.ips.index');
    })->name('admin.ips.index');

    // IP Reputation
    Route::get('/reputation', function () {
        return view('admin.reputation.index');
    })->name('admin.reputation.index');

    // AbuseIPDB
    Route::get('/abuseipdb', function () {
        return view('admin.abuseipdb.index');
    })->name('admin.abuseipdb.index');

    // Email Inbox
    Route::get('/email-inbox', function () {
        return view('admin.email.inbox');
    })->name('admin.email.inbox');

    // Reporters
    Route::get('/reporters', fn () => view('admin.reporters.index'))
        ->name('admin.reporters.index');

    // Audit Log
    Route::get('/audit-log', function () {
        return view('admin.audit.index');
    })->name('admin.audit.index');

    // Settings: only admins. Agents/viewers get a 403 from the role middleware.
    Route::middleware('role:admin')->group(function () {
        // User Management
        Route::get('/users', function () {
            return view('admin.users.index');
        })->name('admin.users.index');

        // Brands & Email
        Route::get('/brands', function () {
            return view('admin.brands.index');
        })->name('admin.brands.index');

        // Rules (CRUD)
        Route::get('/rules', function () {
            return view('admin.rules.index');
        })->name('admin.rules.index');

        // Templates (CRUD)
        Route::get('/templates', function () {
            return view('admin.templates.index');
        })->name('admin.templates.index');

        // AI Settings
        Route::get('/ai-settings', function () {
            return view('admin.ai.settings');
        })->name('admin.ai.settings');

        // AI Usage (tokens + cost)
        Route::get('/ai-usage', function () {
            return view('admin.ai.usage');
        })->name('admin.ai.usage');

        // CSV Exports — admin only. Customer/case data should never leak
        // to non-admin authenticated users (agents, viewers, future
        // tenants). Was previously outside this group, which let any
        // logged-in user pull the entire case/report dataset.
        Route::get('/export/cases', [ExportController::class, 'cases'])->name('admin.export.cases');
        Route::get('/export/reports', [ExportController::class, 'reports'])->name('admin.export.reports');
    });

    // Admin User Guide
    Route::get('/guide', function () {
        return view('admin.guide.index');
    })->name('admin.guide.index');
});

// Customer portal routes (kept but not linked in admin UI)
Route::prefix('portal')->middleware(['auth'])->group(function () {
    Route::get('/cases', [PortalCaseController::class, 'index'])->name('portal.cases.index');
    Route::get('/cases/{case}', [PortalCaseController::class, 'show'])->name('portal.cases.show');
    Route::post('/cases/{case}/appeal', [AppealController::class, 'store'])->name('portal.appeal.store');
});

require __DIR__.'/auth.php';
