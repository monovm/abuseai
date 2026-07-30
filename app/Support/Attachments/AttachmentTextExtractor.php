<?php

namespace App\Support\Attachments;

use App\Services\AI\AiProviderFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Reads abuse-report attachments off disk and turns them into plain text
 * the AI triage / intelligence prompts can ingest. PDFs are the headline
 * use case (CERT and law-enforcement notices often hide IPs and the
 * actual abuse description inside an attached PDF), but text/log/csv/eml
 * are also handled.
 *
 * Output is size-capped per file and overall so a single 50MB log dump
 * doesn't blow the model context window.
 */
class AttachmentTextExtractor
{
    public const MAX_BYTES_PER_FILE = 200_000;
    public const MAX_BYTES_TOTAL = 400_000;
    public const MAX_IMAGE_BYTES = 5_000_000; // images larger than 5MB are skipped for vision
    public const MAX_PDF_BYTES = 30_000_000; // Anthropic doc API limit is 32MB; leave headroom
    public const PDF_TEXT_THRESHOLD = 200;   // below this, treat the PDF as scanned and OCR via vision

    private const IMAGE_EXTS = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

    private const VISION_SYSTEM_PROMPT = 'You are an OCR extractor for an abuse-report intake pipeline. Read the image and return every piece of text it contains verbatim, in reading order.';
    private const VISION_USER_PROMPT = 'Extract all visible text from this image. Include every IP address, domain, URL, port, hash, timestamp, ticket number, and email address exactly as shown. Return plain text only — no commentary, no markdown, no explanation. If the image has no text, return the single word: NONE';

    /**
     * Extract concatenated text from every attachment path on a report.
     * Returns an empty string when no attachments yield readable text.
     *
     * @param array<int, string|null> $paths
     * @param array<string, int>|null $extracted Optional out-param:
     *        filename => bytes-extracted, useful for audit metadata.
     */
    public static function fromPaths(array $paths, ?array &$extracted = null): string
    {
        $extracted = [];
        $chunks = [];
        $total = 0;

        foreach ($paths as $path) {
            if (! is_string($path) || trim($path) === '') {
                continue;
            }
            if ($total >= self::MAX_BYTES_TOTAL) {
                break;
            }

            $disk = self::resolveDisk($path);
            if ($disk === null) {
                continue;
            }

            $filename = basename($path);
            try {
                $text = self::extractOne($disk, $path);
            } catch (\Throwable $e) {
                Log::warning('Attachment text extraction failed', [
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            // Attachments (a Windows-1250 Polish .eml, a Latin-1 log)
            // routinely carry non-UTF-8 bytes. Scrub them at the source
            // so the text is safe to store, display, and — critically —
            // JSON-encode into an AI request.
            $text = \App\Support\Text\Utf8::clean(trim($text));
            if ($text === '') {
                continue;
            }

            if (strlen($text) > self::MAX_BYTES_PER_FILE) {
                $text = substr($text, 0, self::MAX_BYTES_PER_FILE) . "\n…[truncated]";
            }

            $remaining = self::MAX_BYTES_TOTAL - $total;
            if (strlen($text) > $remaining) {
                $text = substr($text, 0, $remaining) . "\n…[truncated]";
            }

            $chunks[] = "=== Attachment: {$filename} ===\n{$text}";
            $extracted[$filename] = strlen($text);
            $total += strlen($text);
        }

        return implode("\n\n", $chunks);
    }

    protected static function extractOne(string $disk, string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($ext, self::IMAGE_EXTS, true)) {
            return self::extractImage($disk, $path);
        }

        return match ($ext) {
            'pdf' => self::extractPdf($disk, $path),
            'eml' => self::extractEml($disk, $path),
            'txt', 'log', 'csv', 'json', 'xml', 'html', 'htm' => self::extractText($disk, $path),
            default => self::extractByMime($disk, $path),
        };
    }

    /**
     * OCR an image via the active AI provider's vision endpoint
     * (Claude or OpenAI). Cached per disk+path so re-triage doesn't
     * re-bill for the same file.
     */
    protected static function extractImage(string $disk, string $path): string
    {
        $cacheKey = 'attachment_text:' . sha1($disk . ':' . $path);
        $cached = Cache::get($cacheKey);
        if (is_string($cached)) {
            return $cached;
        }

        try {
            $size = Storage::disk($disk)->size($path);
        } catch (\Throwable) {
            $size = null;
        }
        if ($size !== null && $size > self::MAX_IMAGE_BYTES) {
            Log::info('Image too large for vision OCR — skipping', ['path' => $path, 'size' => $size]);
            return '';
        }

        $bytes = Storage::disk($disk)->get($path);
        if (! is_string($bytes) || $bytes === '') {
            return '';
        }

        try {
            $mime = Storage::disk($disk)->mimeType($path) ?: 'image/png';
        } catch (\Throwable) {
            $mime = 'image/png';
        }

        $provider = AiProviderFactory::make();
        if (! method_exists($provider, 'completeWithImage')) {
            Log::info('Active AI provider does not support vision — skipping image OCR', [
                'provider' => $provider->getProviderName(),
                'path' => $path,
            ]);
            return '';
        }

        try {
            $text = $provider->completeWithImage(
                self::VISION_SYSTEM_PROMPT,
                self::VISION_USER_PROMPT,
                $bytes,
                $mime,
            );
        } catch (\Throwable $e) {
            Log::warning('Vision OCR failed', ['path' => $path, 'error' => $e->getMessage()]);
            return '';
        }

        $text = is_string($text) ? trim($text) : '';
        if ($text === '' || strtoupper($text) === 'NONE') {
            $text = '';
        }

        // Cache for 7 days — files at evidence/Y/m/<uniqid>-name are immutable.
        Cache::put($cacheKey, $text, now()->addDays(7));
        return $text;
    }

    protected static function extractPdf(string $disk, string $path): string
    {
        $bytes = Storage::disk($disk)->get($path);
        if ($bytes === null || $bytes === '') {
            return '';
        }

        // First try smalot — pure PHP, free, fast, but only sees
        // embedded text (PDFs that are images-of-text return nothing).
        $text = '';
        if (class_exists(\Smalot\PdfParser\Parser::class)) {
            try {
                $parser = new \Smalot\PdfParser\Parser();
                $doc = $parser->parseContent($bytes);
                $text = trim($doc->getText());
            } catch (\Throwable $e) {
                Log::info('Smalot PDF parse failed, will try vision fallback', [
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            Log::info('Smalot\\PdfParser not installed — relying on vision fallback', ['path' => $path]);
        }

        if (strlen($text) >= self::PDF_TEXT_THRESHOLD) {
            return $text;
        }

        // Scanned / image-based PDF: send to the active AI provider's
        // document endpoint (Claude reads PDFs natively, including
        // images of text). Result is cached by disk+path so re-triage
        // and split-sibling triage don't re-bill.
        $vision = self::extractPdfViaVision($disk, $path, $bytes);
        if ($vision !== '') {
            return $vision;
        }

        // Best effort: return whatever smalot got, even if short.
        return $text;
    }

    protected static function extractPdfViaVision(string $disk, string $path, string $bytes): string
    {
        $cacheKey = 'attachment_pdf_vision:' . sha1($disk . ':' . $path);
        $cached = Cache::get($cacheKey);
        if (is_string($cached)) {
            return $cached;
        }

        if (strlen($bytes) > self::MAX_PDF_BYTES) {
            Log::info('PDF too large for document API — skipping vision fallback', [
                'path' => $path,
                'size' => strlen($bytes),
            ]);
            return '';
        }

        $provider = AiProviderFactory::make();
        if (! method_exists($provider, 'completeWithDocument')) {
            Log::info('Active AI provider does not support PDF document mode — skipping vision fallback', [
                'provider' => $provider->getProviderName(),
                'path' => $path,
            ]);
            return '';
        }

        try {
            $text = $provider->completeWithDocument(
                self::VISION_SYSTEM_PROMPT,
                self::VISION_USER_PROMPT,
                $bytes,
                'application/pdf',
            );
        } catch (\Throwable $e) {
            Log::warning('PDF vision OCR failed', ['path' => $path, 'error' => $e->getMessage()]);
            return '';
        }

        $text = is_string($text) ? trim($text) : '';
        if ($text === '' || strtoupper($text) === 'NONE') {
            $text = '';
        }

        Cache::put($cacheKey, $text, now()->addDays(7));
        return $text;
    }

    protected static function extractEml(string $disk, string $path): string
    {
        $raw = Storage::disk($disk)->get($path);
        if (! is_string($raw) || $raw === '') {
            return '';
        }

        // Synthesise the array shape EmailTextExtractor expects.
        return \App\Support\Email\EmailTextExtractor::allText(['raw' => $raw]);
    }

    protected static function extractText(string $disk, string $path): string
    {
        $bytes = Storage::disk($disk)->get($path);
        if (! is_string($bytes) || $bytes === '') {
            return '';
        }

        // Keep the raw bytes — the AI prompt can deal with HTML tags.
        return $bytes;
    }

    /**
     * Fallback for files without a known extension. We sniff the
     * mime type and route PDFs (sometimes uploaded without .pdf) and
     * text/* through the same paths; anything else is skipped.
     */
    protected static function extractByMime(string $disk, string $path): string
    {
        try {
            $mime = Storage::disk($disk)->mimeType($path);
        } catch (\Throwable) {
            return '';
        }

        if ($mime === 'application/pdf') {
            return self::extractPdf($disk, $path);
        }
        if ($mime === 'message/rfc822') {
            return self::extractEml($disk, $path);
        }
        if (is_string($mime) && str_starts_with($mime, 'text/')) {
            return self::extractText($disk, $path);
        }
        if (is_string($mime) && str_starts_with($mime, 'image/')) {
            return self::extractImage($disk, $path);
        }

        return '';
    }

    /**
     * Probe the configured disks until we find one holding the file.
     * Mirrors AttachmentController so a path stored on either local or
     * s3 can be located without callers caring.
     */
    protected static function resolveDisk(string $path): ?string
    {
        $candidates = array_unique(array_filter([
            config('filesystems.default'),
            's3',
            'local',
        ]));

        foreach ($candidates as $disk) {
            try {
                if (Storage::disk($disk)->exists($path)) {
                    return $disk;
                }
            } catch (\Throwable) {
                // Disk not configured / unreachable — try the next.
            }
        }

        return null;
    }
}
