<?php

namespace App\Providers;

use App\Events\CaseScored;
use App\Events\ReportReceived;
use App\Listeners\HandleCaseScored;
use App\Listeners\HandleReportReceived;
use App\Models\AbuseCase;
use App\Models\AbuseReport;
use App\Models\Customer;
use App\Observers\AbuseCaseObserver;
use App\Observers\AbuseReportObserver;
use App\Observers\CustomerObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Event listeners
        Event::listen(ReportReceived::class, HandleReportReceived::class);
        Event::listen(CaseScored::class, HandleCaseScored::class);

        // Model observers for audit logging
        AbuseCase::observe(AbuseCaseObserver::class);
        AbuseReport::observe(AbuseReportObserver::class);
        Customer::observe(CustomerObserver::class);

        // Register queue failure handler
        \Illuminate\Support\Facades\Queue::failing(function (\Illuminate\Queue\Events\JobFailed $event) {
            (new \App\Exceptions\QueueFailureHandler)->handle($event);
        });

        // Unified datetime formatting. Use @datetime($d) anywhere a date/time
        // value is shown so timestamps look the same site-wide. Renders an
        // absolute timestamp with a relative tooltip (e.g. "2 hours ago").
        Blade::directive('datetime', function ($expression) {
            return "<?php \$__d = ({$expression}); echo \$__d ? '<span title=\"' . e(\\Illuminate\\Support\\Carbon::parse(\$__d)->diffForHumans()) . '\">' . e(\\Illuminate\\Support\\Carbon::parse(\$__d)->format('M j, Y g:ia')) . '</span>' : '—'; ?>";
        });

        Blade::directive('reldate', function ($expression) {
            return "<?php \$__d = ({$expression}); echo \$__d ? '<span title=\"' . e(\\Illuminate\\Support\\Carbon::parse(\$__d)->format('M j, Y g:ia')) . '\">' . e(\\Illuminate\\Support\\Carbon::parse(\$__d)->diffForHumans()) . '</span>' : '—'; ?>";
        });

        // Throttle infrastructure auto-link lookups so a DDoS that creates a
        // burst of cases doesn't storm WHMCS / Virtualizor. 30 lookups per
        // minute is enough headroom for normal traffic; bursts release back
        // to the queue and process as the limit refills.
        RateLimiter::for('infra-link', fn () => Limit::perMinute(30));
    }
}
