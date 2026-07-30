<?php

namespace App\Support\Email;

/**
 * Pulls every piece of human-readable text out of a parsed email so IOC
 * extraction (IPs, domains, URLs) can see the subject and any text-format
 * attachments — not just the primary body that ParsesMimeEmail already
 * unpacks. The primary body parser stops at the first text/plain or
 * text/html part; forwarded abuse complaints often hide the real target
 * IP in the subject line or in an attached log / forwarded .eml.
 */
class EmailTextExtractor
{
    /**
     * Concatenate subject + body + every decoded text MIME part from raw.
     */
    public static function allText(array $email): string
    {
        $parts = [];

        if (! empty($email['subject'])) {
            $parts[] = (string) $email['subject'];
        }
        if (! empty($email['body'])) {
            $parts[] = (string) $email['body'];
        }
        if (! empty($email['raw'])) {
            $parts[] = self::decodeAllTextPartsFromRaw((string) $email['raw']);
        }

        return implode("\n", array_filter($parts, fn ($p) => trim($p) !== ''));
    }

    /**
     * Walk every MIME part in a raw RFC822 message and return the decoded
     * text from each text/* part (including attachments). Recurses into
     * nested multipart blocks. Non-text attachments (images, archives) are
     * skipped — they can't contain readable IPs anyway.
     */
    public static function decodeAllTextPartsFromRaw(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        $split = preg_split('/\r?\n\r?\n/', $raw, 2);
        $headerText = $split[0] ?? '';
        $body = $split[1] ?? '';

        if (stripos($headerText, 'Content-Type: multipart/') === false) {
            return self::decodeBodyWithHeaders($body, $headerText);
        }

        if (! preg_match('/boundary="?([^"\s;]+)"?/i', $headerText, $m)) {
            return $body;
        }

        $boundary = $m[1];
        $mimeParts = explode("--{$boundary}", $body);
        $out = [];

        foreach ($mimeParts as $part) {
            $partType = '';
            if (preg_match('/Content-Type:\s*([^\s;]+)/i', $part, $tm)) {
                $partType = strtolower(trim($tm[1]));
            }

            // Recurse into nested multipart parts (e.g. multipart/alternative
            // wrapped inside multipart/mixed).
            if (str_starts_with($partType, 'multipart/')) {
                $out[] = self::decodeAllTextPartsFromRaw($part);
                continue;
            }

            // Skip binary parts. We only want text we can regex-scan.
            // Allow text/*, message/rfc822 (forwarded emails), and a few
            // structured-text formats commonly attached to abuse reports.
            $isTextLike = $partType === ''
                || str_starts_with($partType, 'text/')
                || $partType === 'message/rfc822'
                || $partType === 'application/json'
                || $partType === 'application/xml';

            if (! $isTextLike) {
                continue;
            }

            $decoded = self::decodeMimePart($part);
            if ($partType === 'text/html') {
                $decoded = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $decoded));
            }
            if (trim($decoded) !== '') {
                $out[] = $decoded;
            }
        }

        return implode("\n", $out);
    }

    protected static function decodeMimePart(string $part): string
    {
        $sections = preg_split('/\r?\n\r?\n/', $part, 2);
        $partHeaders = $sections[0] ?? '';
        $partBody = trim($sections[1] ?? '');

        if ($partBody === '') {
            return '';
        }

        if (preg_match('/Content-Transfer-Encoding:\s*(\S+)/i', $partHeaders, $m)) {
            $encoding = strtolower(trim($m[1]));
            if ($encoding === 'base64') {
                $decoded = base64_decode($partBody, true);
                return ($decoded !== false) ? $decoded : $partBody;
            }
            if ($encoding === 'quoted-printable') {
                return quoted_printable_decode($partBody);
            }
        }

        return $partBody;
    }

    protected static function decodeBodyWithHeaders(string $body, string $headerText): string
    {
        if (preg_match('/Content-Transfer-Encoding:\s*quoted-printable/i', $headerText)) {
            return quoted_printable_decode($body);
        }
        if (preg_match('/Content-Transfer-Encoding:\s*base64/i', $headerText)) {
            $decoded = base64_decode($body, true);
            return ($decoded !== false) ? $decoded : $body;
        }
        return $body;
    }
}
