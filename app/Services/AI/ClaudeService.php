<?php

namespace App\Services\AI;

use App\Models\AuditLog;
use App\Support\Text\Utf8;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class ClaudeService
{
    protected AiProviderInterface $provider;
    protected bool $logCalls;
    protected int $maxConcurrent;

    public function __construct(?AiProviderInterface $provider = null)
    {
        $this->provider = $provider ?? AiProviderFactory::make();
        $this->logCalls = config('abusedesk.ai.log_calls', true);
        $this->maxConcurrent = config('abusedesk.ai.max_concurrent', 10);
    }

    public function complete(string $systemPrompt, string $userMessage, ?int $maxTokens = null): ?string
    {
        // Report text can carry non-UTF-8 bytes (a Windows-1250 / Latin
        // .eml). Scrub them here or json_encode of the request body
        // fails and the call never reaches the provider.
        $systemPrompt = Utf8::clean($systemPrompt);
        $userMessage = Utf8::clean($userMessage);

        if (! $this->acquireSemaphore()) {
            Log::warning('AI: max concurrent calls reached, request dropped');
            return null;
        }

        try {
            $result = $this->provider->completeWithUsage($systemPrompt, $userMessage, $maxTokens, false);

            if ($this->logCalls) {
                $this->logAiCall($systemPrompt, $userMessage, $result->text, $result);
            }

            return $result->text;
        } catch (\Throwable $e) {
            Log::error('AI error: ' . $e->getMessage());
            return null;
        } finally {
            $this->releaseSemaphore();
        }
    }

    public function completeJson(string $systemPrompt, string $userMessage, ?int $maxTokens = null): ?array
    {
        // See complete(): strip invalid UTF-8 so the request body can be
        // JSON-encoded — otherwise the provider call throws before it
        // is even sent.
        $systemPrompt = Utf8::clean($systemPrompt);
        $userMessage = Utf8::clean($userMessage);

        if (! $this->acquireSemaphore()) {
            Log::warning('AI: max concurrent calls reached, request dropped');
            return null;
        }

        try {
            $result = $this->provider->completeWithUsage($systemPrompt, $userMessage, $maxTokens, true);

            if (! $result->text) {
                Log::warning('AI JSON: provider returned an empty response', [
                    'provider' => $result->provider,
                    'model' => $result->model,
                ]);
            }

            $parsed = $result->text ? $this->parseJson($result->text) : null;

            if ($this->logCalls) {
                $this->logAiCall($systemPrompt, $userMessage, json_encode($parsed), $result);
            }

            return $parsed;
        } catch (\Throwable $e) {
            Log::error('AI JSON error: ' . $e->getMessage());
            return null;
        } finally {
            $this->releaseSemaphore();
        }
    }

    public function getProvider(): AiProviderInterface
    {
        return $this->provider;
    }

    public function getProviderName(): string
    {
        return $this->provider->getProviderName();
    }

    public function getModelName(): string
    {
        return $this->provider->getModelName();
    }

    protected function acquireSemaphore(): bool
    {
        try {
            $key = 'ai_semaphore';
            $current = (int) Redis::get($key);
            if ($current >= $this->maxConcurrent) {
                return false;
            }
            Redis::incr($key);
            // Self-healing TTL. If a PHP fatal kills the request before
            // the matching release runs — e.g. max_execution_time on a
            // slow synchronous re-triage — the leaked permit would
            // otherwise pin the counter and wedge every later AI call,
            // so triage stays broken no matter how often you retry.
            // The expiry lets a leaked counter clear on its own.
            Redis::expire($key, 300);
            return true;
        } catch (\Throwable) {
            return true;
        }
    }

    protected function releaseSemaphore(): void
    {
        try {
            $key = 'ai_semaphore';
            if ((int) Redis::get($key) > 0) {
                Redis::decr($key);
            }
        } catch (\Throwable) {
        }
    }

    protected function parseJson(string $response): ?array
    {
        $cleaned = trim($response);
        if (str_starts_with($cleaned, '```json')) {
            $cleaned = substr($cleaned, 7);
        } elseif (str_starts_with($cleaned, '```')) {
            $cleaned = substr($cleaned, 3);
        }
        if (str_ends_with($cleaned, '```')) {
            $cleaned = substr($cleaned, 0, -3);
        }
        $cleaned = trim($cleaned);

        // Some models wrap the JSON in a sentence ("Here is the result: {...}").
        // Narrow to the outermost object/array if there is leading prose.
        $firstBrace = strcspn($cleaned, '{[');
        if ($firstBrace > 0 && $firstBrace < strlen($cleaned)) {
            $cleaned = substr($cleaned, $firstBrace);
        }

        $decoded = json_decode($cleaned, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        // The response was most likely cut off at the model's max_tokens
        // cap, leaving the JSON truncated mid-object. Repair it by closing
        // any open string and brackets so the leading, complete fields
        // (e.g. classification + noise) survive instead of the whole
        // triage result being lost.
        $repaired = $this->repairTruncatedJson($cleaned);
        if ($repaired !== null) {
            $decoded = json_decode($repaired, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                Log::warning('AI JSON: recovered a truncated response — raise ai.max_tokens', [
                    'response_length' => strlen($response),
                ]);
                return $decoded;
            }
        }

        Log::warning('AI JSON: failed to parse model response', [
            'json_error' => json_last_error_msg(),
            'response_length' => strlen($response),
            'snippet' => mb_substr($cleaned, 0, 400),
        ]);

        return null;
    }

    /**
     * Best-effort repair of a JSON value that was cut off before it
     * finished — typically because the model hit its max_tokens cap.
     * Tracks string-literal state and bracket depth, drops any dangling
     * partial key/value, and appends the missing closers. Returns null
     * when the input is not a truncated object/array worth repairing.
     */
    protected function repairTruncatedJson(string $s): ?string
    {
        $s = rtrim($s);
        if ($s === '' || ($s[0] !== '{' && $s[0] !== '[')) {
            return null;
        }

        $stack = [];
        $inString = false;
        $escaped = false;

        for ($i = 0, $len = strlen($s); $i < $len; $i++) {
            $c = $s[$i];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($c === '\\') {
                    $escaped = true;
                } elseif ($c === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($c === '"') {
                $inString = true;
            } elseif ($c === '{' || $c === '[') {
                $stack[] = $c;
            } elseif ($c === '}' || $c === ']') {
                array_pop($stack);
            }
        }

        // Nothing left open — it was not truncated, so don't "repair" it.
        if (! $inString && empty($stack)) {
            return null;
        }

        // Close a dangling string literal first.
        if ($inString) {
            $s .= '"';
        }

        // Drop a trailing comma, bare colon, or a "key": with no value —
        // any of which would make the appended closers invalid JSON.
        $s = rtrim($s);
        $s = preg_replace('/(,|:)\s*$/', '', $s);
        $s = preg_replace('/,?\s*"(?:[^"\\\\]|\\\\.)*"\s*:\s*$/', '', $s);
        $s = rtrim((string) $s);
        $s = preg_replace('/,\s*$/', '', (string) $s);

        // Append the missing closing brackets, innermost first.
        for ($j = count($stack) - 1; $j >= 0; $j--) {
            $s .= ($stack[$j] === '{') ? '}' : ']';
        }

        return $s;
    }

    protected function logAiCall(string $systemPrompt, string $userMessage, ?string $response, UsageResult $usage): void
    {
        try {
            $prompt = "[{$usage->provider}:{$usage->model}]\nSystem: {$systemPrompt}\n\nUser: {$userMessage}";

            AuditLog::create([
                'entity_type' => 'AI',
                'entity_id' => Str::uuid()->toString(),
                'event' => 'ai_call',
                'provider' => $usage->provider,
                'model' => $usage->model,
                'input_tokens' => $usage->inputTokens,
                'output_tokens' => $usage->outputTokens,
                'cost_usd' => $this->computeCost($usage),
                'ai_prompt' => mb_substr($prompt, 0, 16_000_000),
                'ai_response' => $response ? mb_substr($response, 0, 16_000_000) : null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to log AI call: ' . $e->getMessage());
        }
    }

    protected function computeCost(UsageResult $usage): float
    {
        $pricing = config("ai.pricing.{$usage->provider}.{$usage->model}");
        if (! is_array($pricing)) {
            return 0.0;
        }

        $inputRate  = (float) ($pricing['input']  ?? 0);
        $outputRate = (float) ($pricing['output'] ?? 0);

        return round(
            ($usage->inputTokens  / 1_000_000) * $inputRate
          + ($usage->outputTokens / 1_000_000) * $outputRate,
            6
        );
    }
}
