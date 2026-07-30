<?php

namespace App\Http\Middleware;

use App\Models\Reporter;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyAuth
{
    /**
     * Authenticate via X-API-Key header OR OAuth2 Bearer token (Sanctum).
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Try X-API-Key header first
        $apiKey = $request->header('X-API-Key');
        if ($apiKey) {
            return $this->authenticateByApiKey($request, $next, $apiKey);
        }

        // Try Bearer token (Sanctum personal access token)
        $bearer = $request->bearerToken();
        if ($bearer) {
            return $this->authenticateByBearer($request, $next, $bearer);
        }

        return response()->json(['error' => 'Authentication required. Use X-API-Key header or Bearer token.'], 401);
    }

    protected function authenticateByApiKey(Request $request, Closure $next, string $apiKey): Response
    {
        $reporter = Reporter::whereNotNull('api_key')
            ->where('is_blocked', false)
            ->get()
            ->first(fn ($r) => Hash::check($apiKey, $r->api_key));

        if (! $reporter) {
            return response()->json(['error' => 'Invalid API key'], 403);
        }

        $request->merge(['reporter' => $reporter]);

        return $next($request);
    }

    protected function authenticateByBearer(Request $request, Closure $next, string $token): Response
    {
        $accessToken = PersonalAccessToken::findToken($token);

        if (! $accessToken) {
            return response()->json(['error' => 'Invalid bearer token'], 403);
        }

        $user = $accessToken->tokenable;

        // Find or create a reporter linked to this user
        $reporter = Reporter::firstOrCreate(
            ['email' => $user->email],
            [
                'name' => $user->name,
                'type' => 'api',
                'is_trusted' => true,
            ],
        );

        if ($reporter->is_blocked) {
            return response()->json(['error' => 'Reporter account blocked'], 403);
        }

        $request->merge(['reporter' => $reporter]);
        $accessToken->forceFill(['last_used_at' => now()])->save();

        return $next($request);
    }
}
