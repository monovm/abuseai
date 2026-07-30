<?php

namespace App\Services\Email;

use App\Models\Brand;
use App\Models\SentEmail;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MandrillService
{
    protected string $apiKey;
    protected string $baseUrl = 'https://mandrillapp.com/api/1.0';
    protected BrandSmtpMailer $smtpFallback;

    public function __construct(?BrandSmtpMailer $smtpFallback = null)
    {
        $this->apiKey = config('abusedesk.email.mandrill_key') ?? '';
        $this->smtpFallback = $smtpFallback ?? app(BrandSmtpMailer::class);
    }

    public function isConfigured(): bool
    {
        return ! empty($this->apiKey);
    }

    /**
     * Whether Mandrill may be used at all right now. False when an operator
     * has flipped the transport switch off (e.g. Mandrill is down and
     * still returning "queued" for undelivered mail).
     */
    public function mandrillEnabled(): bool
    {
        return EmailTransportSettings::mandrillEnabled();
    }

    /**
     * Whether send() has any transport available for this brand: Mandrill
     * (global or per-brand key, and only when the operator hasn't disabled
     * it) or the brand's own SMTP fallback.
     */
    public function canSendFor(?Brand $brand): bool
    {
        $mandrillUsable = $this->mandrillEnabled()
            && ($this->isConfigured() || ! empty($brand?->smtp_config['mandrill_key']));

        return $mandrillUsable || $this->smtpFallback->isAvailableFor($brand);
    }

    /**
     * Send an email via Mandrill API.
     */
    public function send(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        ?Brand $brand = null,
        ?string $inReplyTo = null,
        ?string $caseId = null,
    ): ?SentEmail {
        $brand = $brand ?? Brand::getDefault();

        $fromEmail = $brand?->from_email ?? config('mail.from.address', 'abuse@abuseai.local');
        $fromName = $brand?->from_name ?? config('mail.from.name', 'Abuse AI');

        // Add signature if brand has one
        $fullHtml = $htmlBody;
        if ($brand?->email_signature) {
            $fullHtml .= "\n<br>\n" . $brand->email_signature;
        }
        if ($brand?->email_footer) {
            $fullHtml .= "\n<br>\n<p style=\"font-size:11px;color:#999;\">" . nl2br(e($brand->email_footer)) . "</p>";
        }

        $plainText = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $fullHtml));

        // Build Mandrill message
        $message = [
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'to' => [
                ['email' => $toEmail, 'name' => $toName, 'type' => 'to'],
            ],
            'subject' => $subject,
            'html' => $fullHtml,
            'text' => $plainText,
            'headers' => [],
            'track_opens' => true,
            'track_clicks' => true,
            'tags' => ['abuseai'],
        ];

        if ($brand?->reply_to_email) {
            $message['headers']['Reply-To'] = $brand->reply_to_email;
        }

        if ($inReplyTo) {
            $message['headers']['In-Reply-To'] = $inReplyTo;
            $message['headers']['References'] = $inReplyTo;
        }

        // Use per-brand SMTP config if set
        if ($brand?->smtp_config && ! empty($brand->smtp_config['mandrill_key'])) {
            $apiKey = $brand->smtp_config['mandrill_key'];
        } else {
            $apiKey = $this->apiKey;
        }

        $attributes = [
            'case_id' => $caseId,
            'brand_id' => $brand?->id,
            'sent_by' => auth()->id(),
            'to_email' => $toEmail,
            'to_name' => $toName,
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'subject' => $subject,
            'body_html' => $fullHtml,
            'body_text' => $plainText,
            'in_reply_to' => $inReplyTo,
        ];

        // Operator has switched Mandrill off (it's down / undelivering) —
        // skip it entirely and send from the brand's own SMTP inbox.
        if (! $this->mandrillEnabled()) {
            return $this->attemptSmtpFallback(
                $brand,
                $attributes,
                mandrillResponse: ['error' => 'Mandrill disabled by operator'],
                mandrillStatus: 'failed',
                mandrillReason: 'Mandrill disabled by operator',
            );
        }

        // No Mandrill key anywhere — go straight to the brand's own SMTP.
        if (empty($apiKey)) {
            return $this->attemptSmtpFallback(
                $brand,
                $attributes,
                mandrillResponse: ['error' => 'Mandrill API key not configured'],
                mandrillStatus: 'failed',
                mandrillReason: 'Mandrill API key not configured',
            );
        }

        try {
            $response = Http::timeout(30)->post("{$this->baseUrl}/messages/send", [
                'key' => $apiKey,
                'message' => $message,
                'async' => false,
            ]);

            $result = $response->json();

            // Mandrill returns a LIST of per-recipient results on success, but
            // a single error OBJECT — {"status":"error","name":...,"message":...}
            // — on an API-level failure (invalid key, unverified sending domain,
            // validation error). Laravel's HTTP client does NOT throw on 4xx/5xx,
            // so without these checks an outright API rejection was decoded as an
            // empty $firstResult, saved as status "unknown", and reported to the
            // user as success — while nothing ever reached Mandrill's outbound
            // queue. Detect both the HTTP status and the response shape.
            if (! $response->successful() || ! is_array($result) || ! array_is_list($result) || ! isset($result[0])) {
                $error = is_array($result) && $result !== [] ? $result : ['error' => $response->body()];
                $reason = $error['message'] ?? $error['name'] ?? 'HTTP ' . $response->status();

                Log::error('Mandrill API rejected send', [
                    'to' => $toEmail,
                    'http_status' => $response->status(),
                    'reason' => $reason,
                    'response' => $error,
                ]);

                return $this->attemptSmtpFallback($brand, $attributes, $error, 'failed', $reason);
            }

            $firstResult = $result[0];
            $status = $firstResult['status'] ?? 'unknown';

            // "rejected"/"invalid" are 200-OK responses that still mean the mail
            // did NOT go out (denylisted recipient, unsigned sending domain, bad
            // address). "invalid" messages never appear in the outbound log at
            // all — exactly the "shows success but nothing sent" symptom.
            if (! in_array($status, SentEmail::ACCEPTED_STATUSES, true)) {
                $reason = $firstResult['reject_reason'] ?? $status;

                Log::warning('Mandrill did not accept email', [
                    'to' => $toEmail,
                    'status' => $status,
                    'reason' => $reason,
                ]);

                return $this->attemptSmtpFallback(
                    $brand,
                    $attributes,
                    $firstResult,
                    $status,
                    $reason,
                    mandrillMessageId: $firstResult['_id'] ?? null,
                );
            }

            return SentEmail::create($attributes + [
                'message_id' => $firstResult['_id'] ?? null,
                'status' => $status,
                'mandrill_response' => $firstResult,
                'metadata' => ['transport' => 'mandrill'],
            ]);
        } catch (\Throwable $e) {
            Log::error('Mandrill send failed: ' . $e->getMessage(), ['to' => $toEmail]);

            return $this->attemptSmtpFallback(
                $brand,
                $attributes,
                ['error' => $e->getMessage()],
                'failed',
                $e->getMessage(),
            );
        }
    }

    /**
     * Mandrill did not deliver — try the brand's own SMTP mailbox instead,
     * and record ONE SentEmail row reflecting the final outcome. When no
     * fallback is configured this degrades to exactly the old behavior:
     * the Mandrill failure is recorded as-is.
     */
    protected function attemptSmtpFallback(
        ?Brand $brand,
        array $attributes,
        array $mandrillResponse,
        string $mandrillStatus,
        string $mandrillReason,
        ?string $mandrillMessageId = null,
    ): SentEmail {
        if ($this->smtpFallback->isAvailableFor($brand)) {
            $result = $this->smtpFallback->sendFor(
                brand: $brand,
                toEmail: $attributes['to_email'],
                toName: $attributes['to_name'],
                subject: $attributes['subject'],
                htmlBody: $attributes['body_html'],
                textBody: $attributes['body_text'],
                fromEmail: $attributes['from_email'],
                fromName: $attributes['from_name'],
                replyTo: $brand?->reply_to_email,
                inReplyTo: $attributes['in_reply_to'],
            );

            if ($result['success']) {
                Log::info('Email delivered via brand SMTP fallback', [
                    'to' => $attributes['to_email'],
                    'brand' => $brand?->name,
                    'mandrill_error' => $mandrillReason,
                ]);

                return SentEmail::create($attributes + [
                    'message_id' => $result['message_id'],
                    'status' => 'sent',
                    'mandrill_response' => $mandrillResponse,
                    'metadata' => [
                        'transport' => 'smtp_fallback',
                        'mandrill_error' => $mandrillReason,
                    ],
                ]);
            }

            Log::error('Brand SMTP fallback also failed', [
                'to' => $attributes['to_email'],
                'brand' => $brand?->name,
                'mandrill_error' => $mandrillReason,
                'smtp_error' => $result['error'],
            ]);

            $metadata = ['transport' => 'mandrill', 'smtp_fallback_error' => $result['error']];
        } else {
            $metadata = ['transport' => 'mandrill'];
        }

        return SentEmail::create($attributes + [
            'message_id' => $mandrillMessageId,
            'status' => $mandrillStatus,
            'mandrill_response' => $mandrillResponse,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Test Mandrill connection.
     */
    public function ping(): array
    {
        try {
            $response = Http::timeout(10)->post("{$this->baseUrl}/users/ping", [
                'key' => $this->apiKey,
            ]);

            return ['success' => $response->successful(), 'response' => $response->body()];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
