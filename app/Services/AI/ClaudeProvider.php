<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ClaudeProvider implements AiProviderInterface
{
    protected string $apiKey;
    protected string $model;
    protected int $maxTokens;
    protected string $baseUrl;
    protected int $retryAttempts;

    public function __construct()
    {
        $this->apiKey = config('ai.providers.claude.api_key') ?? '';
        $this->model = \Illuminate\Support\Facades\Cache::get('ai_claude_model', config('ai.providers.claude.model') ?? 'claude-sonnet-5');
        $this->maxTokens = config('ai.max_tokens', 1024);
        $this->baseUrl = config('ai.providers.claude.base_url') ?? 'https://api.anthropic.com';
        $this->retryAttempts = config('abusedesk.ai.retry_attempts', 3);
    }

    public function getProviderName(): string
    {
        return 'claude';
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
        $maxTokens = $maxTokens ?? $this->maxTokens;

        try {
            return $this->callApi($systemPrompt, $userMessage, $maxTokens);
        } catch (\Throwable $e) {
            Log::error('Claude API error: ' . $e->getMessage());
            return new UsageResult(null, $this->getProviderName(), $this->model);
        }
    }

    protected function callApi(string $systemPrompt, string $userMessage, int $maxTokens): UsageResult
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->retryAttempts) {
            $attempt++;

            try {
                $response = Http::withHeaders([
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])->timeout(120)->post("{$this->baseUrl}/v1/messages", [
                    'model' => $this->model,
                    'max_tokens' => $maxTokens,
                    'system' => $systemPrompt,
                    'messages' => [
                        ['role' => 'user', 'content' => $userMessage],
                    ],
                ]);

                if ($response->successful()) {
                    $json = $response->json();
                    return new UsageResult(
                        text: $json['content'][0]['text'] ?? '',
                        provider: $this->getProviderName(),
                        model: $json['model'] ?? $this->model,
                        inputTokens: (int) ($json['usage']['input_tokens'] ?? 0),
                        outputTokens: (int) ($json['usage']['output_tokens'] ?? 0),
                    );
                }

                if ($response->status() === 529 && $attempt < $this->retryAttempts) {
                    sleep(pow(2, $attempt));
                    continue;
                }

                throw new \RuntimeException("Claude API: HTTP {$response->status()} - {$response->body()}");
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                $lastException = $e;
                if ($attempt < $this->retryAttempts) {
                    sleep(pow(2, $attempt));
                    continue;
                }
            }
        }

        throw $lastException ?? new \RuntimeException('Claude API failed after retries');
    }

    /**
     * Vision: send a single image plus a text prompt to Claude and
     * return the model's text response. Used to OCR / interpret PNG /
     * JPG attachments on abuse reports so visible IPs and forwarded
     * complaint text are visible to the rest of the triage pipeline.
     */
    public function completeWithImage(
        string $systemPrompt,
        string $userPrompt,
        string $imageBytes,
        string $mime,
        ?int $maxTokens = null,
    ): ?string {
        $maxTokens = $maxTokens ?? $this->maxTokens;

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout(120)->post("{$this->baseUrl}/v1/messages", [
                'model' => $this->model,
                'max_tokens' => $maxTokens,
                'system' => $systemPrompt,
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'image',
                            'source' => [
                                'type' => 'base64',
                                'media_type' => $mime,
                                'data' => base64_encode($imageBytes),
                            ],
                        ],
                        ['type' => 'text', 'text' => $userPrompt],
                    ],
                ]],
            ]);

            if (! $response->successful()) {
                Log::warning('Claude vision: HTTP error', [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 500),
                ]);
                return null;
            }

            $json = $response->json();
            return $json['content'][0]['text'] ?? null;
        } catch (\Throwable $e) {
            Log::warning('Claude vision: exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Document mode: send a PDF (or other supported document) plus a
     * text prompt to Claude. Used as a fallback for scanned / image-
     * based PDFs that smalot/pdfparser can't read — Claude's native
     * document support runs vision over each page transparently.
     *
     * Anthropic limits: 32MB / ~100 pages per document. Exceeding
     * those returns a 4xx; the caller is expected to size-check
     * before calling.
     */
    public function completeWithDocument(
        string $systemPrompt,
        string $userPrompt,
        string $documentBytes,
        string $mime = 'application/pdf',
        ?int $maxTokens = null,
    ): ?string {
        $maxTokens = $maxTokens ?? $this->maxTokens;

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout(180)->post("{$this->baseUrl}/v1/messages", [
                'model' => $this->model,
                'max_tokens' => $maxTokens,
                'system' => $systemPrompt,
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'document',
                            'source' => [
                                'type' => 'base64',
                                'media_type' => $mime,
                                'data' => base64_encode($documentBytes),
                            ],
                        ],
                        ['type' => 'text', 'text' => $userPrompt],
                    ],
                ]],
            ]);

            if (! $response->successful()) {
                Log::warning('Claude document: HTTP error', [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 500),
                ]);
                return null;
            }

            $json = $response->json();
            return $json['content'][0]['text'] ?? null;
        } catch (\Throwable $e) {
            Log::warning('Claude document: exception', ['error' => $e->getMessage()]);
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
            Log::warning('Claude: failed to parse JSON', ['error' => json_last_error_msg()]);
            return null;
        }

        return $decoded;
    }
}
