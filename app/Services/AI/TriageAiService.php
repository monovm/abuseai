<?php

namespace App\Services\AI;

class TriageAiService
{
    public function __construct(
        protected ClaudeService $claude,
    ) {}

    /**
     * Run classify + noise + parse in a single AI call. Caches the
     * result by content hash for 24h so split-sibling triage doesn't
     * re-bill the API for the same content.
     *
     * Pass $forceFresh = true to skip the cache read and always call the
     * provider — used by the operator-triggered "Re-run AI Triage"
     * button, which must genuinely re-run (a cached result is often the
     * very reason the operator is re-triaging) rather than instantly
     * replaying the stored answer.
     *
     * Returns the combined JSON shape, or null on failure.
     */
    public function triageAll(string $reportContent, array $reporterContext = [], bool $forceFresh = false): ?array
    {
        if (trim($reportContent) === '') {
            return null;
        }

        $systemPrompt = config('ai.prompts.triage.combined');

        $userMessage = $reportContent;
        if (! empty($reporterContext)) {
            $header = "Reporter context (treat these identifiers as REPORTER, never TARGET):\n";
            foreach ($reporterContext as $key => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                $header .= "- {$key}: {$value}\n";
            }
            $userMessage = $header . "\n--- BEGIN REPORT ---\n" . $reportContent . "\n--- END REPORT ---";
        }

        // Key on the prompt too: a prompt revision changes the expected
        // response shape, so a result cached under the old prompt must
        // not be served to code expecting the new shape.
        $cacheKey = 'triage_all:' . sha1($systemPrompt . "\n" . $userMessage);
        if (! $forceFresh) {
            $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);
            if ($this->isUsable($cached)) {
                return $cached;
            }
        }

        $result = $this->normalizeShape($this->claude->completeJson($systemPrompt, $userMessage));

        // Only cache a well-formed result. Caching a malformed or partial
        // response (a transient provider hiccup, or JSON truncated past
        // the token cap) would pin that failure for 24h — every "Re-run
        // AI Triage" retry would hit the poisoned entry and never recover.
        if ($this->isUsable($result)) {
            \Illuminate\Support\Facades\Cache::put($cacheKey, $result, now()->addDay());
        }

        return $result;
    }

    /**
     * A triage result is only usable once it carries the classification
     * block callers read. Anything else (null, a list, a partial object)
     * must not be cached or treated as a hit.
     */
    protected function isUsable(mixed $result): bool
    {
        return is_array($result) && isset($result['classification']) && is_array($result['classification']);
    }

    /**
     * The combined prompt asks for {classification, noise, iocs}, but a
     * model under plain json_object mode is only forced to emit *valid*
     * JSON, not that exact shape. Some responses come back wrapped in an
     * extra key, or flattened to the bare classifier shape ({type,
     * confidence, ...}). Re-shape those into the contract callers expect
     * instead of discarding an otherwise-good triage result.
     */
    protected function normalizeShape(mixed $result): mixed
    {
        if (! is_array($result)) {
            return $result;
        }

        if (isset($result['classification']) && is_array($result['classification'])) {
            return $result;
        }

        // Wrapped under a single key, e.g. {"result": {...}} / {"triage": {...}}.
        if (count($result) === 1) {
            $inner = reset($result);
            if (is_array($inner) && isset($inner['classification']) && is_array($inner['classification'])) {
                return $inner;
            }
        }

        // Flattened to the bare classifier shape — lift it under
        // "classification" and keep any sibling noise / iocs blocks.
        if (isset($result['type']) || array_key_exists('is_abuse_report', $result)) {
            $iocs = $result['iocs'] ?? null;

            return [
                'classification' => [
                    'type' => $result['type'] ?? 'other',
                    'confidence' => $result['confidence'] ?? 0,
                    'is_abuse_report' => $result['is_abuse_report'] ?? true,
                    'summary' => $result['summary'] ?? ($result['evidence_summary'] ?? null),
                ],
                'noise' => is_array($result['noise'] ?? null) ? $result['noise'] : null,
                'iocs' => is_array($iocs) ? $iocs : null,
            ];
        }

        return $result;
    }

    public function classify(string $reportContent): ?array
    {
        return $this->claude->completeJson(
            config('ai.prompts.triage.classifier'),
            $reportContent,
        );
    }

    public function filterNoise(string $reportContent): ?array
    {
        return $this->claude->completeJson(
            config('ai.prompts.triage.noise_filter'),
            $reportContent,
        );
    }

    public function parseReport(string $reportContent, array $reporterContext = []): ?array
    {
        $userMessage = $reportContent;

        if (! empty($reporterContext)) {
            $header = "Reporter context (treat these identifiers as REPORTER, never TARGET):\n";
            foreach ($reporterContext as $key => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                $header .= "- {$key}: {$value}\n";
            }
            $userMessage = $header . "\n--- BEGIN REPORT ---\n" . $reportContent . "\n--- END REPORT ---";
        }

        return $this->claude->completeJson(
            config('ai.prompts.triage.parser'),
            $userMessage,
        );
    }
}
