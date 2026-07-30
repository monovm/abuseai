<?php

namespace App\Http\Middleware;

use App\Models\Brand;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active brand for the public /report form based on the
 * request hostname. Brands are configured with a list of hostnames in
 * their report_hostnames column; an unmatched host falls back to the
 * default brand so the form keeps working on the original abuse URL.
 *
 * The resolved brand is bound to the container as "current.brand"
 * and shared with views as $brand so controllers, views, and the
 * normalizer can read it without re-resolving.
 */
class ResolveBrandFromHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $brand = Brand::findByHost($request->getHost()) ?? Brand::getDefault();

        app()->instance('current.brand', $brand);
        $request->attributes->set('brand', $brand);
        View::share('brand', $brand);

        return $next($request);
    }
}
