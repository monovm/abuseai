<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAiProvider implements AiProviderInterface
{
    protected string $apiKey;
    protected string $model;
    protected int $maxTokens;
    protected string $baseUrl;
    protected int $retryAttempts;

    public function __construct()
    {
        $this->apiKey = config('ai.providers.openai.api_key') ?? '';
        $this->model = Cache::get('ai_openai_model', config('ai.providers.openai.model') ?? 'gpt-4o');
        $this->maxTokens = config('ai.max_tokens', 1024);
        $this->baseUrl = config('ai.providers.openai.base_url') ?? 'https://api.openai.com';
        $this->retryAttempts = config('abusedesk.ai.retry_attempts', 3);
    }

    public function getProviderName(): string
    {
        return 'openai';
    }

    public function getModelName(): string
    {
        return $this->model;
    }

    public function complete(string $systemPrompt, string $userMessage, ?int $maxTokens = null): ?string
    {
        return $this->completeWithUsage($systemPrompt, $userMessage, $maxTokens, false)->text;
    }

    public function completeJson(string $systemPrompt, string $userMessage, ?int $maxTokens = null): ?array
    {
        $result = $this->completeWithUsage($systemPrompt, $userMessage, $maxTokens, true);
        return $result->text ? $this->parseJson($result->text) : null;
    }

    public function completeWithUsage(string $systemPrompt, string $userMessage, ?int $maxTokens = null, bool $jsonMode = false): UsageResult
    {
        try {
            return $this->doRequest($systemPrompt, $userMessage, $maxTokens ?? $this->maxTokens, $jsonMode);
        } catch (\Throwable $e) {
            // Surface the real cause (HTTP status + body, e.g. an
            // unsupported model or a json_object/response_format
            // rejection) instead of letting it bubble up as a bare
            // "no usable result" with nothing in the logs.
            Log::error('OpenAI API error: ' . $e->getMessage());
            return new UsageResult(null, $this->getProviderName(), $this->model);
        }
    }

    protected function doRequest(string $systemPrompt, string $userMessage, int $maxTokens, bool $jsonMode): UsageResult
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->retryAttempts) {
            $attempt++;

            try {
                $body = [
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userMessage],
                    ],
                ];

                // Newer models (o1, o3, gpt-4.1, gpt-5, etc.) use max_completion_tokens
                // Older models (gpt-4o, gpt-4-turbo) use max_tokens
                if ($this->usesCompletionTokens()) {
                    $body['max_completion_tokens'] = $maxTokens;
                } else {
                    $body['max_tokens'] = $maxTokens;
                }

                if ($jsonMode) {
                    $body['response_format'] = ['type' => 'json_object'];
                }

                $response = Http::withHeaders([
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type' => 'application/json',
                ])->timeout(120)->post("{$this->baseUrl}/v1/chat/completions", $body);

                if ($response->successful()) {
                    $json = $response->json();

                    // finish_reason=length means the model was cut off at
                    // the token cap: the JSON comes back truncated (or, on
                    // reasoning models that spend the budget thinking,
                    // empty). Log it so the cause is visible.
                    if (($json['choices'][0]['finish_reason'] ?? null) === 'length') {
                        Log::warning('OpenAI: response hit the token cap (finish_reason=length) — raise ai.max_tokens', [
                            'model' => $json['model'] ?? $this->model,
                            'completion_tokens' => $json['usage']['completion_tokens'] ?? null,
                        ]);
                    }

                    return new UsageResult(
                        text: $json['choices'][0]['message']['content'] ?? '',
                        provider: $this->getProviderName(),
                        model: $json['model'] ?? $this->model,
                        inputTokens: (int) ($json['usage']['prompt_tokens'] ?? 0),
                        outputTokens: (int) ($json['usage']['completion_tokens'] ?? 0),
                    );
                }

                if ($response->status() === 429 && $attempt < $this->retryAttempts) {
                    sleep(pow(2, $attempt));
                    continue;
                }

                throw new \RuntimeException("OpenAI API: HTTP {$response->status()} - {$response->body()}");
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                $lastException = $e;
                if ($attempt < $this->retryAttempts) {
                    sleep(pow(2, $attempt));
                    continue;
                }
            }
        }

        throw $lastException ?? new \RuntimeException('OpenAI API failed after retries');
    }

    /**
     * Vision: send a single image plus a text prompt to GPT-4o (or
     * whatever vision-capable model is configured) and return the
     * model's text response.
     */
    public function completeWithImage(
        string $systemPrompt,
        string $userPrompt,
        string $imageBytes,
        string $mime,
        ?int $maxTokens = null,
    ): ?string {
        $maxTokens = $maxTokens ?? $this->maxTokens;
        $dataUri = 'data:' . $mime . ';base64,' . base64_encode($imageBytes);

        try {
            $body = [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => $userPrompt],
                            ['type' => 'image_url', 'image_url' => ['url' => $dataUri]],
                        ],
                    ],
                ],
            ];
            if ($this->usesCompletionTokens()) {
                $body['max_completion_tokens'] = $maxTokens;
            } else {
                $body['max_tokens'] = $maxTokens;
            }

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
            ])->timeout(120)->post("{$this->baseUrl}/v1/chat/completions", $body);

            if (! $response->successful()) {
                Log::warning('OpenAI vision: HTTP error', [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 500),
                ]);
                return null;
            }

            $json = $response->json();
            return $json['choices'][0]['message']['content'] ?? null;
        } catch (\Throwable $e) {
            Log::warning('OpenAI vision: exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    protected function parseJson(string $response): ?array
    {
        $cleaned = trim($response);
        if (str_starts_with($cleaned, '```json')) {
            $cleaned = substr($cleaned, 7);
        }
        if (str_starts_with($cleaned, '```')) {
            $cleaned = substr($cleaned, 3);
        }
        if (str_ends_with($cleaned, '```')) {
            $cleaned = substr($cleaned, 0, -3);
        }

        $decoded = json_decode(trim($cleaned), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('OpenAI: failed to parse JSON', ['error' => json_last_error_msg()]);
            return null;
        }

        return $decoded;
    }

    /**
     * Newer OpenAI models require max_completion_tokens instead of max_tokens.
     */
    protected function usesCompletionTokens(): bool
    {
        $model = strtolower($this->model);

        // o-series models always use max_completion_tokens
        if (preg_match('/^o[1-9]/', $model)) {
            return true;
        }

        // gpt-4.1+ and gpt-5+ use max_completion_tokens
        if (preg_match('/^gpt-(\d+)\.(\d+)/', $model, $m)) {
            $major = (int) $m[1];
            $minor = (int) $m[2];
            if ($major >= 5 || ($major === 4 && $minor >= 1)) {
                return true;
            }
        }

        // gpt-5 without minor version
        if (str_starts_with($model, 'gpt-5')) {
            return true;
        }

        return false;
    }
}
