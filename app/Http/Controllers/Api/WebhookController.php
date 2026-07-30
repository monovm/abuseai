<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\Ingestion\ProcessWebhookReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    protected array $supportedProviders = [
        'abuseipdb',
        'spamhaus',
        'google_fbl',
        'microsoft_fbl',
        'spamcop',
        'generic_arf',
    ];

    public function receive(Request $request, string $provider): JsonResponse
    {
        if (! in_array($provider, $this->supportedProviders)) {
            return response()->json(['error' => 'Unknown provider'], 404);
        }

        // Verify HMAC signature if configured
        if (! $this->verifySignature($request, $provider)) {
            Log::warning("Webhook signature verification failed for {$provider}", [
                'ip' => $request->ip(),
            ]);
            return response()->json(['error' => 'Invalid signature'], 403);
        }

        // Get raw body for ARF content
        $payload = $request->all();
        $contentType = $request->header('Content-Type', '');

        // If ARF or plain text content, include raw body
        if (str_contains($contentType, 'report') ||
            str_contains($contentType, 'text/plain') ||
            str_contains($contentType, 'message/feedback-report')) {
            $payload['_raw_body'] = $request->getContent();
            $payload['_content_type'] = $contentType;
        }

        ProcessWebhookReport::dispatch(
            $provider,
            $payload,
            $request->header('X-Signature', $request->header('X-Hub-Signature-256', '')),
        )->onQueue('ingestion');

        return response()->json(['message' => 'Webhook received'], 202);
    }

    /**
     * Receive raw email body as abuse report (for email forwarding / pipe integration).
     */
    public function receiveEmail(Request $request): JsonResponse
    {
        // Shared-secret auth for the MTA-pipe / forwarding endpoint. The
        // sending side (postfix pipe script, Mailgun route, etc.) must
        // include the same secret in the X-Inbound-Secret header. Refuse
        // when the secret isn't configured rather than fail open — same
        // posture as the per-provider webhooks above.
        $expected = config('abusedesk.webhooks.inbound_email_secret');
        if (empty($expected)) {
            Log::warning('Inbound-email secret not configured; rejecting request', [
                'ip' => $request->ip(),
            ]);
            return response()->json(['error' => 'Endpoint not configured'], 503);
        }
        $provided = (string) $request->header('X-Inbound-Secret', '');
        if (! hash_equals($expected, $provided)) {
            Log::warning('Inbound-email secret mismatch', ['ip' => $request->ip()]);
            return response()->json(['error' => 'Invalid signature'], 403);
        }

        $rawBody = $request->getContent();

        if (empty($rawBody)) {
            return response()->json(['error' => 'Empty body'], 400);
        }

        ProcessWebhookReport::dispatch(
            'email_inbound',
            [
                '_raw_body' => $rawBody,
                '_content_type' => $request->header('Content-Type', 'message/rfc822'),
            ],
            '',
        )->onQueue('ingestion');

        return response()->json(['message' => 'Email received'], 202);
    }

    protected function verifySignature(Request $request, string $provider): bool
    {
        $secret = config("abusedesk.webhooks.{$provider}.secret");

        // No secret configured = reject. Previously this returned true, which
        // meant a forgotten env var silently disabled signature verification
        // and accepted unsigned payloads from anyone. To opt out of signing
        // for a specific provider intentionally, set
        // ABUSEDESK_WEBHOOKS_{PROVIDER}_REQUIRE_SIGNATURE=false in env and
        // gate per-provider above this method instead.
        if (empty($secret)) {
            Log::warning("Webhook secret not configured for {$provider}; rejecting request", [
                'ip' => $request->ip(),
            ]);
            return false;
        }

        $signature = $request->header('X-Signature')
            ?? $request->header('X-Hub-Signature-256')
            ?? $request->header('X-Webhook-Signature')
            ?? '';

        if (empty($signature)) {
            return false;
        }

        $body = $request->getContent();

        // Try sha256 HMAC
        $expected = hash_hmac('sha256', $body, $secret);
        if (hash_equals($expected, $signature)) {
            return true;
        }

        // Try with sha256= prefix (GitHub style)
        if (hash_equals("sha256={$expected}", $signature)) {
            return true;
        }

        // Try sha1 HMAC
        $expectedSha1 = hash_hmac('sha1', $body, $secret);
        if (hash_equals($expectedSha1, $signature) || hash_equals("sha1={$expectedSha1}", $signature)) {
            return true;
        }

        return false;
    }
}
