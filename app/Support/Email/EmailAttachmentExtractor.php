<?php

namespace App\Support\Email;

/**
 * Walks every MIME part in a raw RFC822 message and returns binary
 * attachments (PDFs, images, archives, etc.) as decoded bytes.
 *
 * The body parser in ParsesMimeEmail intentionally only pulls text/plain
 * and text/html — it ignores everything else. This complements that path
 * so importers can persist real file attachments alongside the .eml.
 */
class EmailAttachmentExtractor
{
    /**
     * @return array<int, array{filename: string, mime: string, bytes: string}>
     */
    public static function extractFromRaw(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $found = [];
        self::walk($raw, $found);

        return $found;
    }

    /**
     * @param array<int, array{filename: string, mime: string, bytes: string}> $found
     */
    protected static function walk(string $raw, array &$found, int $depth = 0): void
    {
        if ($depth > 10) {
            return;
        }

        $split = preg_split('/\r?\n\r?\n/', $raw, 2);
        $headerText = $split[0] ?? '';
        $body = $split[1] ?? '';

        if (stripos($headerText, 'Content-Type: multipart/') === false) {
            return;
        }

        if (! preg_match('/boundary="?([^"\s;]+)"?/i', $headerText, $m)) {
            return;
        }

        $boundary = $m[1];
        $parts = explode("--{$boundary}", $body);

        foreach ($parts as $part) {
            $part = ltrim($part, "\r\n");
            if ($part === '' || str_starts_with($part, '--')) {
                continue;
            }

            $partSplit = preg_split('/\r?\n\r?\n/', $part, 2);
            $partHeaders = $partSplit[0] ?? '';
            if ($partHeaders === '') {
                continue;
            }

            $partType = '';
            if (preg_match('/Content-Type:\s*([^\s;]+)/i', $partHeaders, $tm)) {
                $partType = strtolower(trim($tm[1]));
            }

            if (str_starts_with($partType, 'multipart/')) {
                self::walk($part, $found, $depth + 1);
                continue;
            }

            $disposition = '';
            if (preg_match('/Content-Disposition:\s*([^\s;]+)/i', $partHeaders, $dm)) {
                $disposition = strtolower(trim($dm[1]));
            }

            $filename = self::parseFilename($partHeaders);

            // It's an attachment if the disposition says so, a filename is
            // present, or the content type is something we can't render as
            // body text (PDF, images, archives, octet-stream, etc.).
            $looksBinary = $partType !== ''
                && ! str_starts_with($partType, 'text/')
                && $partType !== 'multipart'
                && $partType !== 'message/rfc822';

            $isAttachment = $disposition === 'attachment'
                || ($filename !== null && $filename !== '')
                || ($disposition === '' && $looksBinary);

            if (! $isAttachment) {
                continue;
            }

            $bytes = self::decodeBody($partHeaders, $partSplit[1] ?? '');
            if ($bytes === '') {
                continue;
            }

            $found[] = [
                'filename' => $filename ?: self::fallbackName($partType, count($found)),
                'mime' => $partType ?: 'application/octet-stream',
                'bytes' => $bytes,
            ];
        }
    }

    protected static function parseFilename(string $headers): ?string
    {
        // Prefer Content-Disposition filename; fall back to Content-Type name=
        $name = null;

        if (preg_match('/Content-Disposition:[^\n]*?filename\*?=([^;\r\n]+)/i', $headers, $m)) {
            $name = trim($m[1], " \t\"'");
        } elseif (preg_match('/Content-Type:[^\n]*?name\*?=([^;\r\n]+)/i', $headers, $m)) {
            $name = trim($m[1], " \t\"'");
        }

        if ($name === null || $name === '') {
            return null;
        }

        // RFC 2231 (filename*=UTF-8''xx%20yy) — strip charset prefix and url-decode.
        if (preg_match("/^[A-Za-z0-9_-]+''(.+)$/", $name, $rm)) {
            $name = rawurldecode($rm[1]);
        }

        // RFC 2047 encoded-words (=?UTF-8?B?...?=).
        if (str_contains($name, '=?')) {
            $name = preg_replace_callback('/=\?([^?]+)\?([BQ])\?([^?]+)\?=/i', function ($matches) {
                $text = $matches[3];
                if (strtoupper($matches[2]) === 'B') {
                    return base64_decode($text) ?: $text;
                }
                return quoted_printable_decode(str_replace('_', ' ', $text));
            }, $name) ?? $name;
        }

        return $name;
    }

    protected static function decodeBody(string $headers, string $body): string
    {
        $body = trim($body);
        if ($body === '') {
            return '';
        }

        if (preg_match('/Content-Transfer-Encoding:\s*(\S+)/i', $headers, $m)) {
            $encoding = strtolower(trim($m[1]));
            if ($encoding === 'base64') {
                $decoded = base64_decode($body, true);
                return $decoded !== false ? $decoded : '';
            }
            if ($encoding === 'quoted-printable') {
                return quoted_printable_decode($body);
            }
        }

        return $body;
    }

    protected static function fallbackName(string $mime, int $idx): string
    {
        $ext = match (true) {
            $mime === 'application/pdf' => 'pdf',
            $mime === 'application/zip' => 'zip',
            $mime === 'application/gzip' => 'gz',
            $mime === 'application/json' => 'json',
            $mime === 'application/xml', $mime === 'text/xml' => 'xml',
            str_starts_with($mime, 'image/') => substr($mime, 6),
            str_starts_with($mime, 'audio/') => substr($mime, 6),
            str_starts_with($mime, 'video/') => substr($mime, 6),
            default => 'bin',
        };

        return 'attachment-' . ($idx + 1) . '.' . $ext;
    }

    /**
     * Strip path separators and other unsafe characters from a filename so
     * it can't escape the storage directory. Keeps the extension.
     */
    public static function sanitizeFilename(string $name): string
    {
        $name = basename($name);
        $name = preg_replace('/[^\w.\-]+/u', '_', $name) ?? 'attachment.bin';
        $name = trim($name, '._');

        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'attachment.bin';
        }

        if (mb_strlen($name) > 120) {
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $base = pathinfo($name, PATHINFO_FILENAME);
            $name = mb_substr($base, 0, 110) . ($ext ? '.' . $ext : '');
        }

        return $name;
    }
}
