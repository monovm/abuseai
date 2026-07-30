<?php

namespace App\Services\Ingestion;

use App\Enums\AbuseType;
use App\Models\AbuseReport;
use App\Models\Brand;
use App\Models\Reporter;
use App\Support\IpHelpers;
use Illuminate\Support\Facades\Storage;

class ReportNormalizerService
{
    public function fromWebForm(array $data, ?string $reporterIp = null, ?Brand $brand = null): AbuseReport
    {
        $reporter = $this->findOrCreateReporter(
            name: $data['reporter_name'],
            email: $data['reporter_email'],
            type: 'form',
        );

        $attachmentPaths = $this->storeAttachments($data['evidence_files'] ?? []);

        $metadata = $brand
            ? ['brand_id' => $brand->id, 'brand_slug' => $brand->slug, 'submitted_via_host' => request()?->getHost()]
            : null;

        return AbuseReport::create([
            'reporter_id' => $reporter->id,
            'raw_payload' => $data['description'],
            'source' => 'form',
            'abuse_type' => $data['abuse_type'],
            'target_ip' => self::normalizeIp($data['target_ip'] ?? null),
            'target_domain' => $data['target_domain'] ?? null,
            'target_url' => $data['target_url'] ?? null,
            'evidence' => $data['description'],
            'reported_at' => now(),
            'reporter_ip' => self::normalizeIp($reporterIp),
            'attachment_paths' => $attachmentPaths ?: null,
            'metadata' => $metadata,
        ]);
    }

    public function fromApi(array $data, Reporter $reporter, ?string $reporterIp = null): AbuseReport
    {
        return AbuseReport::create([
            'reporter_id' => $reporter->id,
            'raw_payload' => json_encode($data),
            'source' => 'api',
            'abuse_type' => $data['abuse_type'],
            'target_ip' => self::normalizeIp($data['target_ip'] ?? null),
            'target_domain' => $data['target_domain'] ?? null,
            'target_url' => $data['target_url'] ?? null,
            'evidence' => $data['evidence'] ?? $data['description'],
            'headers' => $data['headers'] ?? null,
            'reported_at' => self::parseReportedAt($data['reported_at'] ?? null),
            'reporter_ip' => self::normalizeIp($reporterIp),
        ]);
    }

    public function fromFeed(array $data, Reporter $reporter): AbuseReport
    {
        $evidence = $this->sanitizeUtf8($data['evidence'] ?? '');

        // Strip base64 attachment blocks from raw_payload to avoid storing
        // huge binary data (PDFs, images) in the database column.
        // The evidence field stores the original for reference.
        $payloadData = $data;
        if (isset($payloadData['evidence'])) {
            $payloadData['evidence'] = $this->stripBase64Attachments($payloadData['evidence']);
        }

        return AbuseReport::create([
            'reporter_id' => $reporter->id,
            'raw_payload' => mb_substr(json_encode($payloadData), 0, 16_000_000),
            'source' => 'feed',
            'abuse_type' => $data['abuse_type'] ?? AbuseType::Other->value,
            'target_ip' => self::normalizeIp($data['target_ip'] ?? null),
            'target_domain' => $data['target_domain'] ?? null,
            'target_url' => $data['target_url'] ?? null,
            'evidence' => mb_substr($evidence, 0, 16_000_000),
            'headers' => $data['headers'] ?? null,
            'reported_at' => self::parseReportedAt($data['reported_at'] ?? null),
            'metadata' => $data['metadata'] ?? null,
        ]);
    }

    /**
     * Canonicalize an IP (IPv4 or IPv6) for consistent storage and lookup.
     * Returns null for null input, original string if the value doesn't parse
     * so validation downstream still sees the caller-supplied text.
     */
    protected static function normalizeIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }
        return IpHelpers::canonicalize($ip) ?? $ip;
    }

    /**
     * Strip base64-encoded attachment blocks from MIME email content.
     * Replaces the base64 data with a placeholder to keep the structure readable.
     */
    protected function stripBase64Attachments(string $content): string
    {
        // Match MIME base64 content blocks (large base64 sections after Content-Transfer-Encoding: base64)
        return preg_replace(
            '/(Content-Transfer-Encoding:\s*base64\r?\n\r?\n)[A-Za-z0-9+\/=\r\n]{1000,}/',
            '$1[base64 content stripped - see evidence field]',
            $content
        ) ?? $content;
    }

    /**
     * Best-effort parse of a Date header value. Upstream fetchers sometimes hand
     * us junk (References/X-MS-Exchange/etc. bleeding into Date:), and feeding
     * that into the reported_at datetime cast 500s the whole import. Extract the
     * leading RFC 2822 date token when possible; otherwise fall back to now().
     */
    protected static function parseReportedAt(mixed $value): \Carbon\CarbonInterface
    {
        if ($value instanceof \DateTimeInterface) {
            return \Carbon\Carbon::instance($value);
        }
        if (is_string($value) && $value !== '') {
            try {
                return \Carbon\Carbon::parse($value);
            } catch (\Throwable) {
                // Grab just the first RFC 2822 date — "Thu, 2 Apr 2026 16:24:56 +0000".
                if (preg_match('/[A-Z][a-z]{2},\s*\d{1,2}\s+[A-Z][a-z]{2}\s+\d{2,4}\s+\d{1,2}:\d{2}(?::\d{2})?\s*(?:[+-]\d{4}|[A-Z]{2,4})?/', $value, $m)) {
                    try {
                        return \Carbon\Carbon::parse($m[0]);
                    } catch (\Throwable) {
                    }
                }
            }
        }
        return now();
    }

    protected function findOrCreateReporter(string $name, string $email, string $type): Reporter
    {
        $reporter = Reporter::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'type' => $type,
            ],
        );

        // Auto-tag law enforcement by email domain. Runs on every lookup so newly
        // added allowlist domains also backfill existing reporters. We never
        // auto-disable an LE flag — that must be done manually in the admin UI.
        if (! $reporter->is_law_enforcement && Reporter::emailMatchesLawEnforcement($email)) {
            $reporter->forceFill([
                'is_law_enforcement' => true,
                'is_trusted' => true,
            ])->save();
        }

        return $reporter;
    }

    protected function storeAttachments(array $files): array
    {
        $paths = [];
        $disk = config('filesystems.default') ?: 'local';

        foreach ($files as $file) {
            try {
                $stored = $file->store('evidence/' . date('Y/m'), $disk);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Failed to store abuse evidence file', [
                    'disk' => $disk,
                    'original_name' => method_exists($file, 'getClientOriginalName') ? $file->getClientOriginalName() : null,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if (is_string($stored) && $stored !== '') {
                $paths[] = $stored;
            }
        }

        return $paths;
    }

    /**
     * Pull binary attachments (PDFs, images, archives, etc.) out of a raw
     * RFC822 email and persist them under evidence/Y/m. Returns the list
     * of stored paths so the caller can merge them into attachment_paths
     * alongside the .eml.
     *
     * @return array<int, string>
     */
    public function storeEmailAttachments(string $raw, ?string $disk = null): array
    {
        if ($raw === '') {
            return [];
        }

        $disk = $disk ?: (config('filesystems.default') ?: 'local');
        $extracted = \App\Support\Email\EmailAttachmentExtractor::extractFromRaw($raw);
        $paths = [];

        foreach ($extracted as $att) {
            $safe = \App\Support\Email\EmailAttachmentExtractor::sanitizeFilename($att['filename']);
            $path = 'evidence/' . date('Y/m') . '/' . uniqid() . '-' . $safe;

            try {
                \Illuminate\Support\Facades\Storage::disk($disk)->put($path, $att['bytes']);
                $paths[] = $path;
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Failed to store email attachment', [
                    'disk' => $disk,
                    'filename' => $safe,
                    'mime' => $att['mime'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $paths;
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
}
