<?php

namespace App\Services\Ingestion\Feeds;

use App\Services\Ingestion\Parsers\ArfParser;

/**
 * SpamCop report parser.
 * SpamCop sends reports via email in a specific text format.
 */
class SpamCopParser
{
    public function __construct(
        protected ArfParser $arfParser,
    ) {}

    /**
     * Parse a SpamCop report email body.
     */
    public function parse(string $emailBody): array
    {
        $result = [
            'source' => 'spamcop',
            'abuse_type' => 'spam',
            'source_ip' => null,
            'target_ip' => null,
            'spam_url' => null,
            'spamcop_id' => null,
            'evidence' => $emailBody,
            'extracted_ips' => [],
            'extracted_urls' => [],
        ];

        // Extract SpamCop report ID
        if (preg_match('/sc_id\s*=\s*(\S+)/', $emailBody, $m)) {
            $result['spamcop_id'] = $m[1];
        }
        if (preg_match('/Report\s+#?\s*(\d+)/', $emailBody, $m)) {
            $result['spamcop_id'] = $result['spamcop_id'] ?? $m[1];
        }

        // Extract source IP (the spammer)
        if (preg_match('/source:\s*(\d+\.\d+\.\d+\.\d+)/i', $emailBody, $m)) {
            $result['source_ip'] = $m[1];
        }
        if (! $result['source_ip'] && preg_match('/Received:.*?\[(\d+\.\d+\.\d+\.\d+)\]/', $emailBody, $m)) {
            $result['source_ip'] = $m[1];
        }

        // Extract "spam sent to" URL
        if (preg_match('/https?:\/\/www\.spamcop\.net\/\S+/', $emailBody, $m)) {
            $result['spam_url'] = $m[0];
        }

        // Use ARF parser for IOC extraction
        $result['extracted_ips'] = $this->arfParser->extractIps($emailBody);
        $result['extracted_urls'] = $this->arfParser->extractUrls($emailBody);

        // The target IP is what we're responsible for — usually the source_ip is in our network
        $result['target_ip'] = $result['source_ip'];

        return $result;
    }
}
