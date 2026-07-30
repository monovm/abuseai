<?php

namespace App\Services\Ingestion\Feeds;

use App\Services\Ingestion\Parsers\ArfParser;

/**
 * Feedback Loop (FBL) processor.
 * Handles Google Postmaster FBL, Microsoft SNDS/JMRP, and standard ARF FBLs.
 */
class FblProcessor
{
    public function __construct(
        protected ArfParser $arfParser,
    ) {}

    /**
     * Process a Google FBL report (JSON webhook payload).
     */
    public function processGoogleFbl(array $payload): array
    {
        return [
            'source' => 'google_fbl',
            'abuse_type' => 'spam',
            'target_ip' => $payload['sourceIp'] ?? $payload['source_ip'] ?? null,
            'reported_domain' => $payload['domainName'] ?? $payload['domain'] ?? null,
            'feedback_id' => $payload['feedbackId'] ?? $payload['feedback_id'] ?? null,
            'feedback_type' => $payload['feedbackType'] ?? 'abuse',
            'message_id' => $payload['originalMessageId'] ?? null,
            'authentication_results' => $payload['authenticationResults'] ?? null,
            'spam_score' => $payload['spamScore'] ?? null,
            'evidence' => json_encode($payload, JSON_PRETTY_PRINT),
            'extracted_ips' => array_filter([$payload['sourceIp'] ?? $payload['source_ip'] ?? null]),
        ];
    }

    /**
     * Process a Microsoft JMRP/SNDS FBL report (JSON webhook payload).
     */
    public function processMicrosoftFbl(array $payload): array
    {
        return [
            'source' => 'microsoft_fbl',
            'abuse_type' => 'spam',
            'target_ip' => $payload['sourceIp'] ?? $payload['SenderIP'] ?? null,
            'reported_domain' => $payload['senderDomain'] ?? $payload['SenderDomain'] ?? null,
            'feedback_id' => $payload['feedbackId'] ?? $payload['MessageId'] ?? null,
            'message_id' => $payload['originalMessageId'] ?? $payload['MessageId'] ?? null,
            'complaint_date' => $payload['complaintDate'] ?? $payload['DateReceived'] ?? null,
            'evidence' => json_encode($payload, JSON_PRETTY_PRINT),
            'extracted_ips' => array_filter([$payload['sourceIp'] ?? $payload['SenderIP'] ?? null]),
        ];
    }

    /**
     * Process a standard ARF FBL report (email body).
     */
    public function processArfFbl(string $rawEmail, string $source = 'fbl'): array
    {
        $parsed = $this->arfParser->parse($rawEmail);

        if (! $parsed) {
            return [
                'source' => $source,
                'abuse_type' => 'spam',
                'target_ip' => null,
                'evidence' => $rawEmail,
                'extracted_ips' => [],
            ];
        }

        return [
            'source' => $source,
            'abuse_type' => $parsed['abuse_type'],
            'target_ip' => $parsed['source_ip'],
            'reported_domain' => $parsed['reported_domain'],
            'feedback_type' => $parsed['feedback_report']['Feedback-Type'] ?? 'abuse',
            'original_rcpt' => $parsed['feedback_report']['Original-Rcpt-To'] ?? null,
            'original_mail_from' => $parsed['feedback_report']['Original-Mail-From'] ?? null,
            'arrival_date' => $parsed['feedback_report']['Arrival-Date'] ?? null,
            'reporting_mta' => $parsed['feedback_report']['Reporting-MTA'] ?? null,
            'evidence' => $parsed['human_readable'] ?? $rawEmail,
            'original_message' => $parsed['original_message'],
            'extracted_ips' => $parsed['extracted_ips'],
            'extracted_domains' => $parsed['extracted_domains'] ?? [],
            'extracted_urls' => $parsed['extracted_urls'] ?? [],
        ];
    }
}
