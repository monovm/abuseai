<?php

namespace App\Services\AI;

interface AiProviderInterface
{
    public function complete(string $systemPrompt, string $userMessage, ?int $maxTokens = null): ?string;

    public function completeJson(string $systemPrompt, string $userMessage, ?int $maxTokens = null): ?array;

    public function completeWithUsage(string $systemPrompt, string $userMessage, ?int $maxTokens = null, bool $jsonMode = false): UsageResult;

    public function getProviderName(): string;

    public function getModelName(): string;
}
