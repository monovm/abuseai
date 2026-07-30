<?php

namespace App\Services\Ingestion\Parsers;

/**
 * RFC 5965 Abuse Reporting Format (ARF) parser.
 * Parses multipart/report emails with report-type=feedback-report.
 */
class ArfParser
{
    /**
     * Parse an ARF-formatted email (raw string).
     * Returns structured data or null if not valid ARF.
     */
    public function parse(string $rawEmail): ?array
    {
        // Split headers and body
        $parts = preg_split('/\r?\n\r?\n/', $rawEmail, 2);
        $headerSection = $parts[0] ?? '';
        $bodySection = $parts[1] ?? '';

        $headers = $this->parseHeaders($headerSection);

        // Check if this is an ARF report
        $contentType = $headers['content-type'] ?? '';
        if (! str_contains(strtolower($contentType), 'multipart/report') ||
            ! str_contains(strtolower($contentType), 'feedback-report')) {
            // Try parsing as plain abuse email
            return $this->parsePlainAbuse($headers, $bodySection);
        }

        // Extract boundary
        $boundary = $this->extractBoundary($contentType);
        if (! $boundary) {
            return $this->parsePlainAbuse($headers, $bodySection);
        }

        // Split into MIME parts
        $mimeParts = $this->splitMimeParts($bodySection, $boundary);

        $result = [
            'format' => 'arf',
            'headers' => $headers,
            'human_readable' => null,
            'feedback_report' => [],
            'original_message' => null,
            'extracted_ips' => [],
            'extracted_domains' => [],
            'extracted_urls' => [],
            'abuse_type' => 'other',
            'source_ip' => null,
            'reported_domain' => null,
        ];

        foreach ($mimeParts as $index => $part) {
            $partHeaders = $this->parseMimePartHeaders($part);
            $partBody = $this->extractMimePartBody($part);
            $partContentType = strtolower($partHeaders['content-type'] ?? '');

            if ($index === 0 || str_contains($partContentType, 'text/plain')) {
                if (! $result['human_readable']) {
                    $result['human_readable'] = $this->decodeBody($partBody, $partHeaders);
                }
            }

            if (str_contains($partContentType, 'feedback-report')) {
                $result['feedback_report'] = $this->parseFeedbackReport($partBody);
            }

            if (str_contains($partContentType, 'message/rfc822') || str_contains($partContentType, 'text/rfc822-headers')) {
                $result['original_message'] = $partBody;
            }
        }

        // Extract data from feedback report fields
        $fb = $result['feedback_report'];
        $result['abuse_type'] = $this->mapFeedbackType($fb['Feedback-Type'] ?? '');
        $result['source_ip'] = $fb['Source-IP'] ?? $this->extractFirstIp($result['human_readable'] ?? '');

        if (isset($fb['Reported-Domain'])) {
            $result['reported_domain'] = $fb['Reported-Domain'];
        }
        if (isset($fb['Reported-Uri'])) {
            $result['extracted_urls'][] = $fb['Reported-Uri'];
        }

        // Extract IPs from all parts
        $allText = ($result['human_readable'] ?? '') . ' ' . ($result['original_message'] ?? '');
        $result['extracted_ips'] = $this->extractIps($allText);
        $result['extracted_domains'] = $this->extractDomains($allText);
        $result['extracted_urls'] = array_merge($result['extracted_urls'], $this->extractUrls($allText));

        return $result;
    }

    /**
     * Parse a plain (non-ARF) abuse email.
     */
    protected function parsePlainAbuse(array $headers, string $body): array
    {
        $body = $this->decodeBodyFromHeaders($body, $headers);

        return [
            'format' => 'plain',
            'headers' => $headers,
            'human_readable' => $body,
            'feedback_report' => [],
            'original_message' => null,
            'extracted_ips' => $this->extractIps($body),
            'extracted_domains' => $this->extractDomains($body),
            'extracted_urls' => $this->extractUrls($body),
            'abuse_type' => $this->guessAbuseType($headers['subject'] ?? '', $body),
            'source_ip' => $this->extractFirstIp($body),
            'reported_domain' => $this->extractFirstDomain($body),
        ];
    }

    /**
     * Parse the feedback-report MIME part into key-value pairs.
     */
    protected function parseFeedbackReport(string $body): array
    {
        $fields = [];
        $lines = explode("\n", $body);

        foreach ($lines as $line) {
            $line = rtrim($line, "\r");
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $fields[trim($key)] = trim($value);
            }
        }

        return $fields;
    }

    protected function parseHeaders(string $headerText): array
    {
        $headers = [];
        $lines = explode("\n", $headerText);
        $currentKey = '';

        foreach ($lines as $line) {
            $line = rtrim($line, "\r");

            if (preg_match('/^\s+/', $line) && $currentKey) {
                $headers[$currentKey] .= ' ' . trim($line);
                continue;
            }

            if (preg_match('/^([^:]+):\s*(.*)/', $line, $matches)) {
                $currentKey = strtolower(trim($matches[1]));
                $headers[$currentKey] = trim($matches[2]);
            }
        }

        return $headers;
    }

    protected function parseMimePartHeaders(string $part): array
    {
        $parts = preg_split('/\r?\n\r?\n/', $part, 2);
        return $this->parseHeaders($parts[0] ?? '');
    }

    protected function extractMimePartBody(string $part): string
    {
        $parts = preg_split('/\r?\n\r?\n/', $part, 2);
        return $parts[1] ?? '';
    }

    protected function extractBoundary(string $contentType): ?string
    {
        if (preg_match('/boundary="?([^";\s]+)"?/i', $contentType, $matches)) {
            return $matches[1];
        }
        return null;
    }

    protected function splitMimeParts(string $body, string $boundary): array
    {
        $parts = explode("--{$boundary}", $body);
        // Remove preamble and epilogue
        array_shift($parts);
        return array_filter(array_map('trim', $parts), fn ($p) => $p !== '--' && $p !== '');
    }

    protected function decodeBody(string $body, array $headers): string
    {
        $encoding = strtolower($headers['content-transfer-encoding'] ?? '');

        if ($encoding === 'quoted-printable') {
            return quoted_printable_decode($body);
        }
        if ($encoding === 'base64') {
            return base64_decode($body) ?: $body;
        }

        return $body;
    }

    protected function decodeBodyFromHeaders(string $body, array $headers): string
    {
        $encoding = strtolower($headers['content-transfer-encoding'] ?? '');

        if ($encoding === 'quoted-printable') {
            return quoted_printable_decode($body);
        }
        if ($encoding === 'base64') {
            return base64_decode($body) ?: $body;
        }

        // Handle multipart for plain emails
        $contentType = $headers['content-type'] ?? '';
        if (str_contains($contentType, 'multipart/')) {
            $boundary = $this->extractBoundary($contentType);
            if ($boundary) {
                $parts = $this->splitMimeParts($body, $boundary);
                foreach ($parts as $part) {
                    $ph = $this->parseMimePartHeaders($part);
                    if (str_contains(strtolower($ph['content-type'] ?? ''), 'text/plain')) {
                        return $this->decodeBody($this->extractMimePartBody($part), $ph);
                    }
                }
            }
        }

        return $body;
    }

    protected function mapFeedbackType(string $type): string
    {
        return match (strtolower(trim($type))) {
            'abuse' => 'spam',
            'fraud' => 'fraud',
            'virus' => 'malware',
            'other' => 'other',
            'not-spam' => 'other',
            'phishing' => 'phishing',
            default => 'other',
        };
    }

    protected function guessAbuseType(string $subject, string $body): string
    {
        $text = strtolower($subject . ' ' . substr($body, 0, 3000));

        if (str_contains($text, 'law enforcement') || str_contains($text, 'police') || str_contains($text, 'criminal')
            || str_contains($text, 'court order') || str_contains($text, 'subpoena') || str_contains($text, 'cybercrime')
            || str_contains($text, 'subscriber information') || str_contains($text, 'data request')) return 'law_enforcement';
        if (str_contains($text, 'phish')) return 'phishing';
        if (str_contains($text, 'malware') || str_contains($text, 'virus') || str_contains($text, 'ransomware')) return 'malware';
        if (str_contains($text, 'botnet') || str_contains($text, 'c2') || str_contains($text, 'c&c')) return 'botnet';
        if (str_contains($text, 'ddos') || str_contains($text, 'denial of service') || str_contains($text, 'amplification')) return 'ddos';
        if (str_contains($text, 'brute force') || str_contains($text, 'port scan')) return 'brute_force';
        if (str_contains($text, 'intrusion') || str_contains($text, 'hacking') || str_contains($text, 'exploit')) return 'intrusion';
        if (str_contains($text, 'spam') || str_contains($text, 'uce') || str_contains($text, 'unsolicited')) return 'spam';
        if (str_contains($text, 'copyright') || str_contains($text, 'dmca') || str_contains($text, 'infringement')) return 'copyright';
        if (str_contains($text, 'fraud') || str_contains($text, 'scam')) return 'fraud';

        return 'other';
    }

    public function extractIps(string $text): array
    {
        return \App\Support\IpHelpers::extractIps($text);
    }

    public function extractFirstIp(string $text): ?string
    {
        $ips = $this->extractIps($text);
        return $ips[0] ?? null;
    }

    public function extractDomains(string $text): array
    {
        preg_match_all('/\b([a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}\b/i', $text, $matches);
        $domains = array_unique($matches[0] ?? []);
        // Filter out IPs and common non-domains
        return array_values(array_filter($domains, fn ($d) => ! filter_var($d, FILTER_VALIDATE_IP) && strlen($d) > 4));
    }

    public function extractFirstDomain(string $text): ?string
    {
        $domains = $this->extractDomains($text);
        return $domains[0] ?? null;
    }

    public function extractUrls(string $text): array
    {
        preg_match_all('/https?:\/\/[^\s<>"\']+/i', $text, $matches);
        return array_values(array_unique($matches[0] ?? []));
    }
}
