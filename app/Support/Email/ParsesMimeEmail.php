<?php

namespace App\Support\Email;

use App\Support\Text\Utf8;

/**
 * Shared MIME / header parsing primitives for raw-socket mail clients
 * (POP3, IMAP). Pulled out of EmailIngestionService so the IMAP backend
 * can reuse the same battle-tested parsers without duplication.
 */
trait ParsesMimeEmail
{
    /**
     * Parse email headers into a structured array. Tracks only the headers
     * the rest of the pipeline cares about; everything else is ignored so
     * continuation lines from untracked headers can't bleed into Date/etc.
     */
    protected function parseHeaders(string $headerText): array
    {
        $headers = [
            'from' => '',
            'subject' => '',
            'date' => '',
            'to' => '',
            'message_id' => '',
        ];

        $lines = explode("\n", $headerText);
        $currentHeader = '';

        foreach ($lines as $line) {
            $line = rtrim($line, "\r");

            if (preg_match('/^\s+/', $line) && $currentHeader && isset($headers[$currentHeader])) {
                $headers[$currentHeader] .= ' '.trim($line);

                continue;
            }

            if (preg_match('/^[!-9;-~]+:/', $line)) {
                $currentHeader = '';
            }

            if (preg_match('/^(From|Subject|Date|To|Message-ID):\s*(.+)/i', $line, $matches)) {
                $key = strtolower($matches[1]);
                $key = str_replace('-', '_', $key);
                $headers[$key] = trim($matches[2]);
                $currentHeader = $key;
            }
        }

        foreach (['from', 'subject', 'to'] as $field) {
            if (! empty($headers[$field])) {
                // Belt and suspenders: decode MIME words (now charset-aware),
                // then hard-scrub so an 8-bit header with no encoding at all,
                // or a word with a lying charset label, still can't emit
                // invalid UTF-8 into Livewire state / json_encode.
                $headers[$field] = $this->sanitizeUtf8($this->decodeMimeHeader($headers[$field]));
            }
        }

        return $headers;
    }

    /**
     * Parse a full RFC822 message into the array shape the rest of the
     * codebase expects.
     */
    protected function parseEmail(string $raw, int|string $messageId): array
    {
        $parts = preg_split('/\r?\n\r?\n/', $raw, 2);
        $headerText = $parts[0] ?? '';
        $body = $parts[1] ?? '';

        $headers = $this->parseHeaders($headerText);
        $plainBody = $this->extractPlainText($headerText, $body);
        $plainBody = $this->sanitizeUtf8($plainBody);

        return [
            'id' => $messageId,
            'from' => $headers['from'],
            'to' => $headers['to'],
            'subject' => $this->sanitizeUtf8($headers['subject']),
            'date' => $headers['date'],
            'message_id' => $headers['message_id'],
            'body' => $plainBody,
            'raw' => $raw,
            'headers' => $headers,
        ];
    }

    protected function extractPlainText(string $headerText, string $body): string
    {
        if (stripos($headerText, 'Content-Type: multipart/') !== false) {
            if (preg_match('/boundary="?([^"\s;]+)"?/i', $headerText, $m)) {
                $boundary = $m[1];
                $mimeParts = explode("--{$boundary}", $body);

                foreach ($mimeParts as $part) {
                    if (stripos($part, 'Content-Type: text/plain') !== false) {
                        return $this->decodeMimePart($part);
                    }
                }

                foreach ($mimeParts as $part) {
                    if (stripos($part, 'Content-Type: text/html') !== false) {
                        $decoded = $this->decodeMimePart($part);

                        return strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $decoded));
                    }
                }

                foreach ($mimeParts as $part) {
                    $decoded = $this->decodeMimePart($part);
                    if (! empty(trim($decoded)) && mb_detect_encoding($decoded, 'UTF-8', true)) {
                        return $decoded;
                    }
                }
            }
        }

        return $this->decodeBodyWithHeaders($body, $headerText);
    }

    protected function decodeMimePart(string $part): string
    {
        $sections = preg_split('/\r?\n\r?\n/', $part, 2);
        $partHeaders = $sections[0] ?? '';
        $partBody = trim($sections[1] ?? '');

        if (empty($partBody)) {
            return '';
        }

        if (preg_match('/Content-Transfer-Encoding:\s*(\S+)/i', $partHeaders, $m)) {
            $encoding = strtolower(trim($m[1]));

            if ($encoding === 'base64') {
                $decoded = base64_decode($partBody);

                return ($decoded !== false) ? $decoded : $partBody;
            }
            if ($encoding === 'quoted-printable') {
                return quoted_printable_decode($partBody);
            }
        }

        return $partBody;
    }

    protected function decodeBodyWithHeaders(string $body, string $headerText): string
    {
        if (preg_match('/Content-Transfer-Encoding:\s*quoted-printable/i', $headerText)) {
            return quoted_printable_decode($body);
        }
        if (preg_match('/Content-Transfer-Encoding:\s*base64/i', $headerText)) {
            $decoded = base64_decode($body);

            return ($decoded !== false) ? $decoded : $body;
        }

        return $body;
    }

    protected function sanitizeUtf8(string $text): string
    {
        $text = str_replace("\0", '', $text);

        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        $detected = mb_detect_encoding($text, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], true);
        if ($detected && $detected !== 'UTF-8') {
            $converted = mb_convert_encoding($text, 'UTF-8', $detected);
            if ($converted !== false) {
                return $converted;
            }
        }

        return mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }

    protected function decodeMimeHeader(string $header): string
    {
        if (! str_contains($header, '=?')) {
            return $header;
        }

        // RFC 2047: linear whitespace separating two adjacent encoded-words
        // is not part of the text and must be dropped, otherwise a subject
        // split across several =?...?= chunks gains stray spaces.
        $header = preg_replace('/(\?=)\s+(=\?)/', '$1$2', $header) ?? $header;

        $decoded = preg_replace_callback('/=\?([^?]+)\?([BQ])\?([^?]*)\?=/i', function ($matches) {
            $charset = strtoupper($matches[1]);
            $encoding = strtoupper($matches[2]);
            $text = $matches[3];

            if ($encoding === 'B') {
                $bytes = base64_decode($text, true);
                $bytes = $bytes === false ? $text : $bytes;
            } else { // Q
                $bytes = quoted_printable_decode(str_replace('_', ' ', $text));
            }

            // The decoded bytes are in the word's declared charset, NOT
            // necessarily UTF-8. Convert so we never emit raw Latin-1 /
            // Windows-125x bytes that later blow up json_encode().
            return $this->toUtf8($bytes, $charset);
        }, $header);

        return $decoded ?? $header;
    }

    /**
     * Convert bytes from a (possibly odd) source charset to UTF-8, tolerating
     * unknown/misdeclared charsets. Always returns valid UTF-8.
     */
    protected function toUtf8(string $bytes, string $charset): string
    {
        $charset = trim($charset);
        $normalized = strtoupper($charset);

        if ($normalized === 'UTF-8' || $normalized === 'UTF8' || $charset === '') {
            return Utf8::clean($bytes);
        }

        // iconv understands the widest set of legacy charset labels; //IGNORE
        // drops anything that still won't map instead of failing the whole
        // conversion. Fall back to mbstring, then to a raw scrub.
        $converted = @iconv($charset, 'UTF-8//IGNORE', $bytes);
        if (is_string($converted)) {
            return $converted;
        }

        if (in_array($normalized, array_map('strtoupper', mb_list_encodings()), true)) {
            $converted = @mb_convert_encoding($bytes, 'UTF-8', $charset);
            if (is_string($converted)) {
                return $converted;
            }
        }

        return Utf8::clean($bytes);
    }
}
