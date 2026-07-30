<?php

namespace App\Support\Email;

/**
 * When an abuse complaint is forwarded to us (a customer forwards a Spamhaus
 * notice, an internal team forwards a CERT email, etc.), the visible From
 * header is the forwarder — not the actual reporter. This class digs the
 * original sender out of the forwarded payload so we attribute the report
 * to the upstream party.
 *
 * Strategies, in priority order:
 *   1. message/rfc822 attached email — highest fidelity, full headers
 *   2. Resent-From / X-Forwarded-From outer headers
 *   3. "---------- Forwarded message ----------" block in body
 *   4. Outlook-style "From: ... <email>" block in body
 *
 * Returns null when the email doesn't look forwarded or no upstream
 * sender could be identified.
 */
class ForwardedReporterDetector
{
    /**
     * @return array{email: string, name: ?string, source: string}|null
     */
    public static function detect(array $email): ?array
    {
        $raw = (string) ($email['raw'] ?? '');
        $body = (string) ($email['body'] ?? '');
        $subject = (string) ($email['subject'] ?? '');
        $headers = (array) ($email['headers'] ?? []);

        // 1. Inline rfc822 attachment — the original message lives inside.
        if ($raw !== '') {
            $hit = self::fromRfc822Attachment($raw);
            if ($hit) {
                return $hit;
            }
        }

        // 2. Resent-* / X-Forwarded-* outer headers.
        $hit = self::fromForwardingHeaders($raw, $headers);
        if ($hit) {
            return $hit;
        }

        // 3 & 4 only run when the email looks forwarded — subject prefix
        // ("Fwd:", "FW:", localized variants) or a forwarding marker
        // anywhere in the body. Avoids false positives from emails that
        // happen to quote a "From: ..." line in their signature.
        if (! self::looksForwarded($subject, $body)) {
            return null;
        }

        $hit = self::fromForwardedBlock($body);
        if ($hit) {
            return $hit;
        }

        return self::fromOutlookHeaderBlock($body);
    }

    /**
     * Scan the raw RFC822 for message/rfc822 MIME parts and pull the From
     * header out of the first one that has one.
     *
     * @return array{email: string, name: ?string, source: string}|null
     */
    protected static function fromRfc822Attachment(string $raw): ?array
    {
        $split = preg_split('/\r?\n\r?\n/', $raw, 2);
        $headerText = $split[0] ?? '';
        $body = $split[1] ?? '';

        if (stripos($headerText, 'Content-Type: multipart/') === false) {
            return null;
        }
        if (! preg_match('/boundary="?([^"\s;]+)"?/i', $headerText, $m)) {
            return null;
        }

        $boundary = $m[1];
        $parts = explode("--{$boundary}", $body);

        foreach ($parts as $part) {
            if (! preg_match('/Content-Type:\s*message\/rfc822/i', $part)) {
                continue;
            }

            // The attached message starts after the part's own header block.
            $inner = preg_split('/\r?\n\r?\n/', $part, 2)[1] ?? '';
            if ($inner === '') {
                continue;
            }
            // Inner message itself has its own header/body split.
            $innerHeaders = preg_split('/\r?\n\r?\n/', $inner, 2)[0] ?? '';
            $from = self::extractHeaderValue($innerHeaders, 'From');
            if ($from) {
                $parsed = self::parseEmailAddress($from);
                if ($parsed) {
                    return $parsed + ['source' => 'rfc822_attachment'];
                }
            }
        }

        return null;
    }

    /**
     * @return array{email: string, name: ?string, source: string}|null
     */
    protected static function fromForwardingHeaders(string $raw, array $headers): ?array
    {
        $candidates = [];

        if ($raw !== '') {
            $headerText = preg_split('/\r?\n\r?\n/', $raw, 2)[0] ?? '';
            foreach (['Resent-From', 'X-Forwarded-From', 'X-Original-From'] as $h) {
                $v = self::extractHeaderValue($headerText, $h);
                if ($v) {
                    $candidates[] = $v;
                }
            }
        }

        foreach (['resent_from', 'x_forwarded_from', 'x_original_from'] as $k) {
            if (! empty($headers[$k])) {
                $candidates[] = (string) $headers[$k];
            }
        }

        foreach ($candidates as $raw) {
            $parsed = self::parseEmailAddress($raw);
            if ($parsed) {
                return $parsed + ['source' => 'forwarding_header'];
            }
        }

        return null;
    }

    /**
     * Match "Forwarded message" / "Original Message" / localized banners
     * followed by a From: line. Most mail clients emit a recognizable banner.
     *
     * @return array{email: string, name: ?string, source: string}|null
     */
    protected static function fromForwardedBlock(string $body): ?array
    {
        // Banners we recognise (case-insensitive). Each is searched as a
        // marker; the From: line within ~30 lines after it is taken as
        // the upstream sender.
        $banners = [
            '/-{3,}\s*forwarded message\s*-{3,}/i',
            '/-{3,}\s*original message\s*-{3,}/i',
            '/begin forwarded message/i',
            '/-{3,}\s*weitergeleitete nachricht\s*-{3,}/i', // German
            '/-{3,}\s*mensaje reenviado\s*-{3,}/i',          // Spanish
            '/-{3,}\s*message transféré\s*-{3,}/i',          // French
        ];

        foreach ($banners as $banner) {
            if (! preg_match($banner, $body, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $after = substr($body, $m[0][1]);
            // Limit search window so a banner near the bottom can't pick up
            // an unrelated From: line lower down (e.g. another quoted reply).
            $window = mb_substr($after, 0, 4000);
            if (preg_match('/^\s*From:\s*(.+)$/im', $window, $fm)) {
                $parsed = self::parseEmailAddress($fm[1]);
                if ($parsed) {
                    return $parsed + ['source' => 'forwarded_block'];
                }
            }
        }

        return null;
    }

    /**
     * Outlook / Gmail "rich" forwards drop a header block at the top of the
     * forwarded body without a banner:
     *   From: Alice <alice@example.com>
     *   Sent: Monday, May 1, 2025 ...
     *   To: ...
     *   Subject: ...
     *
     * We look for the From: + Sent:/Date: pair specifically so we don't
     * grab a stray From: line from someone's signature.
     *
     * @return array{email: string, name: ?string, source: string}|null
     */
    protected static function fromOutlookHeaderBlock(string $body): ?array
    {
        // Trim leading whitespace/quote prefixes (>) so the regex can hit.
        $lines = preg_split('/\r?\n/', $body) ?: [];
        $clean = [];
        foreach ($lines as $line) {
            $clean[] = ltrim($line, "> \t");
        }
        $stripped = implode("\n", $clean);

        // First N kb only — forwarded headers come at the top.
        $head = mb_substr($stripped, 0, 6000);

        if (preg_match(
            '/^From:\s*(?<from>.+)\s*\n(?:Date|Sent):\s*.+\n(?:To|An|A):/im',
            $head,
            $m,
        )) {
            $parsed = self::parseEmailAddress($m['from']);
            if ($parsed) {
                return $parsed + ['source' => 'outlook_block'];
            }
        }

        return null;
    }

    protected static function looksForwarded(string $subject, string $body): bool
    {
        $s = mb_strtolower(trim($subject));
        $forwardedPrefixes = [
            'fwd:', 'fw:', 'fwd ', 'fw ',
            'wg:', 'tr:', 'rv:', 'i:', 'enc:',  // de / fr / es / it / pt
        ];
        foreach ($forwardedPrefixes as $p) {
            if (str_starts_with($s, $p)) {
                return true;
            }
        }

        $bodyLower = mb_strtolower($body);
        $markers = [
            'forwarded message',
            'original message',
            'begin forwarded message',
            'weitergeleitete nachricht',
            'mensaje reenviado',
            'message transféré',
        ];
        foreach ($markers as $marker) {
            if (str_contains($bodyLower, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pull a single header value out of a block of headers. Folded
     * continuation lines (leading whitespace) are joined onto the
     * preceding header so multi-line From values still parse.
     */
    protected static function extractHeaderValue(string $headerText, string $name): ?string
    {
        $lines = preg_split('/\r?\n/', $headerText) ?: [];
        $joined = [];
        $current = '';
        foreach ($lines as $line) {
            if (preg_match('/^\s+/', $line) && $current !== '') {
                $joined[count($joined) - 1] .= ' ' . trim($line);
                continue;
            }
            $joined[] = $line;
            $current = $line;
        }

        $needle = strtolower($name) . ':';
        foreach ($joined as $line) {
            if (stripos($line, $needle) === 0) {
                $value = trim(substr($line, strlen($needle)));
                if ($value !== '') {
                    return self::decodeMimeHeader($value);
                }
            }
        }

        return null;
    }

    /**
     * Extract email + display name from a header value like
     *   `"Alice Smith" <alice@example.com>` or `alice@example.com`.
     *
     * @return array{email: string, name: ?string}|null
     */
    protected static function parseEmailAddress(string $raw): ?array
    {
        $raw = trim(self::decodeMimeHeader($raw));
        if ($raw === '') {
            return null;
        }

        $email = null;
        $name = null;

        if (preg_match('/<([^>]+)>/', $raw, $m)) {
            $email = trim($m[1]);
            $name = trim(str_replace($m[0], '', $raw), " \t\"'");
        } else {
            $email = $raw;
        }

        $email = strtolower(trim($email, " \t<>\"'"));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $name = $name !== '' ? $name : null;
        return ['email' => $email, 'name' => $name];
    }

    protected static function decodeMimeHeader(string $header): string
    {
        if (! str_contains($header, '=?')) {
            return $header;
        }
        return preg_replace_callback('/=\?([^?]+)\?([BQ])\?([^?]+)\?=/i', function ($m) {
            $text = $m[3];
            return strtoupper($m[2]) === 'B'
                ? (base64_decode($text) ?: $text)
                : quoted_printable_decode(str_replace('_', ' ', $text));
        }, $header);
    }
}
