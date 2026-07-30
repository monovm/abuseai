<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust Cloudflare (and any extra proxies via TRUSTED_PROXIES) so
        // request()->ip() returns the real visitor IP and request()->isSecure()
        // honours the X-Forwarded-Proto: https that CF sends to the origin.
        //
        // The config() helper isn't usable inside withMiddleware() — the config
        // service isn't bound to the container yet — so read the values directly.
        // Prefer the cached config: once `config:cache` has run, Laravel stops
        // parsing .env, so re-requiring the raw config file would evaluate
        // env('TRUSTED_PROXIES') to null and silently drop proxy trust.
        $cachedConfig = __DIR__.'/../bootstrap/cache/config.php';
        $cf = is_file($cachedConfig)
            ? (require $cachedConfig)['cloudflare'] ?? null
            : null;
        // Fall back to the raw file if the cache is absent or predates this
        // config — never end up with an empty list, which would trust nothing.
        $cf ??= require __DIR__.'/../config/cloudflare.php';

        $extraProxies = $cf['trusted_proxies'] ?? null;
        if ($extraProxies === '*') {
            $proxies = '*';
        } elseif ($extraProxies) {
            $proxies = array_values(array_filter(array_map('trim', explode(',', $extraProxies))));
        } else {
            $proxies = array_merge($cf['ipv4'] ?? [], $cf['ipv6'] ?? []);
        }

        $middleware->trustProxies(
            at: $proxies,
            headers: \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->alias([
            'auth.apikey' => \App\Http\Middleware\ApiKeyAuth::class,
            'reporter.ratelimit' => \App\Http\Middleware\ReporterRateLimit::class,
            // Spatie permission package middleware aliases — needed so route
            // groups can use ->middleware('role:admin') to gate settings.
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
