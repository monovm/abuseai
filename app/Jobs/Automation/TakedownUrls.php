<?php

namespace App\Jobs\Automation;

use App\Enums\ActionType;
use App\Models\AbuseCase;
use App\Models\Brand;
use App\Models\CaseAction;
use App\Services\Infrastructure\UrlTakedownService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Walk every target_url on the case's linked reports and ask the brand's
 * Custom HTTP API to delete it. Mirrors the manual button on the case
 * detail page, but runs from the automation rules engine.
 */
class TakedownUrls implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 180;

    public function __construct(
        public AbuseCase $case,
    ) {
        $this->onQueue('automation');
    }

    /** Prevent two automation rule evaluations from racing on the same case. */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('takedown-urls:' . $this->case->id))->expireAfter(180)];
    }

    public function handle(UrlTakedownService $takedown): void
    {
        $brand = $this->resolveBrand();
        if (! $brand || ! $takedown->isConfigured($brand)) {
            Log::info('TakedownUrls: brand has no url_takedown config, skipping', [
                'case_id' => $this->case->id,
                'brand_id' => $brand?->id,
            ]);
            return;
        }

        $urls = $this->case->reports()
            ->whereNotNull('target_url')
            ->where('target_url', '!=', '')
            ->pluck('target_url')
            ->map(fn ($u) => $takedown->cleanUrl((string) $u))
            ->unique()
            ->filter(fn ($u) => $takedown->handlesUrl($brand, $u))
            ->values()
            ->all();

        if (empty($urls)) {
            return;
        }

        foreach ($urls as $url) {
            if ($this->alreadyTakenDown($url)) {
                continue;
            }

            $result = $takedown->takedown($brand, $url);

            if (! $result['success']) {
                Log::warning('TakedownUrls: takedown failed', [
                    'case_id' => $this->case->id,
                    'url' => $url,
                    'error' => $result['error'] ?? null,
                ]);
                CaseAction::create([
                    'case_id' => $this->case->id,
                    'action_type' => ActionType::NoteAdded,
                    'payload' => [
                        'type' => 'url_takedown_failed',
                        'url' => $url,
                        'brand' => $brand->name,
                        'error' => $result['error'] ?? null,
                        'automated' => true,
                    ],
                    'note' => "Automated URL takedown via {$brand->name} failed for {$url}: "
                        . ($result['error'] ?? 'unknown error'),
                    'created_at' => now(),
                ]);
                continue;
            }

            CaseAction::create([
                'case_id' => $this->case->id,
                'action_type' => ActionType::NoteAdded,
                'payload' => [
                    'type' => 'url_takedown',
                    'url' => $result['url'],
                    'remote_id' => $result['id'] ?? null,
                    'brand' => $brand->name,
                    'automated' => true,
                ],
                'note' => "Automated URL takedown via {$brand->name}: deleted {$result['url']} (remote id {$result['id']}). "
                    . ($result['message'] ?? ''),
                'created_at' => now(),
            ]);
        }

        $this->case->update(['last_seen_at' => now()]);
    }

    /**
     * Same resolution the manual takedown UI uses: prefer the case's
     * resolved brand, fall back to IP-based lookup. Returns null when
     * neither is available.
     */
    protected function resolveBrand(): ?Brand
    {
        if ($this->case->brand) {
            return $this->case->brand;
        }
        if ($this->case->target_ip) {
            return Brand::findByIp($this->case->target_ip);
        }
        return null;
    }

    /**
     * Don't DELETE the same URL twice. A previous successful takedown
     * (manual or automated) leaves a CaseAction with payload.type =
     * url_takedown — re-deleting would either 404 or worse, hit an
     * unrelated id if the remote reused it.
     */
    protected function alreadyTakenDown(string $url): bool
    {
        return CaseAction::query()
            ->where('case_id', $this->case->id)
            ->where('payload->type', 'url_takedown')
            ->where('payload->url', $url)
            ->exists();
    }
}
