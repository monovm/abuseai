<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AbuseReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    public function download(Request $request, AbuseReport $report, int $index): StreamedResponse
    {
        $paths = $report->attachment_paths ?? [];

        abort_unless(isset($paths[$index]), 404, 'Attachment not found');

        $path = $paths[$index];

        // Older reports stored false/null when the configured disk failed.
        // Treat anything that isn't a usable path as missing rather than
        // letting Flysystem 500 on an empty location.
        if (! is_string($path) || trim($path) === '') {
            abort(404, 'Attachment file missing on disk');
        }

        $disk = $this->resolveDisk($path);

        abort_if($disk === null, 404, 'Attachment file missing on disk');

        $filename = basename($path);

        return Storage::disk($disk)->download($path, $filename);
    }

    private function resolveDisk(string $path): ?string
    {
        // Attachments may live on s3 (web form, default email path) or
        // local (fallback when s3 isn't configured). Probe each in turn.
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
                // Disk not configured or unreachable — try the next.
            }
        }

        return null;
    }
}
