<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class ReporterRateLimit
{
    public function handle(Request $request, Closure $next): Response
    {
        $reporter = $request->get('reporter');
        $key = $reporter ? "api_reporter:{$reporter->id}" : "api_ip:{$request->ip()}";

        if (RateLimiter::tooManyAttempts($key, 60)) {
            $retryAfter = RateLimiter::availableIn($key);

            return response()->json([
                'error' => 'Rate limit exceeded',
                'retry_after' => $retryAfter,
            ], 429)->header('Retry-After', $retryAfter);
        }

        RateLimiter::hit($key, 60);

        return $next($request);
    }
}
